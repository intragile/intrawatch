<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Actions\Notifications\CreateNotificationChannel;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\ImportReport;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\KumaSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Models\AuditEvent;
use App\Models\ImportMapping;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * @phpstan-import-type ReportShape from ImportReport
 * @phpstan-import-type EntryShape from ImportReport
 */
final class ImportNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<KumaSource> */
    private array $sources = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->sources as $source) {
            $source->close();
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>|null  $notifications  Replaces the fixture's notifications with a JSON backup.
     * @return ReportShape
     */
    private function import(string $fixture, Workspace $workspace, bool $dryRun = false, ?array $notifications = null): array
    {
        $path = ImporterTest::fixture($fixture);
        if ($notifications !== null) {
            $path = $this->backupWith($notifications);
        }
        $source = $this->sources[] = SourceFactory::open($path);

        return app(KumaImporter::class)->run($source, $workspace, new ImportOptions(dryRun: $dryRun), null)->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $configs  Kuma notification configs; ids start at 1.
     */
    private function backupWith(array $configs): string
    {
        $list = [];
        foreach ($configs as $i => $config) {
            $list[] = ['id' => $i + 1, 'name' => 'N'.($i + 1), 'active' => true, 'isDefault' => false, 'config' => json_encode($config)];
        }
        $path = tempnam(sys_get_temp_dir(), 'iw-kuma-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, json_encode(['monitorList' => [], 'notificationList' => $list]));

        return $path;
    }

    /**
     * @param  ReportShape  $report
     * @return list<EntryShape>
     */
    private function entries(array $report): array
    {
        return array_values(array_filter($report['entries'], fn (array $e): bool => $e['entity'] === 'notification'));
    }

    /**
     * @param  ReportShape  $report
     * @return list<string>
     */
    private function outcomes(array $report): array
    {
        return array_map(fn (array $e): string => $e['outcome'], $this->entries($report));
    }

    public function test_import_creates_channels_and_mappings(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import('kuma2.db', $ws);

        $this->assertSame(['created' => 2], $report['counts']['notification']);
        $email = NotificationChannel::where('workspace_id', $ws->id)->where('type', 'email')->sole();
        $this->assertTrue($email->is_default);
        $this->assertTrue($email->active);
        $this->assertSame('p', $email->secrets['smtp_password'] ?? null);
        $webhook = NotificationChannel::where('workspace_id', $ws->id)->where('type', 'webhook')->sole();
        $this->assertSame(['X-Token' => 'abc'], $webhook->secrets['secret_headers'] ?? null);
        $this->assertSame($email->id, ImportMapping::where('entity', 'notification')->where('source_id', 1)->sole()->target_id);
        $this->assertSame($webhook->id, ImportMapping::where('entity', 'notification')->where('source_id', 2)->sole()->target_id);
        $this->assertSame(2, AuditEvent::where('action', 'notification_channel.created')->count());
    }

    public function test_dry_run_writes_nothing_but_validates(): void
    {
        $ws = Workspace::factory()->create();
        $configs = [
            ['type' => 'slack', 'slackwebhookURL' => 'https://hooks.slack.com/x'],
            ['type' => 'webhook', 'webhookURL' => 'not a url'],
            ['type' => 'LineNotify'],
        ];

        $dry = $this->import('kuma1-backup.json', $ws, dryRun: true, notifications: $configs);
        $this->assertSame(0, NotificationChannel::count());
        $this->assertSame(0, ImportMapping::count());

        $real = $this->import('kuma1-backup.json', $ws, notifications: $configs);

        $this->assertSame(['would_create', 'invalid', 'skipped'], $this->outcomes($dry));
        $this->assertSame(['created', 'invalid', 'skipped'], $this->outcomes($real));
        $this->assertSame($this->entries($dry)[1]['errors'] ?? null, $this->entries($real)[1]['errors'] ?? null);
        $this->assertArrayHasKey('config.url', $this->entries($real)[1]['errors'] ?? []);
        $this->assertSame('provider_unsupported', $this->entries($real)[2]['notes'][0]['code']);
    }

    public function test_import_creates_channels_inactive_when_lossy(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import('kuma1-backup.json', $ws, notifications: [
            ['type' => 'apprise', 'appriseURL' => 'json://localhost'],
            ['type' => 'ntfy', 'ntfyserverurl' => 'https://ntfy.sh', 'ntfytopic' => 'alerts', 'ntfyAuthenticationMethod' => 'usernamePassword', 'ntfyusername' => 'u', 'ntfypassword' => 'pw'],
            ['type' => 'webhook', 'webhookURL' => 'https://x.example.com', 'webhookAdditionalHeaders' => '{broken'],
            ['type' => 'teams', 'webhookUrl' => 'https://x.example.com/t'],
        ]);

        $this->assertSame(['created' => 4], $report['counts']['notification']);
        $this->assertSame(
            [false, false, false, true],
            NotificationChannel::orderBy('id')->get()->map(fn (NotificationChannel $c): bool => $c->active)->all(),
        );
        $this->assertSame('apprise_server_required', $this->entries($report)[0]['notes'][0]['code']);
    }

    public function test_rerun_reports_existing_and_never_recreates(): void
    {
        $ws = Workspace::factory()->create();
        $this->import('kuma2.db', $ws);
        NotificationChannel::where('type', 'email')->update(['name' => 'Renamed']);

        $again = $this->import('kuma2.db', $ws);

        $this->assertSame(['existing' => 2], $again['counts']['notification']);
        $this->assertSame('already_imported', $this->entries($again)[0]['notes'][0]['code']);
        $this->assertSame('Renamed', NotificationChannel::where('type', 'email')->sole()->name);

        NotificationChannel::query()->delete();
        $third = $this->import('kuma2.db', $ws);
        $this->assertSame('deleted_locally', $this->entries($third)[0]['notes'][0]['code']);
        $this->assertSame(0, NotificationChannel::count());
    }

    public function test_applying_to_existing_monitors_is_not_done_by_import(): void
    {
        $ws = Workspace::factory()->create();
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);

        $this->import('kuma2.db', $ws);

        $this->assertSame(0, $monitor->notificationChannels()->count());
    }

    public function test_report_and_audit_contain_no_secret_values(): void
    {
        $secrets = ['S3cr3t-smtp-Pa55', 'Bearer S3cr3t-header', 'S3cr3t-hook-token', 'S3cr3t-app-key'];
        $configs = [
            ['type' => 'smtp', 'smtpHost' => 'h', 'smtpFrom' => 'k@example.com', 'smtpTo' => 'a@example.com', 'smtpPassword' => $secrets[0]],
            ['type' => 'webhook', 'webhookURL' => 'https://x.example.com', 'webhookAdditionalHeaders' => json_encode(['Authorization' => $secrets[1], 'X-Api-Token' => $secrets[2]])],
            // Invalid on purpose: validation errors must not echo values either.
            ['type' => 'gotify', 'gotifyserverurl' => 'not a url', 'gotifyapplicationToken' => $secrets[3]],
            ['type' => 'telegram', 'telegramChatID' => '1', 'telegramBotToken' => 'S3cr3t-bad-token-'.$secrets[3]],
        ];
        $secrets[] = 'S3cr3t-bad-token-';
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });
        $ws = Workspace::factory()->create();

        $dry = $this->import('kuma1-backup.json', $ws, dryRun: true, notifications: $configs);
        $real = $this->import('kuma1-backup.json', $ws, notifications: $configs);

        $this->assertSame(['created' => 2, 'invalid' => 2], $real['counts']['notification']);
        $haystack = json_encode([$dry, $real, AuditEvent::all()->toArray(), $logged]);
        $this->assertIsString($haystack);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
        $this->assertNotEmpty(NotificationChannel::where('type', 'email')->sole()->secrets);
    }

    public function test_fixture_secrets_stay_out_of_the_report(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import('kuma2.db', $ws);

        $json = json_encode($report);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('"abc"', $json);
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertSame(ImportOutcome::Created->value, $this->entries($report)[0]['outcome']);
    }

    public function test_unexpected_failure_logs_only_class_and_source_id(): void
    {
        $this->app->bind(CreateNotificationChannel::class, fn () => new class extends CreateNotificationChannel
        {
            public function handle(Workspace $workspace, array $validated, ?User $actor): NotificationChannel
            {
                throw new \RuntimeException('SQLSTATE bindings S3cr3t-binding');
            }
        });
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.json_encode($m->context);
        });

        $report = $this->import('kuma2.db', Workspace::factory()->create());

        $this->assertSame(['failed' => 2], $report['counts']['notification']);
        $this->assertStringNotContainsString('S3cr3t-binding', json_encode([$report, $logged]) ?: '');
        $this->assertNotEmpty($logged);
    }
}
