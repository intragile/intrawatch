<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ImportStatus;
use App\Enums\WorkspaceRole;
use App\Http\Requests\StoreImportRequest;
use App\Jobs\RunKumaImport;
use App\Models\AuditEvent;
use App\Models\Import;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PDO;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\Concerns\AssertsWorkspaceIsolation;
use Tests\Feature\Import\Kuma\ImporterTest;
use Tests\TestCase;

final class ImportApiTest extends TestCase
{
    use AssertsWorkspaceIsolation;
    use RefreshDatabase;

    private Workspace $ws;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->ws = Workspace::factory()->create();
        $this->admin = User::factory()->memberOf($this->ws, WorkspaceRole::Admin)->create();
    }

    private function url(string $path = '', ?Workspace $workspace = null): string
    {
        return '/api/v1/workspaces/'.($workspace ?? $this->ws)->slug.'/imports'.$path;
    }

    private function kumaUpload(string $fixture = 'kuma2.db', ?string $path = null): UploadedFile
    {
        $contents = (string) file_get_contents($path ?? ImporterTest::fixture($fixture));

        return UploadedFile::fake()->createWithContent($fixture, $contents);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function upload(array $extra = [], ?UploadedFile $file = null): string
    {
        $id = $this->actingAs($this->admin)
            ->post($this->url(), ['file' => $file ?? $this->kumaUpload()] + $extra, ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * Runs the most recently queued import job inline, as the long-queue worker would.
     */
    private function runQueuedJob(int $index): void
    {
        $jobs = Queue::pushed(RunKumaImport::class)->values();
        $this->assertArrayHasKey($index, $jobs->all());
        $job = $jobs[$index];
        $this->assertInstanceOf(RunKumaImport::class, $job);
        app()->call([$job, 'handle']);
    }

    public function test_upload_analyses_then_runs(): void
    {
        $id = $this->upload(['history' => '0', 'paused' => '1']);

        $import = Import::findOrFail($id);
        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertSame('imports/'.$id, $import->file_path);
        Storage::disk('local')->assertExists('imports/'.$id);
        Queue::assertPushed(RunKumaImport::class, fn (RunKumaImport $job): bool => $job->importId === $id && $job->dryRun && $job->connection === 'long');

        $this->runQueuedJob(0);

        $this->actingAs($this->admin)->getJson($this->url("/{$id}"))->assertOk()
            ->assertJsonPath('data.status', 'analyzed')
            ->assertJsonPath('data.source', 'uptime_kuma')
            ->assertJsonPath('data.file_name', 'kuma2.db')
            ->assertJsonPath('data.options', ['history' => false, 'paused' => true])
            ->assertJsonPath('data.report.dry_run', true)
            ->assertJsonPath('data.report.counts.monitor', ['would_create' => 9, 'skipped' => 1])
            ->assertJsonPath('data.error', null);
        $this->assertSame(0, Monitor::count());
        Storage::disk('local')->assertExists('imports/'.$id);

        $this->actingAs($this->admin)->postJson($this->url("/{$id}/run"))->assertStatus(202)
            ->assertJsonPath('data.status', 'running');
        Queue::assertPushed(RunKumaImport::class, fn (RunKumaImport $job): bool => $job->importId === $id && ! $job->dryRun);

        $this->runQueuedJob(1);

        $this->actingAs($this->admin)->getJson($this->url("/{$id}"))->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.report.dry_run', false)
            ->assertJsonPath('data.report.counts.monitor', ['created' => 9, 'skipped' => 1]);
        $this->assertTrue(Monitor::where('workspace_id', $this->ws->id)->where('name', 'Example HTTP')->where('active', false)->exists());
        Storage::disk('local')->assertMissing('imports/'.$id);
        $this->assertNull(Import::findOrFail($id)->file_path);

        $audit = AuditEvent::where('action', 'import.completed')->sole();
        $this->assertSame($this->ws->id, $audit->workspace_id);
        $this->assertSame($this->admin->id, $audit->actor_user_id);

        $this->actingAs($this->admin)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.report.entries.0.config');
    }

    public function test_upload_rejects_non_kuma_file(): void
    {
        $this->actingAs($this->admin)
            ->post($this->url(), ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'just some text')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Import::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_oversized_upload_is_422(): void
    {
        config(['intrawatch.import.max_upload_mb' => 1]);

        $this->actingAs($this->admin)
            ->post($this->url(), ['file' => UploadedFile::fake()->create('kuma.db', 2048)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->actingAs($this->admin)->postJson($this->url(), [])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, Import::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * Makes the request see the given PHP upload limit (upload_max_filesize/post_max_size cannot change at run
     * time).
     */
    private function phpUploadLimit(int $bytes): void
    {
        $this->app->bind(StoreImportRequest::class, fn () => new class($bytes) extends StoreImportRequest
        {
            public function __construct(private readonly int $phpLimit)
            {
                parent::__construct();
            }

            protected function phpMaxBytes(): int
            {
                return $this->phpLimit;
            }
        });
    }

    public function test_upload_limit_is_clamped_to_the_php_limit_and_the_message_says_the_real_limit(): void
    {
        config(['intrawatch.import.max_upload_mb' => 256]);
        $this->phpUploadLimit(1024 * 1024);

        $response = $this->actingAs($this->admin)
            ->post($this->url(), ['file' => UploadedFile::fake()->create('kuma.db', 2048)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $message = (string) json_encode($response->json());
        $this->assertStringContainsString('upload limit of 1 MB', $message);
        $this->assertStringNotContainsString('256', $message);
        $this->assertSame(0, Import::count());
    }

    public function test_configured_limit_below_the_php_limit_is_the_one_reported(): void
    {
        config(['intrawatch.import.max_upload_mb' => 1]);
        $this->phpUploadLimit(512 * 1024 * 1024);

        $response = $this->actingAs($this->admin)
            ->post($this->url(), ['file' => UploadedFile::fake()->create('kuma.db', 2048)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertStringContainsString('upload limit of 1 MB', (string) json_encode($response->json()));
    }

    public function test_php_limit_under_one_megabyte_is_reported_in_kilobytes(): void
    {
        config(['intrawatch.import.max_upload_mb' => 256]);
        $this->phpUploadLimit(512 * 1024);

        $response = $this->actingAs($this->admin)
            ->post($this->url(), ['file' => UploadedFile::fake()->create('kuma.db', 600)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertStringContainsString('upload limit of 512 KB', (string) json_encode($response->json()));
    }

    public function test_upload_cut_off_by_php_says_the_real_limit(): void
    {
        config(['intrawatch.import.max_upload_mb' => 256]);
        $this->phpUploadLimit(2 * 1024 * 1024);
        $path = (string) tempnam(sys_get_temp_dir(), 'iw-upload-');
        file_put_contents($path, '');
        $file = new UploadedFile($path, 'kuma.db', null, UPLOAD_ERR_INI_SIZE, true);

        $response = $this->actingAs($this->admin)
            ->post($this->url(), ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
        @unlink($path);

        $message = (string) json_encode($response->json());
        $this->assertStringContainsString('upload limit of 2 MB', $message);
        $this->assertStringNotContainsString('256', $message);
    }

    public function test_php_limit_defaults_to_the_ini_settings(): void
    {
        $request = new class extends StoreImportRequest
        {
            public function phpLimit(): int
            {
                return $this->phpMaxBytes();
            }
        };

        $this->assertSame((int) SymfonyUploadedFile::getMaxFilesize(), $request->phpLimit());
    }

    public function test_second_import_while_running_is_409(): void
    {
        $first = $this->upload();

        foreach ([ImportStatus::Pending, ImportStatus::Analyzing, ImportStatus::Analyzed, ImportStatus::Running] as $status) {
            Import::whereKey($first)->update(['status' => $status]);
            $this->actingAs($this->admin)
                ->post($this->url(), ['file' => $this->kumaUpload()], ['Accept' => 'application/json'])
                ->assertStatus(409)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'urn:problem:import-in-progress');
        }
        $this->assertSame(1, Import::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());

        Import::whereKey($first)->update(['status' => ImportStatus::Completed]);
        $this->upload();
        $this->assertSame(2, Import::count());

        // Another workspace is not blocked.
        $other = Workspace::factory()->create();
        $this->admin->workspaces()->attach($other, ['role' => WorkspaceRole::Owner->value]);
        $this->actingAs($this->admin)
            ->post($this->url('', $other), ['file' => $this->kumaUpload()], ['Accept' => 'application/json'])
            ->assertStatus(202);
    }

    public function test_run_only_from_analyzed(): void
    {
        $id = $this->upload();

        foreach ([ImportStatus::Pending, ImportStatus::Analyzing, ImportStatus::Running, ImportStatus::Completed, ImportStatus::Failed] as $status) {
            Import::whereKey($id)->update(['status' => $status]);
            $this->actingAs($this->admin)->postJson($this->url("/{$id}/run"))
                ->assertStatus(409)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'urn:problem:import-not-analyzed');
        }
        Queue::assertPushed(RunKumaImport::class, 1);

        Import::whereKey($id)->update(['status' => ImportStatus::Analyzed]);
        $this->actingAs($this->admin)->postJson($this->url("/{$id}/run"))->assertStatus(202);
        $this->actingAs($this->admin)->postJson($this->url("/{$id}/run"))->assertStatus(409);
        Queue::assertPushed(RunKumaImport::class, 2);
    }

    public function test_delete_removes_the_file_except_while_busy(): void
    {
        $id = $this->upload();

        foreach ([ImportStatus::Analyzing, ImportStatus::Running] as $status) {
            Import::whereKey($id)->update(['status' => $status]);
            $this->actingAs($this->admin)->deleteJson($this->url("/{$id}"))
                ->assertStatus(409)
                ->assertJsonPath('type', 'urn:problem:import-in-progress');
        }

        Import::whereKey($id)->update(['status' => ImportStatus::Analyzed]);
        $this->actingAs($this->admin)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->assertNull(Import::find($id));
        Storage::disk('local')->assertMissing('imports/'.$id);
        $this->actingAs($this->admin)->getJson($this->url("/{$id}"))->assertNotFound();
    }

    public function test_delete_is_409_when_the_import_turns_busy_after_it_was_loaded(): void
    {
        $id = $this->upload();
        Import::whereKey($id)->update(['status' => ImportStatus::Analyzed]);

        // A worker claims the import between the route binding loading it and the delete.
        $claimed = false;
        Import::retrieved(function (Import $import) use ($id, &$claimed): void {
            if ($import->id === $id && ! $claimed) {
                $claimed = true;
                Import::query()->whereKey($id)->toBase()->update(['status' => ImportStatus::Running->value]);
            }
        });

        $this->actingAs($this->admin)->deleteJson($this->url("/{$id}"))
            ->assertStatus(409)
            ->assertJsonPath('type', 'urn:problem:import-in-progress');

        $this->assertTrue($claimed, 'the import was not loaded through Eloquent');
        $this->assertSame(ImportStatus::Running, Import::query()->findOrFail($id)->status);
        Storage::disk('local')->assertExists('imports/'.$id);
    }

    public function test_viewer_and_editor_get_403_token_needs_workspace_manage(): void
    {
        $id = $this->upload();

        foreach ([WorkspaceRole::Viewer, WorkspaceRole::Editor] as $role) {
            $member = User::factory()->memberOf($this->ws, $role)->create();
            $this->app['auth']->forgetGuards();
            $this->actingAs($member)->getJson($this->url())->assertForbidden();
            $this->actingAs($member)->getJson($this->url("/{$id}"))->assertForbidden();
            $this->actingAs($member)->post($this->url(), ['file' => $this->kumaUpload()], ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($member)->postJson($this->url("/{$id}/run"))->assertForbidden();
            $this->actingAs($member)->deleteJson($this->url("/{$id}"))->assertForbidden();
        }

        $owner = User::factory()->memberOf($this->ws, WorkspaceRole::Owner)->create();
        $readOnly = $this->mint($owner, ['monitors:read', 'monitors:write', 'workspace:read']);
        $this->withToken($readOnly)->getJson($this->url())->assertForbidden();
        $this->withToken($readOnly)->postJson($this->url("/{$id}/run"))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $manage = $this->mint($owner, ['workspace:manage']);
        $this->withToken($manage)->getJson($this->url())->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_cross_workspace_import_is_404(): void
    {
        [$actor, $own, $foreign] = $this->crossWorkspaceFixture();
        $foreignImport = Import::create([
            'workspace_id' => $foreign->id, 'source' => 'uptime_kuma', 'status' => ImportStatus::Analyzed,
            'options' => ['history' => true, 'paused' => false],
        ]);

        $paths = ['GET' => "/{$foreignImport->id}", 'DELETE' => "/{$foreignImport->id}", 'POST' => "/{$foreignImport->id}/run"];
        foreach ($paths as $method => $path) {
            $this->assertCrossWorkspaceNotFound($actor, $method, $this->url($path, $own));
            $this->assertCrossWorkspaceNotFound($actor, $method, $this->url($path, $foreign));
        }
        $this->assertNotNull(Import::find($foreignImport->id));
        $this->assertCrossWorkspaceNotFound($actor, 'GET', $this->url('/not-a-ulid', $own));
    }

    public function test_import_resource_contains_no_secret_values(): void
    {
        $secrets = ['S3cr3t-smtp-Pa55', 'S3cr3t-hook-token', 'S3cr3t-basic-pass', 'S3cr3t-proxy-pass'];
        $path = $this->fixtureCopyWithSecrets($secrets);
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });

        try {
            $id = $this->upload([], $this->kumaUpload('kuma2.db', $path));
            $this->runQueuedJob(0);
            $analysed = $this->actingAs($this->admin)->getJson($this->url("/{$id}"))->assertOk()->getContent();
            $this->actingAs($this->admin)->postJson($this->url("/{$id}/run"))->assertStatus(202);
            $this->runQueuedJob(1);
            $completed = $this->actingAs($this->admin)->getJson($this->url("/{$id}"))->assertOk()
                ->assertJsonPath('data.status', 'completed')->getContent();
            $listed = $this->actingAs($this->admin)->getJson($this->url())->assertOk()->getContent();
        } finally {
            @unlink($path);
        }

        $haystack = implode("\n", [(string) $analysed, (string) $completed, (string) $listed, json_encode($logged), json_encode(AuditEvent::all()->toArray())]);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
        $this->assertStringNotContainsString('file_path', (string) $completed);
    }

    /**
     * @param  list<string>  $secrets
     */
    private function fixtureCopyWithSecrets(array $secrets): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kuma-secrets-');
        copy(ImporterTest::fixture('kuma2.db'), $path);
        $pdo = new PDO('sqlite:'.$path);
        $smtp = json_encode(['name' => 'Ops e-mail', 'type' => 'smtp', 'smtpHost' => 'smtp.example.com', 'smtpPort' => 587, 'smtpFrom' => 'kuma@example.com', 'smtpTo' => 'ops@example.com', 'smtpUsername' => 'u', 'smtpPassword' => $secrets[0]]);
        $hook = json_encode(['name' => 'Webhook', 'type' => 'webhook', 'webhookURL' => 'https://hooks.example.com/kuma', 'webhookContentType' => 'json', 'webhookAdditionalHeaders' => json_encode(['X-Token' => $secrets[1]])]);
        $pdo->prepare('UPDATE notification SET config = ? WHERE id = 1')->execute([$smtp]);
        $pdo->prepare('UPDATE notification SET config = ? WHERE id = 2')->execute([$hook]);
        $pdo->prepare('UPDATE monitor SET basic_auth_pass = ? WHERE id = 2')->execute([$secrets[2]]);
        $pdo->prepare('UPDATE proxy SET password = ? WHERE id = 1')->execute([$secrets[3]]);
        $pdo = null;

        return $path;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function mint(User $user, array $abilities): string
    {
        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/workspaces/{$this->ws->slug}/tokens", ['name' => 'ci', 'abilities' => $abilities])
            ->assertCreated()
            ->json('token');
        $this->assertIsString($token);
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_uploads_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->admin)
                ->post($this->url(), ['file' => UploadedFile::fake()->createWithContent('x.txt', 'nope')], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }
        $this->actingAs($this->admin)
            ->post($this->url(), ['file' => UploadedFile::fake()->createWithContent('x.txt', 'nope')], ['Accept' => 'application/json'])
            ->assertStatus(429);
    }
}
