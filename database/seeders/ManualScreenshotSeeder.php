<?php

namespace Database\Seeders;

use App\Actions\CreateWorkspace;
use App\Actions\Maintenances\CreateMaintenance;
use App\Enums\ChannelType;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\CheckRollup;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorEvent;
use App\Models\NotificationChannel;
use App\Models\StatusPage;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo data for the screenshots of the user manual (`npm run manual:screenshots` in spa/): one user, one
 * workspace, eight monitors of several types with six hours of check history, two notification channels, an
 * open incident, a scheduled maintenance and a status page with a layout.
 *
 * Deterministic: a fixed random seed and timestamps relative to the moment it runs (or MANUAL_SEED_NOW), so
 * the screenshots taken right after seeding look the same on every run. The texts follow MANUAL_SEED_LOCALE (`hu` or `en`, default
 * `hu`), because the screenshots of each language are taken from data in that language.
 *
 * Refuses to run in production: it creates a user with a known password.
 */
class ManualScreenshotSeeder extends Seeder
{
    public const EMAIL = 'docs@example.test';

    /** Dev-only default; MANUAL_SEED_PASSWORD overrides it. */
    public const DEFAULT_PASSWORD = 'manual-screenshots';

    private const HISTORY_HOURS = 6;

    private const TIMEZONE = 'Europe/Budapest';

    private string $locale = 'hu';

    private CarbonImmutable $now;

    public function run(CreateWorkspace $createWorkspace, CreateMaintenance $createMaintenance): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ManualScreenshotSeeder creates a user with a known password and never runs in production.');
        }

        $this->locale = config('intrawatch.manual_screenshots.locale') === 'en' ? 'en' : 'hu';
        // MANUAL_SEED_NOW (epoch seconds) pins "now", so the screenshot script can freeze the browser clock at
        // the same moment. Whole minutes keep the check history aligned to minute buckets.
        $pinned = config('intrawatch.manual_screenshots.now');
        $this->now = (is_numeric($pinned) ? CarbonImmutable::createFromTimestamp((int) $pinned) : CarbonImmutable::now())->startOfMinute();
        mt_srand(20261110);

        $password = config('intrawatch.manual_screenshots.password');
        $user = User::query()->create([
            'name' => $this->t('Kiss Anna', 'Anna Kiss'),
            'email' => self::EMAIL,
            'password' => is_string($password) && $password !== '' ? $password : self::DEFAULT_PASSWORD,
        ]);
        $user->forceFill(['email_verified_at' => $this->now->subDays(30)])->save();

        $workspace = $createWorkspace->handle($user, $this->t('Példa Kft.', 'Example Inc.'));
        $workspace->update(['timezone' => self::TIMEZONE]);

        $channels = $this->channels($workspace);
        $tags = $this->tags($workspace);
        $monitors = $this->monitors($workspace, $channels, $tags);
        $statusPage = $this->statusPage($workspace, $monitors);
        $this->incident($workspace, $monitors['mail'], $statusPage);
        $this->maintenance($createMaintenance, $workspace, $monitors['database'], $statusPage);
    }

    private function t(string $hu, string $en): string
    {
        return $this->locale === 'hu' ? $hu : $en;
    }

    /**
     * @return list<NotificationChannel>
     */
    private function channels(Workspace $workspace): array
    {
        return [
            NotificationChannel::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $this->t('Ügyeletes csapat', 'On-call team'),
                'type' => ChannelType::Email,
                'config' => ['to' => ['ops@example.com']],
                'is_default' => true,
                'active' => true,
                'last_success_at' => $this->now->subMinutes(32),
            ]),
            NotificationChannel::query()->create([
                'workspace_id' => $workspace->id,
                'name' => '#monitoring',
                'type' => ChannelType::Slack,
                'config' => [],
                'secrets' => ['webhook_url' => 'https://hooks.example.com/services/T0000/B0000/demo'],
                'is_default' => false,
                'active' => true,
                'last_success_at' => $this->now->subMinutes(32),
            ]),
        ];
    }

    /**
     * @return array{prod: Tag, internal: Tag}
     */
    private function tags(Workspace $workspace): array
    {
        return [
            'prod' => Tag::query()->create(['workspace_id' => $workspace->id, 'name' => 'prod', 'color' => '#2e7d32']),
            'internal' => Tag::query()->create(['workspace_id' => $workspace->id, 'name' => $this->t('belső', 'internal'), 'color' => '#5c6bc0']),
        ];
    }

    /**
     * The monitors, keyed by a stable name, each with its history and current state.
     *
     * @param  list<NotificationChannel>  $channels
     * @param  array{prod: Tag, internal: Tag}  $tags
     * @return array<string, Monitor>
     */
    private function monitors(Workspace $workspace, array $channels, array $tags): array
    {
        $http = static fn (string $url, ?array $keyword = null): array => [
            'url' => $url,
            'method' => 'GET',
            'headers' => [],
            'body' => null,
            'accepted_status' => ['200-299'],
            'max_redirects' => 5,
            'keyword' => $keyword,
            'json_query' => null,
            'header_checks' => [],
            'mtls' => false,
            'proxy' => null,
        ];

        // name, type, config, interval, latency base (ms, null: none), tags, profile
        $specs = [
            'webshop' => [$this->t('Webshop', 'Webshop'), MonitorType::Http, $http('https://shop.example.com'), 60, 180, ['prod'], 'blip'],
            'api' => ['API', MonitorType::Http, $http('https://api.example.com/health', ['value' => '"status":"ok"', 'mode' => 'contains', 'case_sensitive' => false]), 60, 95, ['prod'], 'up'],
            'billing' => [$this->t('Számlázás', 'Billing'), MonitorType::Http, $http('https://billing.example.net/login'), 60, 420, ['prod'], 'degraded'],
            'mail' => [$this->t('Levelezőszerver', 'Mail server'), MonitorType::Tcp, ['host' => 'mail.example.net', 'port' => 587], 60, 35, ['internal'], 'down'],
            'database' => [$this->t('Adatbázis', 'Database'), MonitorType::Tcp, ['host' => 'db.example.net', 'port' => 5432], 60, 4, ['internal'], 'up'],
            'gateway' => ['Gateway', MonitorType::Ping, ['host' => 'gw.example.net', 'count' => 3], 60, 12, ['internal'], 'up'],
            'dns' => ['DNS example.com', MonitorType::Dns, ['name' => 'example.com', 'record_type' => 'A', 'resolver' => null, 'expected' => ['93.184.215.14'], 'match' => 'any', 'watch_changes' => false], 120, 24, [], 'up'],
            'backup' => [$this->t('Mentés', 'Backup job'), MonitorType::Push, ['grace_seconds' => 300], 300, null, ['internal'], 'up'],
        ];

        $monitors = [];
        foreach ($specs as $key => [$name, $type, $config, $interval, $latency, $tagKeys, $profile]) {
            $monitor = Monitor::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'type' => $type,
                'active' => true,
                'interval_seconds' => $interval,
                'timeout_seconds' => 10,
                'retries' => 1,
                'retry_interval_seconds' => 60,
                'degraded_threshold_ms' => $profile === 'degraded' ? 800 : null,
                'config' => $config,
                'push_token' => $type === MonitorType::Push ? str_repeat('d0c5', 12) : null,
            ]);
            $monitor->forceFill(['created_at' => $this->now->subDays(40), 'updated_at' => $this->now->subDays(40)])->save();
            $monitor->notificationChannels()->sync(array_map(static fn (NotificationChannel $c): int => $c->id, $channels));
            foreach ($tagKeys as $tagKey) {
                $monitor->tags()->attach($tags[$tagKey]->id, ['value' => null]);
            }
            $this->history($workspace, $monitor, $interval, $latency, $profile);
            $monitors[$key] = $monitor;
        }

        return $monitors;
    }

    /**
     * Writes the check results, rollups, status events and current state of a monitor.
     *
     * Profiles: `up` always passes; `blip` failed one check two hours ago; `degraded` has answered slower than
     * its threshold for the last 25 minutes; `down` has failed for the last 34 minutes.
     */
    private function history(Workspace $workspace, Monitor $monitor, int $interval, ?int $base, string $profile): void
    {
        $count = intdiv(self::HISTORY_HOURS * 3600, $interval);
        // The newest check ran a few seconds before "now", the rest at whole intervals before it.
        $last = $this->now->subSeconds(20);
        $rows = [];
        $status = MonitorStatus::Up;
        // A monitor whose state did not change within the history has been up for days.
        $since = $this->now->subDays(9)->subMinutes($monitor->id * 97);
        $events = [];
        $failures = 0;
        $newest = null;

        for ($i = $count - 1; $i >= 0; $i--) {
            $at = $last->subSeconds($i * $interval);
            $age = $i * $interval;
            $latency = $base === null ? null : max(1, (int) round($base * (0.8 + mt_rand(0, 40) / 100)));
            $next = MonitorStatus::Up;
            $message = null;

            if ($profile === 'blip' && $age >= 7200 && $age < 7200 + $interval) {
                $next = MonitorStatus::Down;
                $latency = null;
                $message = $this->t('Időtúllépés 10 másodperc után', 'Timed out after 10 seconds');
            } elseif ($profile === 'degraded' && $age < 1500 && $base !== null) {
                $next = MonitorStatus::Degraded;
                $latency = 900 + mt_rand(0, 450);
                $message = $this->t('A válaszidő meghaladja a 800 ms-ot', 'Response time above 800 ms');
            } elseif ($profile === 'down' && $age < 2040) {
                $next = MonitorStatus::Down;
                $latency = null;
                $message = $this->t('A kapcsolat elutasítva (mail.example.net:587)', 'Connection refused (mail.example.net:587)');
            }

            if ($next !== $status) {
                $events[] = [
                    'workspace_id' => $workspace->id,
                    'monitor_id' => $monitor->id,
                    'kind' => MonitorEvent::KIND_STATUS_CHANGED,
                    'from_status' => $status->value,
                    'to_status' => $next->value,
                    'message' => $message ?? $this->t('Ismét elérhető', 'Back up'),
                    'created_at' => $at,
                ];
                $status = $next;
                $since = $at;
            }
            $failures = $next === MonitorStatus::Down ? $failures + 1 : 0;

            $newest = [
                'monitor_id' => $monitor->id,
                'status' => $next->value,
                'latency_ms' => $latency,
                'message' => $message,
                'facts' => null,
                'checked_at' => $at,
                'drift_ms' => 0,
                'bucket_minute' => intdiv($at->getTimestamp(), 60) * 60,
            ];
            $rows[] = $newest;
        }
        if ($newest === null) {
            throw new RuntimeException('The history interval leaves no check.');
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('check_results')->insert($chunk);
        }
        if ($events !== []) {
            DB::table('monitor_events')->insert($events);
        }
        $this->rollups($monitor, $rows);

        $monitor->state()->update([
            'status' => $status,
            'status_since' => $since,
            'last_checked_at' => $newest['checked_at'],
            'consecutive_failures' => $failures,
            'last_latency_ms' => $newest['latency_ms'],
            'last_message' => $newest['message'],
            'last_ping_at' => $monitor->type === MonitorType::Push ? $newest['checked_at'] : null,
            'deadline_anchor_at' => $newest['checked_at'],
        ]);
    }

    /**
     * Minute, hour and (workspace-local) day rollups of the generated results, as the rollup builder would
     * aggregate them.
     *
     * @param  list<array{status: 'up'|'down'|'degraded', latency_ms: int|null, checked_at: CarbonImmutable}>  $rows
     */
    private function rollups(Monitor $monitor, array $rows): void
    {
        $buckets = [];
        foreach ($rows as $row) {
            $at = $row['checked_at'];
            $starts = [
                CheckRollup::BUCKET_MINUTE => intdiv($at->getTimestamp(), 60) * 60,
                CheckRollup::BUCKET_HOUR => intdiv($at->getTimestamp(), 3600) * 3600,
                CheckRollup::BUCKET_DAY => $at->setTimezone(self::TIMEZONE)->startOfDay()->getTimestamp(),
            ];
            foreach ($starts as $bucket => $start) {
                $key = $bucket.':'.$start;
                $buckets[$key] = self::count($buckets[$key] ?? self::bucket($monitor->id, $bucket, $start), $row['status'], $row['latency_ms']);
            }
        }

        foreach (array_chunk(array_values($buckets), 500) as $chunk) {
            DB::table('check_rollups')->insert($chunk);
        }
    }

    /**
     * @return array{monitor_id: int, bucket: string, bucket_start: int, total: int, up: int, down: int, degraded: int, pending: int, maintenance: int, latency_min: int|null, latency_max: int|null, latency_avg: int|null, latency_sum: int, latency_count: int}
     */
    private static function bucket(int $monitorId, string $bucket, int $start): array
    {
        return [
            'monitor_id' => $monitorId, 'bucket' => $bucket, 'bucket_start' => $start,
            'total' => 0, 'up' => 0, 'down' => 0, 'degraded' => 0, 'pending' => 0, 'maintenance' => 0,
            'latency_min' => null, 'latency_max' => null, 'latency_avg' => null, 'latency_sum' => 0, 'latency_count' => 0,
        ];
    }

    /**
     * Adds one check result to a rollup bucket.
     *
     * @param  array{monitor_id: int, bucket: string, bucket_start: int, total: int, up: int, down: int, degraded: int, pending: int, maintenance: int, latency_min: int|null, latency_max: int|null, latency_avg: int|null, latency_sum: int, latency_count: int}  $bucket
     * @param  'up'|'down'|'degraded'  $status
     * @return array{monitor_id: int, bucket: string, bucket_start: int, total: int, up: int, down: int, degraded: int, pending: int, maintenance: int, latency_min: int|null, latency_max: int|null, latency_avg: int|null, latency_sum: int, latency_count: int}
     */
    private static function count(array $bucket, string $status, ?int $latency): array
    {
        $bucket['total']++;
        $bucket[$status]++;
        if ($latency !== null) {
            $bucket['latency_min'] = min($bucket['latency_min'] ?? $latency, $latency);
            $bucket['latency_max'] = max($bucket['latency_max'] ?? $latency, $latency);
            $bucket['latency_sum'] += $latency;
            $bucket['latency_count']++;
            $bucket['latency_avg'] = intdiv($bucket['latency_sum'], $bucket['latency_count']);
        }

        return $bucket;
    }

    /**
     * @param  array<string, Monitor>  $monitors
     */
    private function statusPage(Workspace $workspace, array $monitors): StatusPage
    {
        $page = StatusPage::query()->create([
            'workspace_id' => $workspace->id,
            'slug' => 'example-status',
            'title' => $this->t('Példa Kft. – rendszerállapot', 'Example Inc. system status'),
            'description' => $this->t('Szolgáltatásaink aktuális állapota.', 'The current status of our services.'),
            'published' => true,
            'locale' => $this->locale,
        ]);

        $layout = [
            [$this->t('Weboldalak', 'Websites'), $this->t('Ügyfeleink által használt oldalak', 'Pages our customers use'), ['webshop', 'api', 'billing']],
            [$this->t('Belső rendszerek', 'Internal systems'), null, ['mail', 'database']],
        ];
        foreach ($layout as $position => [$name, $description, $keys]) {
            $section = $page->sections()->create(['name' => $name, 'description' => $description, 'position' => $position]);
            foreach ($keys as $index => $key) {
                $section->items()->create(['monitor_id' => $monitors[$key]->id, 'position' => $index, 'show_uptime' => true]);
            }
        }

        return $page;
    }

    private function incident(Workspace $workspace, Monitor $monitor, StatusPage $page): void
    {
        $started = $this->now->subMinutes(30);
        $incident = Incident::query()->create([
            'workspace_id' => $workspace->id,
            'title' => $this->t('Késik a levelek kézbesítése', 'Email delivery delayed'),
            'impact' => IncidentImpact::Major,
            'internal' => false,
            'status' => IncidentStatus::Identified,
            'started_at' => $started,
        ]);
        $incident->forceFill(['created_at' => $started, 'updated_at' => $started->addMinutes(18)])->save();
        $incident->monitors()->sync([$monitor->id]);
        DB::table('status_page_links')->insert(['status_page_id' => $page->id, 'linkable_type' => 'incident', 'linkable_id' => $incident->id]);

        $updates = [
            [IncidentStatus::Investigating, $this->t(
                'A kimenő levelek kézbesítése késik. Vizsgáljuk a hiba okát.',
                'Outgoing email is delayed. We are investigating the cause.',
            ), $started],
            [IncidentStatus::Identified, $this->t(
                'A hibát a levelezőszerver megtelt tárhelye okozza. A tárhely bővítése folyamatban van, a sorban álló levelek ezután automatikusan kézbesítésre kerülnek.',
                'The mail server ran out of disk space. We are expanding the storage; queued messages will be delivered automatically afterwards.',
            ), $started->addMinutes(18)],
        ];
        foreach ($updates as [$status, $message, $at]) {
            $update = $incident->updates()->create(['status' => $status, 'message' => $message]);
            // A set updated_at marks an update as edited afterwards.
            DB::table('incident_updates')->where('id', $update->id)->update(['created_at' => $at, 'updated_at' => null]);
        }
    }

    private function maintenance(CreateMaintenance $create, Workspace $workspace, Monitor $monitor, StatusPage $page): void
    {
        $starts = $this->now->setTimezone(self::TIMEZONE)->addDays(2)->setTime(22, 0);
        $validated = $create->validate($workspace, [
            'title' => $this->t('Adatbázis-frissítés', 'Database upgrade'),
            'description' => $this->t('Az adatbázis-szerver frissítése alatt a számlázás rövid ideig nem érhető el.', 'Billing may be briefly unavailable while the database server is upgraded.'),
            'strategy' => 'once',
            'timezone' => self::TIMEZONE,
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $starts->addHours(2)->toIso8601String(),
            'monitor_ids' => [$monitor->id],
            'status_page_ids' => [$page->id],
        ]);
        $create->handle($workspace, $validated, null);
    }
}
