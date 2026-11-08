<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Actions\Monitors\CreateMonitor;
use App\Enums\MonitorType;
use App\Enums\WorkspaceRole;
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
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Types\GroupHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * @phpstan-import-type ReportShape from ImportReport
 * @phpstan-import-type EntryShape from ImportReport
 */
final class ImportMonitorsTest extends TestCase
{
    use RefreshDatabase;

    /** The one note for every replaced push token, whatever the reason. */
    private const REGENERATED = ['code' => 'push_token_regenerated', 'message' => 'The push token was replaced with a new one, so the push URL changes.'];

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
     * @return ReportShape
     */
    private function import(string $path, Workspace $workspace, bool $dryRun = false, bool $paused = false): array
    {
        $source = $this->sources[] = SourceFactory::open($path);

        return app(KumaImporter::class)->run($source, $workspace, new ImportOptions(dryRun: $dryRun, paused: $paused), null)->toArray();
    }

    /**
     * A 1.23 JSON backup holding the given monitors. Each monitor is completed with the fields Kuma always sets.
     *
     * @param  list<array<string, mixed>>  $monitors
     * @param  list<array<string, mixed>>  $notifications
     */
    private function backup(array $monitors, array $notifications = []): string
    {
        $list = [];
        foreach ($monitors as $monitor) {
            $list[] = $monitor + [
                'name' => 'M'.(is_scalar($monitor['id'] ?? null) ? $monitor['id'] : ''), 'type' => 'http', 'active' => true,
                'interval' => 60, 'timeout' => 48, 'url' => 'https://example.com', 'method' => 'GET', 'maxretries' => 0,
                'retryInterval' => 60, 'resendInterval' => 0, 'upsideDown' => false, 'ignoreTls' => false,
                'maxredirects' => 10, 'accepted_statuscodes' => ['200-299'], 'parent' => null, 'tags' => [],
                'notificationIDList' => [],
            ];
        }
        $path = tempnam(sys_get_temp_dir(), 'iw-kuma-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, json_encode(['version' => '1.23.17', 'notificationList' => $notifications, 'monitorList' => $list]));

        return $path;
    }

    /**
     * @param  ReportShape  $report
     * @return array<int, EntryShape>
     */
    private function entries(array $report): array
    {
        $entries = [];
        foreach ($report['entries'] as $entry) {
            if ($entry['entity'] === 'monitor') {
                $entries[$entry['source_id']] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  EntryShape  $entry
     * @return list<string>
     */
    private static function codes(array $entry): array
    {
        return array_map(fn (array $n): string => $n['code'], $entry['notes']);
    }

    /**
     * @param  ReportShape  $report
     */
    private function monitor(array $report, int $sourceId): Monitor
    {
        $id = $this->entries($report)[$sourceId]['target_id'] ?? null;
        $this->assertIsInt($id, "monitor {$sourceId} was not created");

        return Monitor::query()->findOrFail($id);
    }

    public function test_fixture_monitors_are_imported_with_parents_tags_and_channels(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertSame(['created' => 9, 'skipped' => 1], $report['counts']['monitor']);
        $this->assertSame('type_unsupported', $this->entries($report)[10]['notes'][0]['code']);

        $group = $this->monitor($report, 1);
        $http = $this->monitor($report, 2);
        $this->assertSame(MonitorType::Group, $group->type);
        $this->assertSame($group->id, $http->parent_id);
        $this->assertSame($group->id, $this->monitor($report, 3)->parent_id);
        $this->assertSame('bp', $http->secrets['basic_password'] ?? null);
        $this->assertSame(['eu-west'], $http->tags()->pluck('monitor_tag.value')->all());
        $this->assertSame(
            NotificationChannel::query()->orderBy('id')->pluck('id')->all(),
            $http->notificationChannelIds(),
        );
        $this->assertSame(10, $http->resend_interval_minutes);

        $proxied = $this->monitor($report, 5);
        $this->assertFalse($proxied->active);
        $this->assertSame('pp', $proxied->secrets['proxy_password'] ?? null);

        $this->assertSame(9, ImportMapping::where('entity', 'monitor')->count());
        $this->assertSame(9, AuditEvent::where('action', 'monitor.created')->count());
    }

    public function test_monitors_without_bindings_get_no_default_channels(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertTrue(NotificationChannel::where('is_default', true)->exists(), 'the fixture has a default channel');
        $tcp = $this->monitor($report, 6);
        $this->assertSame([], $tcp->notificationChannelIds());
        $this->assertContains('default_channels_not_applied', self::codes($this->entries($report)[6]));
        $this->assertNotContains('default_channels_not_applied', self::codes($this->entries($report)[2]));
    }

    public function test_dry_run_validates_and_writes_nothing(): void
    {
        $ws = Workspace::factory()->create();

        $dry = $this->import(ImporterTest::fixture('kuma2.db'), $ws, dryRun: true);

        $this->assertSame(['would_create' => 9, 'skipped' => 1], $dry['counts']['monitor']);
        $this->assertSame(0, Monitor::count());
        $this->assertSame(0, ImportMapping::count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_interval_and_timeout_are_clamped_with_notes(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import($this->backup([
            ['id' => 1, 'interval' => 10, 'timeout' => 30, 'retryInterval' => 5],
        ]), $ws);

        $monitor = $this->monitor($report, 1);
        $this->assertSame(20, $monitor->interval_seconds);
        $this->assertSame(16, $monitor->timeout_seconds);
        $this->assertSame(20, $monitor->retry_interval_seconds);
        $this->assertSame(['interval_raised', 'timeout_adjusted', 'retry_interval_raised'], self::codes($this->entries($report)[1]));
    }

    public function test_invalid_monitor_does_not_stop_the_import(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import($this->backup([
            ['id' => 1, 'type' => 'port', 'hostname' => 'not a host!', 'port' => 22],
            // Private and loopback targets are allowed by the default network policy (it only blocks the cloud
            // metadata address, and only when a check connects), so this one is created.
            ['id' => 2, 'url' => 'http://127.0.0.1:8080/health'],
            ['id' => 3, 'type' => 'http', 'url' => 'ftp://example.com/'],
            ['id' => 4, 'type' => 'dns', 'hostname' => 'example.com', 'dns_resolve_type' => 'A'],
        ]), $ws);

        $entries = $this->entries($report);
        $this->assertSame('invalid', $entries[1]['outcome']);
        $this->assertArrayHasKey('config.host', $entries[1]['errors'] ?? []);
        $this->assertSame(['monitor_invalid'], self::codes($entries[1]));
        $this->assertSame('created', $entries[2]['outcome']);
        $this->assertSame('invalid', $entries[3]['outcome']);
        $this->assertArrayHasKey('config.url', $entries[3]['errors'] ?? []);
        $this->assertSame('created', $entries[4]['outcome']);
        $this->assertSame(2, Monitor::count());
    }

    public function test_children_follow_imported_parents_and_orphans_go_top_level(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import($this->backup([
            // Listed before its parent: parents are still created first.
            ['id' => 1, 'parent' => 2],
            ['id' => 2, 'type' => 'group', 'name' => 'Parent'],
            // A group our validation rejects, and its child.
            ['id' => 3, 'type' => 'group', 'name' => ''],
            ['id' => 4, 'parent' => 3],
            // A skipped parent, and a parent id that does not exist.
            ['id' => 5, 'type' => 'redis'],
            ['id' => 6, 'parent' => 5],
            ['id' => 7, 'parent' => 99],
        ]), $ws);

        $entries = $this->entries($report);
        $this->assertSame($this->monitor($report, 2)->id, $this->monitor($report, 1)->parent_id);
        $this->assertSame('invalid', $entries[3]['outcome']);
        foreach ([4, 6, 7] as $orphan) {
            $this->assertNull($this->monitor($report, $orphan)->parent_id);
            $this->assertSame(['parent_not_imported'], self::codes($entries[$orphan]), "monitor {$orphan}");
        }
        $order = array_keys($entries);
        $this->assertLessThan(array_search(1, $order, true), array_search(2, $order, true));
    }

    public function test_parent_cycles_do_not_hang_and_go_top_level(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import($this->backup([
            ['id' => 1, 'type' => 'group', 'parent' => 2],
            ['id' => 2, 'type' => 'group', 'parent' => 1],
        ]), $ws);

        $this->assertSame(['created' => 2], $report['counts']['monitor']);
        $this->assertSame(1, Monitor::whereNull('parent_id')->count());
    }

    public function test_depth_cap_reattaches_with_note(): void
    {
        $ws = Workspace::factory()->create();
        $monitors = [['id' => 1, 'type' => 'group']];
        for ($id = 2; $id <= 6; $id++) {
            $monitors[] = ['id' => $id, 'type' => 'group', 'parent' => $id - 1];
        }
        $monitors[] = ['id' => 7, 'parent' => 6];

        $report = $this->import($this->backup($monitors), $ws);

        $this->assertSame(['created' => 7], $report['counts']['monitor']);
        $entries = $this->entries($report);
        $level4 = $this->monitor($report, 4);
        $this->assertSame($this->monitor($report, 5)->parent_id, $level4->id);
        $this->assertSame($level4->id, $this->monitor($report, 6)->parent_id);
        $this->assertSame(['depth_capped'], self::codes($entries[6]));
        // The capped group sits at the last level, so its own child is capped as well.
        $this->assertSame($level4->id, $this->monitor($report, 7)->parent_id);
        $this->assertSame(['depth_capped'], self::codes($entries[7]));
        $this->assertSame(5, GroupHierarchy::MAX_DEPTH);
    }

    public function test_push_token_held_in_another_workspace_is_regenerated_without_saying_why(): void
    {
        $token = str_repeat('aB3', 10).'xy'; // 32 characters, so only the collision applies
        $other = Workspace::factory()->create();
        Monitor::factory()->for($other)->push()->create(['push_token' => $token]);
        $ws = Workspace::factory()->create();
        $path = $this->backup([['id' => 1, 'type' => 'push', 'pushToken' => $token]]);

        foreach ([true, false] as $dryRun) {
            $report = $this->import($path, $ws, dryRun: $dryRun);

            $this->assertSame([self::REGENERATED], $this->entries($report)[1]['notes']);
            $this->assertStringNotContainsString('push_token_collision', (string) json_encode($report));
            $this->assertStringNotContainsString($token, (string) json_encode($report));
        }

        $push = $this->monitor($report, 1);
        $this->assertNotSame($token, $push->push_token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', (string) $push->push_token);
        $this->assertSame(1, Monitor::query()->where('push_token', $token)->count());
    }

    public function test_short_push_token_is_kept_for_the_kuma_push_alias(): void
    {
        $ws = Workspace::factory()->create();
        $short = str_repeat('aB3', 10).'x'; // 31 characters
        $path = $this->backup([
            ['id' => 1, 'type' => 'push', 'pushToken' => $short],
            ['id' => 2, 'type' => 'push', 'pushToken' => 'a'],
            ['id' => 3, 'type' => 'push', 'pushToken' => 'researchPushToken01'],
        ]);

        $dry = $this->entries($this->import($path, $ws, dryRun: true));
        $report = $this->import($path, $ws);
        $entries = $this->entries($report);

        foreach ([1 => $short, 2 => 'a', 3 => 'researchPushToken01'] as $id => $token) {
            $this->assertSame([], $dry[$id]['notes']);
            $this->assertSame([], $entries[$id]['notes']);
            $this->assertSame($token, $this->monitor($report, $id)->push_token);
        }
    }

    public function test_push_token_with_unsupported_characters_is_regenerated(): void
    {
        $ws = Workspace::factory()->create();
        $report = $this->import($this->backup([['id' => 1, 'type' => 'push', 'pushToken' => 'bad-token!']]), $ws);

        $this->assertSame([self::REGENERATED], $this->entries($report)[1]['notes']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', (string) $this->monitor($report, 1)->push_token);
    }

    public function test_long_unique_push_token_is_kept(): void
    {
        $ws = Workspace::factory()->create();
        $token32 = str_repeat('Kp7', 10).'Zz';
        $token48 = str_repeat('Kp7Q', 12);

        $report = $this->import($this->backup([
            ['id' => 1, 'type' => 'push', 'pushToken' => $token32],
            ['id' => 2, 'type' => 'push', 'pushToken' => $token48],
        ]), $ws);

        $this->assertSame($token32, $this->monitor($report, 1)->push_token);
        $this->assertSame($token48, $this->monitor($report, 2)->push_token);
        $this->assertSame([], $this->entries($report)[1]['notes']);
    }

    public function test_push_token_with_foreign_characters_is_regenerated(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import($this->backup([['id' => 1, 'type' => 'push', 'pushToken' => 'abc-def']]), $ws);

        $push = $this->monitor($report, 1);
        $this->assertNotSame('abc-def', $push->push_token);
        $this->assertSame([self::REGENERATED], $this->entries($report)[1]['notes']);
    }

    public function test_rerun_keeps_local_edits_and_deletions(): void
    {
        $ws = Workspace::factory()->create();
        $first = $this->import(ImporterTest::fixture('kuma2.db'), $ws);
        $renamed = $this->monitor($first, 2);
        $renamed->update(['name' => 'Renamed locally']);
        $renamed->notificationChannels()->sync([]);
        $this->monitor($first, 6)->delete();
        $count = Monitor::count();

        $again = $this->import(ImporterTest::fixture('kuma2.db'), $ws);

        $this->assertSame(['existing' => 9, 'skipped' => 1], $again['counts']['monitor']);
        $this->assertSame(['already_imported'], self::codes($this->entries($again)[2]));
        $this->assertSame(['deleted_locally'], self::codes($this->entries($again)[6]));
        $this->assertSame($count, Monitor::count());
        $this->assertSame('Renamed locally', $renamed->fresh()?->name);
        $this->assertSame([], $renamed->notificationChannelIds());
        $this->assertSame(9, ImportMapping::where('entity', 'monitor')->count());
    }

    public function test_child_imported_on_rerun_attaches_to_the_existing_parent(): void
    {
        $ws = Workspace::factory()->create();
        $this->import($this->backup([['id' => 1, 'type' => 'group'], ['id' => 2, 'parent' => 1, 'hostname' => 'not a host!', 'port' => 22, 'type' => 'port']]), $ws);

        $again = $this->import($this->backup([['id' => 1, 'type' => 'group'], ['id' => 2, 'parent' => 1, 'hostname' => 'example.com', 'port' => 22, 'type' => 'port']]), $ws);

        $this->assertSame('existing', $this->entries($again)[1]['outcome']);
        $this->assertSame($this->monitor($again, 1)->id, $this->monitor($again, 2)->parent_id);
    }

    public function test_paused_option_imports_every_monitor_paused(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import(ImporterTest::fixture('kuma2.db'), $ws, paused: true);

        $this->assertSame(['created' => 9, 'skipped' => 1], $report['counts']['monitor']);
        $this->assertSame(0, Monitor::where('active', true)->count());
    }

    public function test_lossy_mapping_is_imported_paused(): void
    {
        $ws = Workspace::factory()->create();

        $report = $this->import($this->backup([
            ['id' => 1, 'type' => 'json-query', 'jsonPath' => '$count(items)', 'expectedValue' => '2'],
            ['id' => 2, 'authMethod' => 'ntlm', 'basic_auth_user' => 'u', 'basic_auth_pass' => 'p'],
        ]), $ws);

        $this->assertFalse($this->monitor($report, 1)->active);
        $this->assertSame(['jsonata_untranslatable'], self::codes($this->entries($report)[1]));
        $this->assertFalse($this->monitor($report, 2)->active);
        $this->assertSame(['auth_unsupported'], self::codes($this->entries($report)[2]));
    }

    public function test_report_and_audit_contain_no_secret_values(): void
    {
        $secrets = ['S3cr3t-basic-Pa55', 'S3cr3t-bearer-tok', 'S3cr3t-header-val', 'S3cr3t-url-pass', 'S3cr3t-mtls-key', 'S3cr3t-proxy-pw'];
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });
        $ws = Workspace::factory()->create();
        $path = $this->backup([
            ['id' => 1, 'authMethod' => 'basic', 'basic_auth_user' => 'u', 'basic_auth_pass' => $secrets[0]],
            ['id' => 2, 'authMethod' => 'bearer', 'bearer_token' => $secrets[1], 'headers' => json_encode(['Authorization' => $secrets[2], 'X-Plain' => 'p'])],
            ['id' => 3, 'url' => 'https://user:'.$secrets[3].'@example.com/'],
            // Invalid on purpose: the key is not PEM, and the errors must not echo it.
            ['id' => 4, 'authMethod' => 'mtls', 'tlsCert' => 'nope', 'tlsKey' => $secrets[4]],
            // Invalid on purpose: the url is not http(s); the bearer token must not leak through the errors.
            ['id' => 5, 'url' => 'gopher://example.com', 'authMethod' => 'bearer', 'bearer_token' => $secrets[1]],
        ]);

        $dry = $this->import($path, $ws, dryRun: true);
        $real = $this->import($path, $ws);
        $fixture = $this->import(ImporterTest::fixture('kuma2.db'), Workspace::factory()->create());

        $this->assertSame(['created' => 3, 'invalid' => 2], $real['counts']['monitor']);
        $haystack = json_encode([$dry, $real, $fixture, AuditEvent::all()->toArray(), $logged]);
        $this->assertIsString($haystack);
        foreach ([...$secrets, '"bp"', '"pp"'] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
        $this->assertSame($secrets[3], $this->monitor($real, 3)->secrets['basic_password'] ?? null);
        $this->assertSame(['Authorization' => $secrets[2]], $this->monitor($real, 2)->secrets['secret_headers'] ?? null);
    }

    public function test_url_credentials_never_reach_config_audit_report_or_api(): void
    {
        $password = 'S3cr3t-url-Pa55';
        $ws = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($ws, WorkspaceRole::Viewer)->create();
        $path = $this->backup([
            ['id' => 1, 'url' => 'https://ops:'.$password.'@example.com/health'],
            // The monitor's own basic authentication wins over the URL credentials.
            ['id' => 2, 'url' => 'https://ops:'.$password.'@example.com/health', 'authMethod' => 'basic', 'basic_auth_user' => 'own', 'basic_auth_pass' => 'own-pass'],
            // parse_url() rejects this URL (port out of range); the credentials must still not survive.
            ['id' => 3, 'url' => 'https://ops:'.$password.'@example.com:99999/health'],
        ]);

        $report = $this->import($path, $ws);

        $moved = $this->monitor($report, 1);
        $this->assertSame('https://example.com/health', $moved->config['url'] ?? null);
        $this->assertSame(['type' => 'basic', 'username' => 'ops'], $moved->config['auth'] ?? null);
        $this->assertSame($password, $moved->secrets['basic_password'] ?? null);
        $this->assertSame(['url_credentials_moved'], self::codes($this->entries($report)[1]));

        $own = $this->monitor($report, 2);
        $this->assertSame('https://example.com/health', $own->config['url'] ?? null);
        $this->assertSame(['type' => 'basic', 'username' => 'own'], $own->config['auth'] ?? null);
        $this->assertSame('own-pass', $own->secrets['basic_password'] ?? null);
        $this->assertSame(['url_credentials_dropped'], self::codes($this->entries($report)[2]));

        $this->assertContains('url_credentials_dropped', self::codes($this->entries($report)[3]));

        $api = [];
        foreach ([$moved, $own] as $monitor) {
            $api[] = $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->slug}/monitors/{$monitor->id}")->assertOk()->json();
        }
        $api[] = $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->slug}/monitors")->assertOk()->json();
        $haystack = json_encode([
            $report,
            DB::table('monitors')->pluck('config')->all(),
            AuditEvent::all()->toArray(),
            $api,
        ]);
        $this->assertIsString($haystack);
        $this->assertStringNotContainsString($password, $haystack);
        $this->assertStringNotContainsString('ops:', $haystack);
    }

    public function test_unexpected_failure_logs_only_class_and_source_id(): void
    {
        $this->app->bind(CreateMonitor::class, fn () => new class(app(GroupEvaluator::class)) extends CreateMonitor
        {
            public function handle(Workspace $workspace, array $validated, ?User $actor, ?\Closure $within = null): Monitor
            {
                throw new \RuntimeException('SQLSTATE bindings S3cr3t-binding');
            }
        });
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.json_encode($m->context);
        });

        $report = $this->import(ImporterTest::fixture('kuma2.db'), Workspace::factory()->create());

        $this->assertSame(['failed' => 9, 'skipped' => 1], $report['counts']['monitor']);
        $this->assertSame(['unexpected_error'], self::codes($this->entries($report)[2]));
        $this->assertStringNotContainsString('S3cr3t-binding', json_encode([$report, $logged]) ?: '');
        $this->assertStringContainsString('RuntimeException', implode("\n", $logged));
        $this->assertSame(0, Monitor::count());
    }
}
