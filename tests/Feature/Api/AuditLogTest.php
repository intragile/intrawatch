<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $stateful = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login'];

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    private function member(WorkspaceRole $role): User
    {
        return User::factory()->memberOf($this->workspace, $role)->create();
    }

    private function url(string $suffix): string
    {
        return "/api/v1/workspaces/{$this->workspace->slug}{$suffix}";
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return list<mixed>
     */
    private function rows(TestResponse $response): array
    {
        $data = $response->json('data');
        $this->assertIsArray($data);

        return array_values($data);
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function mint(User $user, array $abilities): string
    {
        $token = $this->actingAs($user, 'sanctum')
            ->postJson($this->url('/tokens'), ['name' => 'ci', 'abilities' => $abilities])
            ->assertCreated()->json('token');
        $this->assertIsString($token);

        return $token;
    }

    public function test_role_change_is_audited_with_diff(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $viewer = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url("/members/{$viewer->id}"), ['role' => 'editor'])
            ->assertOk();

        $event = AuditEvent::where('action', 'member.role_changed')->sole();
        $this->assertSame(['role' => ['viewer', 'editor']], $event->changes);
        $this->assertSame('user', $event->actor_type);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertSame('user', $event->subject_type);
        $this->assertSame((string) $viewer->id, $event->subject_id);
    }

    public function test_member_add_and_remove_are_audited(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $other = User::factory()->create();

        Mail::fake();
        // Adding a member is an invitation (202) that the invitee accepts; each step is audited.
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url('/members'), ['email' => $other->email, 'role' => 'viewer'])->assertStatus(202);
        $invited = AuditEvent::where('action', 'member.invited')->sole();
        $this->assertSame($owner->id, $invited->actor_user_id);
        // MySQL stores JSON keys ordered by length, so the keys are sorted before the strict comparison.
        $this->assertSame(['email' => [null, $other->email], 'role' => [null, 'viewer']], $this->sortedKeys($invited->changes));

        $token = Str::random(64);
        WorkspaceInvitation::query()->sole()->update(['token_hash' => WorkspaceInvitation::hashToken($token)]);
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->deleteJson($this->url("/members/{$other->id}"))->assertNoContent();

        $added = AuditEvent::where('action', 'member.added')->sole();
        $this->assertSame(['role' => [null, 'viewer']], $added->changes);
        $this->assertSame($other->id, $added->actor_user_id);
        $this->assertSame($this->workspace->id, $added->workspace_id);
        $removed = AuditEvent::where('action', 'member.removed')->sole();
        $this->assertSame(['role' => ['viewer', null]], $removed->changes);
    }

    public function test_workspace_lifecycle_is_audited(): void
    {
        config(['intrawatch.workspaces.creators' => 'anyone']);
        $user = User::factory()->create();

        $slug = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/workspaces', ['name' => 'Acme'])->assertCreated()->json('data.id');
        $this->assertNotNull($slug);
        $created = AuditEvent::where('action', 'workspace.created')->sole();
        $this->assertSame(['name' => [null, 'Acme']], $created->changes);
        $this->assertSame($user->id, $created->actor_id);
        $this->assertNotNull($created->workspace_id);

        $acme = Workspace::findOrFail($created->workspace_id);
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/workspaces/{$acme->slug}", ['name' => 'Acme 2'])->assertOk();
        $updated = AuditEvent::where('action', 'workspace.updated')->sole();
        $this->assertSame(['name' => ['Acme', 'Acme 2']], $updated->changes);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/workspaces/{$acme->slug}")->assertNoContent();
        $deleted = AuditEvent::where('action', 'workspace.deleted')->sole();
        $this->assertSame($acme->id, $deleted->workspace_id);
        $this->assertSame(3, AuditEvent::where('workspace_id', $acme->id)->count());
    }

    public function test_noop_updates_record_nothing(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $viewer = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url("/members/{$viewer->id}"), ['role' => 'viewer'])->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url(''), ['name' => $this->workspace->name])->assertOk();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_workspace_created_by_command_is_audited_once_as_system(): void
    {
        $this->assertSame(0, Artisan::call('intrawatch:user:create', [
            'email' => 'ada@example.com', '--name' => 'Ada', '--password' => 'correct-horse-battery', '--owner-of' => 'Ada Co',
        ]));

        $event = AuditEvent::where('action', 'workspace.created')->sole();
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->actor_user_id);
    }

    public function test_command_joining_existing_workspace_is_audited_as_member_added(): void
    {
        $this->assertSame(0, Artisan::call('intrawatch:user:create', [
            'email' => 'ada@example.com', '--password' => 'correct-horse-battery', '--owner-of' => $this->workspace->slug,
        ]));

        $event = AuditEvent::where('action', 'member.added')->sole();
        $this->assertSame('system', $event->actor_type);
        $this->assertSame(['role' => [null, 'owner']], $event->changes);
        $this->assertSame($this->workspace->id, $event->workspace_id);
    }

    public function test_token_actions_record_token_actor(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $plain = $this->mint($admin, ['workspace:manage']);

        $created = AuditEvent::where('action', 'token.created')->sole();
        $this->assertSame('user', $created->actor_type);
        $this->assertSame('ci', $created->changes['name'][1] ?? null);

        $this->asToken($plain)->patchJson($this->url(''), ['name' => 'Renamed'])->assertOk();

        $event = AuditEvent::where('action', 'workspace.updated')->sole();
        $this->assertSame('token', $event->actor_type);
        $this->assertSame((int) $created->subject_id, $event->actor_id);
        $this->assertSame($admin->id, $event->actor_user_id, 'Token actions keep their human author.');

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->withoutToken()
            ->getJson($this->url("/audit?actor_user_id={$admin->id}&actor_type=token"))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_user_id', $admin->id)
            ->assertJsonPath('data.0.actor_type', 'token');
    }

    public function test_token_revocation_is_audited(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $this->mint($admin, ['workspace:read']);
        $id = AuditEvent::where('action', 'token.created')->sole()->subject_id;

        $this->actingAs($admin, 'sanctum')->deleteJson($this->url("/tokens/{$id}"))->assertNoContent();

        $revoked = AuditEvent::where('action', 'token.revoked')->sole();
        $this->assertSame($id, $revoked->subject_id);
        $this->assertSame($admin->id, $revoked->actor_id);
    }

    public function test_login_and_failed_login_are_audited_without_workspace(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => 'Someone@Example.com', 'password' => 'hunter2-wrong'], $this->stateful)
            ->assertStatus(422);
        $failed = AuditEvent::where('action', 'auth.login_failed')->sole();
        $this->assertSame(['email' => [null, 'someone@example.com']], $failed->changes);
        $this->assertNull($failed->actor_user_id);
        $this->assertNull($failed->workspace_id);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($failed->toArray()));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $this->stateful)
            ->assertSuccessful();
        $login = AuditEvent::where('action', 'auth.login')->sole();
        $this->assertSame('user', $login->actor_type);
        $this->assertSame($user->id, $login->actor_id);
        $this->assertSame($user->id, $login->actor_user_id);
        $this->assertNull($login->workspace_id);
    }

    public function test_failed_login_keeps_only_a_plausible_email(): void
    {
        $long = str_repeat('a', 250).'@example.com';

        foreach (['not an email', $long, "x@example.com\nInjected: header"] as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => $attempt, 'password' => 'whatever-wrong'], $this->stateful);
        }

        $stored = AuditEvent::where('action', 'auth.login_failed')->orderBy('id')->get()
            ->map(fn (AuditEvent $event): mixed => $event->changes)->all();
        $this->assertSame(array_fill(0, 3, ['email' => [null, null]]), $stored);
    }

    public function test_two_factor_events_are_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'password'], $this->stateful)->assertCreated();

        $this->postJson('/api/v1/auth/user/two-factor-authentication', [], $this->stateful)->assertSuccessful();
        $this->deleteJson('/api/v1/auth/user/two-factor-authentication', [], $this->stateful)->assertSuccessful();

        $enabled = AuditEvent::where('action', 'auth.two_factor_enabled')->sole();
        $this->assertSame($user->id, $enabled->actor_id);
        $this->assertNull($enabled->workspace_id);
        $this->assertSame(1, AuditEvent::where('action', 'auth.two_factor_disabled')->count());
    }

    public function test_audit_never_contains_secrets(): void
    {
        $user = User::factory()->memberOf($this->workspace, WorkspaceRole::Owner)->create();
        $plain = $this->mint($user, ['workspace:read']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'password'], $this->stateful)->assertCreated();
        $this->postJson('/api/v1/auth/user/two-factor-authentication', [], $this->stateful)->assertSuccessful();
        $encrypted = $user->refresh()->two_factor_secret;
        $this->assertIsString($encrypted);
        $secret = decrypt($encrypted);
        $this->assertIsString($secret);

        // A careless caller handing whole attribute sets to the trail must not leak credentials either.
        $user->refresh();
        $token = $user->tokens()->firstOrFail();
        Audit::record('user.snapshot', $user, ['profile' => [null, ['user' => $user->getAttributes(), 'token' => $token->getAttributes()]]]);
        Audit::record('user.snapshot', $user, ['attributes' => [$user->getAttributes(), null]]);

        $dump = (string) json_encode(AuditEvent::all()->toArray());
        $this->assertGreaterThan(0, AuditEvent::count());
        $this->assertStringContainsString($user->email, $dump, 'Harmless nested fields are kept.');
        $this->assertStringNotContainsString($plain, $dump);
        $this->assertStringNotContainsString(explode('|', $plain)[1] ?? $plain, $dump);
        $this->assertStringNotContainsString($secret, $dump);
        $this->assertStringNotContainsString($encrypted, $dump);
        $passwordHash = $user->getAttributes()['password'];
        $tokenHash = $token->getAttributes()['token'];
        $this->assertIsString($passwordHash);
        $this->assertIsString($tokenHash);
        $this->assertStringNotContainsString($passwordHash, $dump);
        $this->assertStringNotContainsString($tokenHash, $dump);
        $this->assertStringNotContainsString('two_factor', str_replace('auth.two_factor_', '', $dump));
        $this->assertStringNotContainsString('remember_token', $dump);
    }

    public function test_record_strips_sensitive_fields(): void
    {
        $event = Audit::record('thing.changed', null, [
            'name' => ['a', 'b'],
            'password' => ['x', 'y'],
            'api_token' => [null, 'z'],
            'TOTP_Secret' => [null, 's'],
            'two_factor_secret' => [null, 's'],
            'two_factor_recovery_codes' => [null, 's'],
            'remember_token' => ['a', 'b'],
            'settings' => [['theme' => 'dark', 'smtp' => ['host' => 'mx', 'Password' => 'p']], ['list' => [['api_token' => 't', 'id' => 1]]]],
        ]);

        // Equality, not identity: MySQL's JSON type does not preserve key order.
        $this->assertEquals([
            'name' => ['a', 'b'],
            'settings' => [['theme' => 'dark', 'smtp' => ['host' => 'mx']], ['list' => [['id' => 1]]]],
        ], $event->fresh()?->changes);
    }

    public function test_events_cannot_be_updated_or_deleted(): void
    {
        $event = Audit::record('thing.changed');

        try {
            $event->update(['action' => 'other']);
            $this->fail('Update must be refused.');
        } catch (LogicException) {
        }
        try {
            $event->delete();
            $this->fail('Delete must be refused.');
        } catch (LogicException) {
        }

        $this->assertSame('thing.changed', $event->fresh()?->action);
    }

    public function test_no_mutating_audit_routes_exist(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($owner, 'sanctum')
                ->json($method, $this->url('/audit'))->assertStatus(405);
        }
    }

    public function test_editor_cannot_read_audit(): void
    {
        $this->actingAs($this->member(WorkspaceRole::Editor), 'sanctum')
            ->getJson($this->url('/audit'))->assertForbidden();
    }

    public function test_admin_reads_newest_first_with_filters_and_pagination(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $admin = $this->member(WorkspaceRole::Admin);
        $viewer = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($owner, 'sanctum')->patchJson($this->url("/members/{$viewer->id}"), ['role' => 'editor'])->assertOk();
        $this->actingAs($admin, 'sanctum')->patchJson($this->url("/members/{$viewer->id}"), ['role' => 'viewer'])->assertOk();
        $this->actingAs($admin, 'sanctum')->patchJson($this->url(''), ['name' => 'New'])->assertOk();

        $all = $this->actingAs($admin, 'sanctum')->getJson($this->url('/audit'))->assertOk();
        $this->assertSame(
            ['workspace.updated', 'member.role_changed', 'member.role_changed'],
            array_column($this->rows($all), 'action'),
        );
        $all->assertJsonStructure(['data' => [['id', 'action', 'actor_type', 'actor_id', 'actor_user_id', 'subject_type', 'subject_id', 'changes', 'ip', 'created_at']], 'meta' => ['next_cursor', 'limit']]);

        $byAction = $this->actingAs($admin, 'sanctum')->getJson($this->url('/audit?action=member.role_changed'))->assertOk();
        $this->assertCount(2, $this->rows($byAction));

        $byActor = $this->actingAs($admin, 'sanctum')
            ->getJson($this->url("/audit?actor_id={$owner->id}&actor_type=user"))->assertOk();
        $this->assertCount(1, $this->rows($byActor));

        $page = $this->actingAs($admin, 'sanctum')->getJson($this->url('/audit?limit=2'))->assertOk();
        $this->assertCount(2, $this->rows($page));
        $cursor = $page->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $next = $this->actingAs($admin, 'sanctum')
            ->getJson($this->url('/audit?limit=2&cursor='.$cursor))->assertOk();
        $this->assertCount(1, $this->rows($next));
        $this->assertNull($next->json('meta.next_cursor'));
    }

    public function test_audit_is_scoped_to_the_workspace(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $other = Workspace::factory()->create();
        $other->members()->attach($owner, ['role' => 'owner']);
        Audit::record('workspace.updated', $other);

        $this->actingAs($owner, 'sanctum')->getJson($this->url('/audit'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_token_needs_audit_read_ability(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $withAbility = $this->mint($admin, ['audit:read']);
        $without = $this->mint($admin, ['workspace:read']);

        $this->asToken($withAbility)->getJson($this->url('/audit'))->assertOk();
        $this->asToken($without)->getJson($this->url('/audit'))->assertForbidden();
    }

    public function test_audit_read_token_never_sees_sensitive_channel_config(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $id = $this->actingAs($admin, 'sanctum')->postJson($this->url('/notification-channels'), [
            'name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/T0/OLDSECRETPATH?token=OLDQS'],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($id);
        $this->actingAs($admin, 'sanctum')->patchJson($this->url("/notification-channels/{$id}"), [
            'config' => ['url' => 'https://hooks.example.com/T0/NEWSECRETPATH?token=NEWQS'],
        ])->assertOk();
        $token = $this->mint($admin, ['audit:read']);

        $body = (string) $this->asToken($token)->getJson($this->url('/audit'))->assertOk()->getContent();

        foreach (['OLDSECRETPATH', 'OLDQS', 'NEWSECRETPATH', 'NEWQS'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringContainsString('https:\/\/hooks.example.com\/***', $body);
    }

    public function test_editor_cannot_mint_audit_read_token(): void
    {
        $editor = $this->member(WorkspaceRole::Editor);

        $this->actingAs($editor, 'sanctum')
            ->postJson($this->url('/tokens'), ['name' => 'x', 'abilities' => ['audit:read']])
            ->assertStatus(422);
    }

    private function failAuditInserts(): void
    {
        AuditEvent::creating(function (): never {
            throw new LogicException('Audit insert failed.');
        });
    }

    public function test_failed_audit_insert_rolls_back_role_change(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->failAuditInserts();

        $this->actingAs($owner, 'sanctum')
            ->patchJson($this->url("/members/{$viewer->id}"), ['role' => 'editor'])
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(WorkspaceRole::Viewer, $this->workspace->memberships()->where('user_id', $viewer->id)->sole()->role);
    }

    public function test_failed_audit_insert_leaves_no_token(): void
    {
        $admin = $this->member(WorkspaceRole::Admin);
        $this->failAuditInserts();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->url('/tokens'), ['name' => 'ci', 'abilities' => ['workspace:read']])
            ->assertStatus(500);

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_failed_audit_insert_keeps_workspace(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        $this->failAuditInserts();

        $this->actingAs($owner, 'sanctum')->deleteJson($this->url(''))->assertStatus(500);
        $this->actingAs($owner, 'sanctum')->patchJson($this->url(''), ['name' => 'Changed'])->assertStatus(500);

        $fresh = Workspace::findOrFail($this->workspace->id);
        $this->assertSame($this->workspace->name, $fresh->name);
    }

    /**
     * The audit diff with its keys sorted: MySQL stores JSON object keys ordered by length, not as written.
     *
     * @return array<array-key, mixed>
     */
    private function sortedKeys(mixed $changes): array
    {
        $this->assertIsArray($changes);
        ksort($changes);

        return $changes;
    }
}
