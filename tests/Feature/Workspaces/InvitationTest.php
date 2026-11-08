<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->workspace = Workspace::factory()->create(['name' => 'Acme']);
        $this->admin = User::factory()->memberOf($this->workspace, WorkspaceRole::Admin)->create(['name' => 'Ada Admin']);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->workspace->slug}{$suffix}";
    }

    /**
     * @return TestResponse<Response>
     */
    private function invite(string $email, string $role = 'editor', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson($this->url('/members'), ['email' => $email, 'role' => $role]);
    }

    /**
     * The plain token of the last invitation mailed to the address.
     */
    private function mailedToken(string $email): string
    {
        $token = null;
        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use ($email, &$token): bool {
            if (! $mail->hasTo($email)) {
                return false;
            }
            $this->assertMatchesRegularExpression('~/admin/invitations#token=[A-Za-z0-9]{64}$~', $mail->url);
            $token = substr($mail->url, -64);

            return true;
        });
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @return TestResponse<Response>
     */
    private function accept(string $token, User $as): TestResponse
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/v1/invitations/accept', ['token' => $token]);
    }

    public function test_the_invitation_link_carries_the_token_only_in_the_fragment(): void
    {
        config(['app.url' => 'https://watch.example.com/']);
        $this->invite('fragment@example.com')->assertStatus(202);
        $token = $this->mailedToken('fragment@example.com');

        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use ($token): bool {
            $parts = parse_url($mail->url);
            $this->assertIsArray($parts);
            $this->assertSame('/admin/invitations', $parts['path'] ?? null);
            $this->assertNull($parts['query'] ?? null);
            $this->assertSame('token='.$token, $parts['fragment'] ?? null);
            $this->assertStringNotContainsString($token, $parts['path'].'?'.($parts['query'] ?? ''));

            return true;
        });
    }

    public function test_every_address_gets_the_same_answer_and_an_email(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        User::factory()->create(['email' => 'mixed@example.com']);

        $answers = [
            'known@example.com' => $this->invite('known@example.com'),
            'nobody@example.com' => $this->invite('nobody@example.com'),
            'mixed@example.com' => $this->invite('  MiXeD@Example.COM '),
        ];

        foreach ($answers as $email => $response) {
            $response->assertStatus(202)
                ->assertExactJsonStructure(['data' => ['email', 'role', 'expires_at']])
                ->assertJsonPath('data.email', $email)
                ->assertJsonPath('data.role', 'editor');
            Mail::assertQueued(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail): bool => $mail->hasTo($email));
            $this->assertDatabaseHas('workspace_invitations', ['workspace_id' => $this->workspace->id, 'email' => $email, 'role' => 'editor', 'invited_by' => $this->admin->id]);
        }
        Mail::assertQueuedCount(3);
        // Nobody joined yet: membership is only granted by accepting.
        $this->assertSame(1, $this->workspace->memberships()->count());
    }

    public function test_the_email_says_nothing_about_the_account(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        $this->invite('known@example.com')->assertStatus(202);
        $this->invite('nobody@example.com')->assertStatus(202);

        $rendered = [];
        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use (&$rendered): bool {
            $html = $mail->render();
            $this->assertStringContainsString('Acme', $html);
            $this->assertStringContainsString('Ada Admin', $html);
            $this->assertStringContainsString('ask your administrator for an account', $html);
            $this->assertStringContainsString($mail->url, $html);
            $rendered[] = str_replace($mail->url, '', $html);

            return true;
        });
        $this->assertCount(2, $rendered);
        $this->assertSame($rendered[0], $rendered[1]);
    }

    public function test_an_existing_member_is_refused(): void
    {
        $member = User::factory()->memberOf($this->workspace, WorkspaceRole::Viewer)->create(['email' => 'member@example.com']);

        $this->invite('Member@Example.com')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->invite($this->admin->email)->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('workspace_invitations', 0);
        $this->assertSame(WorkspaceRole::Viewer, $member->roleIn($this->workspace));
    }

    public function test_resending_refreshes_the_single_pending_invitation(): void
    {
        $this->invite('cy@example.com', 'viewer')->assertStatus(202);
        $first = WorkspaceInvitation::query()->sole();
        $oldToken = $this->mailedToken('cy@example.com');

        $this->travel(1)->hours();
        Mail::fake();
        $this->invite('cy@example.com', 'editor')->assertStatus(202);

        $second = WorkspaceInvitation::query()->sole();
        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->token_hash, $second->token_hash);
        $this->assertTrue($second->expires_at->greaterThan($first->expires_at));
        $this->assertSame(WorkspaceRole::Editor, $second->role);
        $newToken = $this->mailedToken('cy@example.com');

        $cy = User::factory()->create(['email' => 'cy@example.com']);
        $this->accept($oldToken, $cy)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->accept($newToken, $cy)->assertOk();
        $this->assertSame(WorkspaceRole::Editor, $cy->roleIn($this->workspace));
    }

    public function test_only_the_token_hash_is_stored(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);
        $token = $this->mailedToken('cy@example.com');

        $row = (array) DB::table('workspace_invitations')->sole();
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertStringNotContainsString($token, (string) json_encode($row));
        $this->assertArrayNotHasKey('token_hash', WorkspaceInvitation::query()->sole()->toArray());
    }

    public function test_the_mail_goes_out_in_the_locale_the_inviter_sends(): void
    {
        Mail::fake();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/members'), ['email' => 'hu@example.com', 'role' => 'editor', 'locale' => 'hu'])
            ->assertAccepted();
        $this->invite('en@example.com')->assertAccepted();

        Mail::assertQueued(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail): bool => $mail->hasTo('hu@example.com') && $mail->locale === 'hu');
        Mail::assertQueued(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail): bool => $mail->hasTo('en@example.com') && $mail->locale === 'en');
    }

    public function test_an_unknown_locale_is_refused(): void
    {
        Mail::fake();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/members'), ['email' => 'x@example.com', 'role' => 'editor', 'locale' => 'de'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locale']);
        Mail::assertNothingQueued();
    }

    public function test_invitations_expire_after_seven_days(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);

        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), WorkspaceInvitation::query()->sole()->expires_at->getTimestamp(), 5);
    }

    public function test_only_managers_invite_and_only_owners_invite_owners(): void
    {
        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $owner = User::factory()->memberOf($this->workspace, WorkspaceRole::Owner)->create();

        $this->invite('cy@example.com', 'viewer', $editor)->assertForbidden();
        $this->invite('cy@example.com', 'owner')->assertForbidden();
        $this->invite('cy@example.com', 'god')->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->invite('not-an-address', 'viewer')->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingQueued();

        $this->invite('cy@example.com', 'owner', $owner)->assertStatus(202);
    }

    public function test_inviting_is_audited(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);

        $event = AuditEvent::where('action', 'member.invited')->sole();
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame(['email' => [null, 'cy@example.com'], 'role' => [null, 'editor']], $event->changes);
    }

    public function test_accepting_grants_the_invited_role(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);
        $token = $this->mailedToken('cy@example.com');
        $cy = User::factory()->create(['email' => 'cy@example.com']);

        $this->accept($token, $cy)
            ->assertOk()
            ->assertJsonPath('data.slug', $this->workspace->slug)
            ->assertJsonPath('data.role', 'editor')
            ->assertJsonPath('data.can.manageMonitors', true);

        $this->assertSame(WorkspaceRole::Editor, $cy->roleIn($this->workspace));
        $this->assertNotNull(WorkspaceInvitation::query()->sole()->accepted_at);
        $event = AuditEvent::where('action', 'member.added')->sole();
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertSame($cy->id, $event->actor_user_id);
        $this->assertSame((string) $cy->id, $event->subject_id);
    }

    public function test_unusable_invitations_all_fail_the_same_way(): void
    {
        $cy = User::factory()->create(['email' => 'cy@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);

        $expired = Str::random(64);
        WorkspaceInvitation::factory()->for($this->workspace)->withToken($expired)->create(['email' => 'cy@example.com', 'expires_at' => now()->subMinute()]);
        $wrongEmail = Str::random(64);
        WorkspaceInvitation::factory()->for($this->workspace)->withToken($wrongEmail)->create(['email' => 'someone@example.com']);
        $accepted = Str::random(64);
        $second = Workspace::factory()->create();
        $secondOwner = User::factory()->memberOf($second, WorkspaceRole::Owner)->create();
        WorkspaceInvitation::factory()->for($second)->withToken($accepted)->create(['email' => 'other@example.com', 'invited_by' => $secondOwner->id]);
        $this->accept($accepted, $other)->assertOk();

        $this->invite('dee@example.com')->assertStatus(202);
        $revoked = $this->mailedToken('dee@example.com');
        $dee = User::factory()->create(['email' => 'dee@example.com']);
        $invitation = WorkspaceInvitation::query()->where('email', 'dee@example.com')->sole();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("/invitations/{$invitation->id}"))->assertNoContent();

        $answers = [
            'expired' => $this->accept($expired, $cy),
            'other e-mail' => $this->accept($wrongEmail, $cy),
            'revoked' => $this->accept($revoked, $dee),
            'already accepted' => $this->accept($accepted, $other),
            'unknown' => $this->accept(Str::random(64), $cy),
            'malformed' => $this->accept('../'.Str::random(10), $cy),
        ];
        $bodies = [];
        foreach ($answers as $case => $response) {
            $response->assertUnprocessable()->assertJsonValidationErrors(['token' => 'The invitation is invalid or has expired.']);
            $bodies[$case] = $response->json('errors');
        }
        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)));
        $this->assertNull($cy->roleIn($this->workspace));
        $this->assertNull($dee->roleIn($this->workspace));
    }

    public function test_a_concurrent_accept_is_a_422_not_a_500(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);
        $token = $this->mailedToken('cy@example.com');
        $cy = User::factory()->create(['email' => 'cy@example.com']);

        // A parallel request inserts the same membership between the check and the insert.
        $done = false;
        Event::listen('eloquent.creating: '.Membership::class, function (Membership $membership) use (&$done): void {
            if ($done) {
                return;
            }
            $done = true;
            DB::table('memberships')->insert([
                'workspace_id' => $membership->workspace_id,
                'user_id' => $membership->user_id,
                'role' => 'viewer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->accept($token, $cy)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue($done);
        $this->assertNull(WorkspaceInvitation::query()->sole()->accepted_at);
    }

    public function test_accepting_twice_is_a_422(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);
        $token = $this->mailedToken('cy@example.com');
        $cy = User::factory()->create(['email' => 'cy@example.com']);

        $this->accept($token, $cy)->assertOk();
        $this->accept($token, $cy)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertSame(1, AuditEvent::where('action', 'member.added')->count());
    }

    public function test_tokens_and_guests_cannot_accept(): void
    {
        $this->invite('cy@example.com')->assertStatus(202);
        $token = $this->mailedToken('cy@example.com');
        $cy = User::factory()->memberOf(Workspace::factory()->create(), WorkspaceRole::Owner)->create(['email' => 'cy@example.com']);
        $plain = $cy->createToken('ci', ['workspace:read'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/invitations/accept', ['token' => $token])->assertUnauthorized();
        $this->assertNull($cy->roleIn($this->workspace));
    }

    public function test_managers_list_and_revoke_pending_invitations(): void
    {
        $viewer = User::factory()->memberOf($this->workspace, WorkspaceRole::Viewer)->create();
        $this->invite('cy@example.com')->assertStatus(202);
        $this->invite('dee@example.com', 'viewer')->assertStatus(202);
        WorkspaceInvitation::factory()->for($this->workspace)->create(['email' => 'old@example.com', 'expires_at' => now()->subDay()]);
        WorkspaceInvitation::factory()->for($this->workspace)->create(['email' => 'done@example.com', 'accepted_at' => now()]);
        WorkspaceInvitation::factory()->for(Workspace::factory())->create(['email' => 'elsewhere@example.com']);

        $list = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/invitations'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'email', 'role', 'invited_by', 'expires_at', 'expired', 'created_at']], 'meta' => ['next_cursor', 'limit']]);
        /** @var list<array{id: int, email: string, expired: bool, invited_by: array{id: int, name: string}|null}> $rows */
        $rows = $list->json('data');
        $byEmail = [];
        foreach ($rows as $row) {
            $byEmail[$row['email']] = $row;
        }
        ksort($byEmail);
        $this->assertSame(['cy@example.com', 'dee@example.com', 'old@example.com'], array_keys($byEmail));
        $this->assertTrue($byEmail['old@example.com']['expired']);
        $this->assertFalse($byEmail['cy@example.com']['expired']);
        $this->assertSame(['id' => $this->admin->id, 'name' => 'Ada Admin'], $byEmail['cy@example.com']['invited_by']);
        $this->assertStringNotContainsString('token', (string) $list->getContent());

        $this->actingAs($viewer, 'sanctum')->getJson($this->url('/invitations'))->assertForbidden();
        $id = $byEmail['cy@example.com']['id'];
        $this->actingAs($viewer, 'sanctum')->deleteJson($this->url("/invitations/{$id}"))->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("/invitations/{$id}"))->assertNoContent();
        $this->assertDatabaseMissing('workspace_invitations', ['id' => $id]);
        $event = AuditEvent::where('action', 'member.invitation_revoked')->sole();
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertSame(['email' => ['cy@example.com', null], 'role' => ['editor', null]], $event->changes);

        $foreign = WorkspaceInvitation::query()->where('email', 'elsewhere@example.com')->sole();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("/invitations/{$foreign->id}"))->assertNotFound();
    }

    public function test_only_owners_revoke_owner_invitations(): void
    {
        $owner = User::factory()->memberOf($this->workspace, WorkspaceRole::Owner)->create();
        $this->invite('boss@example.com', 'owner', $owner)->assertStatus(202);
        $id = WorkspaceInvitation::query()->sole()->id;

        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("/invitations/{$id}"))->assertForbidden();
        $this->actingAs($owner, 'sanctum')->deleteJson($this->url("/invitations/{$id}"))->assertNoContent();
    }

    public function test_an_admin_cannot_replace_a_pending_owner_invitation(): void
    {
        // Re-inviting overwrites the pending row; that must not let an admin cancel or downgrade what an owner sent.
        $owner = User::factory()->memberOf($this->workspace, WorkspaceRole::Owner)->create();
        $this->invite('boss@example.com', 'owner', $owner)->assertStatus(202);
        $hash = WorkspaceInvitation::query()->sole()->token_hash;

        $this->invite('boss@example.com', 'viewer')->assertForbidden();

        $invitation = WorkspaceInvitation::query()->sole();
        $this->assertSame(WorkspaceRole::Owner, $invitation->role);
        $this->assertSame($hash, $invitation->token_hash);
        $this->invite('boss@example.com', 'viewer', $owner)->assertStatus(202);
    }

    public function test_an_invitation_dies_with_its_inviters_right_to_grant_it(): void
    {
        $owner = User::factory()->memberOf($this->workspace, WorkspaceRole::Owner)->create();
        $other = User::factory()->memberOf($this->workspace, WorkspaceRole::Owner)->create();
        $this->invite('cy@example.com')->assertStatus(202);
        $this->invite('boss@example.com', 'owner', $other)->assertStatus(202);
        $cyToken = $this->mailedToken('cy@example.com');
        $bossToken = $this->mailedToken('boss@example.com');

        // The admin who invited cy is removed; the owner who invited boss becomes an admin.
        $this->actingAs($owner, 'sanctum')->deleteJson($this->url("/members/{$this->admin->id}"))->assertNoContent();
        $this->actingAs($owner, 'sanctum')->patchJson($this->url("/members/{$other->id}"), ['role' => 'admin'])->assertOk();

        $cy = User::factory()->create(['email' => 'cy@example.com']);
        $boss = User::factory()->create(['email' => 'boss@example.com']);
        $this->accept($cyToken, $cy)->assertUnprocessable()->assertJsonPath('errors.token.0', 'The invitation is invalid or has expired.');
        $this->accept($bossToken, $boss)->assertUnprocessable()->assertJsonPath('errors.token.0', 'The invitation is invalid or has expired.');
        $this->assertNull($cy->roleIn($this->workspace));
        $this->assertNull($boss->roleIn($this->workspace));
    }

    public function test_prune_deletes_invitations_a_month_past_their_expiry(): void
    {
        $this->invite('old@example.com')->assertStatus(202);
        $this->invite('recent@example.com')->assertStatus(202);
        $this->invite('fresh@example.com')->assertStatus(202);
        WorkspaceInvitation::where('email', 'old@example.com')->update(['expires_at' => now()->subDays(31)]);
        WorkspaceInvitation::where('email', 'recent@example.com')->update(['expires_at' => now()->subDays(29)]);

        $this->assertSame(0, Artisan::call('intrawatch:prune'));
        $this->assertStringContainsString('workspace_invitations: 1 deleted', Artisan::output());

        $this->assertEqualsCanonicalizing(['recent@example.com', 'fresh@example.com'], WorkspaceInvitation::pluck('email')->all());
    }

    public function test_sending_is_throttled_per_workspace(): void
    {
        config(['intrawatch.workspaces.invitations.per_hour' => 2]);
        $otherAdmin = User::factory()->memberOf($this->workspace, WorkspaceRole::Admin)->create();
        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();

        // Members who may not invite never spend the workspace's budget.
        for ($i = 0; $i < 3; $i++) {
            $this->invite("x{$i}@example.com", 'viewer', $editor)->assertForbidden();
        }

        $this->invite('a@example.com')->assertStatus(202);
        $this->invite('b@example.com', 'editor', $otherAdmin)->assertStatus(202);
        $this->invite('c@example.com')->assertStatus(429);
        $this->invite('d@example.com', 'editor', $otherAdmin)->assertStatus(429);
        Mail::assertQueuedCount(2);

        // Another workspace has its own budget.
        $second = Workspace::factory()->create();
        $secondAdmin = User::factory()->memberOf($second, WorkspaceRole::Admin)->create();
        $this->actingAs($secondAdmin, 'sanctum')
            ->postJson("/api/v1/workspaces/{$second->slug}/members", ['email' => 'c@example.com', 'role' => 'viewer'])
            ->assertStatus(202);

        $this->travel(61)->minutes();
        $this->invite('c@example.com')->assertStatus(202);
    }
}
