<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Enums\MonitorType;
use App\Enums\TokenAbility;
use App\Enums\WorkspaceRole;
use App\Models\CheckRollup;
use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

#[Signature('intrawatch:bench:scale {--monitors=5000} {--pages=300} {--items=20} {--requests=2000} {--base-url=http://127.0.0.1:3000 : The running application to measure} {--settle=60 : Seconds to let the check worker pick up the monitors before measuring} {--keep : Keep the seeded workspace} {--force : Run even though the database holds other workspaces}')]
#[Description('Seed thousands of active monitors and hundreds of status pages, then measure admin lists and status pages over HTTP')]
#[Help(<<<'TXT'
Run it inside a dedicated benchmark container started with TRUSTED_PROXIES=127.0.0.1. Status page requests carry a
different X-Forwarded-For each, so the per-IP public status rate limit stays out of the measurement.

Warning: TRUSTED_PROXIES=127.0.0.1 is a setting for the benchmark container only. It makes the application trust the
X-Forwarded-For header that clients send, which defeats the per-IP public status rate limit. Never set it in
production unless a real reverse proxy at that address sets the header.

The checks reach loopback without any network policy switch: by default only the built-in cloud metadata addresses
are blocked (CHECK_BLOCKED_NETWORKS is empty).
TXT)]
class BenchScaleCommand extends Command
{
    /** The 95th percentile of any admin list at which a run fails, in milliseconds. */
    private const MAX_ADMIN_P95_MS = 500.0;

    /** The warm status page 95th percentile at which a run fails, in milliseconds. */
    private const MAX_WARM_STATUS_P95_MS = 100.0;

    private const MAX_ITEMS = 250;

    private const MAX_GROUPS = 250;

    private const TAGS = 50;

    private const CHANNELS = 15;

    private const DAYS = 90;

    private const HOURS = 48;

    private const CHUNK = 500;

    private const INTERVAL = 60;

    /** Push monitors expect a ping only this often, so they stay up for the whole run without one. */
    private const PUSH_INTERVAL = 3600;

    private const ALERTS = 2000;

    private const AUDIT_EVENTS = 2000;

    private const INCIDENTS = 200;

    private const MAINTENANCES = 50;

    private const SEARCH = 'api';

    private const NAME_PREFIXES = ['api', 'web', 'db', 'cache', 'queue', 'mail', 'auth'];

    private bool $interrupted = false;

    public function handle(): int
    {
        $monitors = $this->positiveOption('monitors');
        $pages = $this->positiveOption('pages');
        $items = $this->positiveOption('items');
        $requests = $this->positiveOption('requests');
        $settle = $this->option('settle');
        $settle = is_numeric($settle) && (int) $settle >= 0 ? (int) $settle : null;
        $base = rtrim((string) $this->option('base-url'), '/');
        $target = parse_url($base);
        if ($monitors === null || $pages === null || $items === null || $requests === null || $settle === null) {
            $this->error('--monitors, --pages, --items and --requests must be positive whole numbers, --settle zero or more.');

            return self::INVALID;
        }
        if ($items > self::MAX_ITEMS) {
            $this->error('--items must not exceed '.self::MAX_ITEMS.', the most a status page holds.');

            return self::INVALID;
        }
        if (! is_array($target) || ! in_array($target['scheme'] ?? null, ['http', 'https'], true) || ! isset($target['host'])) {
            $this->error('--base-url must be an http or https URL.');

            return self::INVALID;
        }
        if (! $this->option('force') && Workspace::query()->exists()) {
            $this->error('The database already holds workspaces. Run the benchmark against an empty database, or pass --force.');

            return self::FAILURE;
        }

        $this->trapSignals();
        $workspace = Workspace::query()->create(['name' => 'Bench scale', 'slug' => Workspace::uniqueSlug('bench-scale'), 'timezone' => 'UTC']);
        $user = null;
        $exit = self::FAILURE;

        try {
            $user = $this->benchUser($workspace);
            $exit = $this->measure($workspace, $user, $monitors, $pages, $items, $requests, $settle, $base, $target);
        } finally {
            DB::table('personal_access_tokens')->where('workspace_id', $workspace->id)->delete();
            if ($this->option('keep')) {
                $this->line("Kept workspace {$workspace->slug}".($exit !== self::SUCCESS ? ' after a failed run.' : '.'));
            } elseif (! $this->cleanUp($workspace, $user)) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    /**
     * Seeds the workspace, lets the worker settle and measures; returns the exit code.
     *
     * @param  array<string, mixed>  $target
     */
    private function measure(Workspace $workspace, User $user, int $monitors, int $pages, int $items, int $requests, int $settle, string $base, array $target): int
    {
        $seeded = $this->seed($workspace, $monitors, $pages, $items, $base, $target);
        if ($this->interrupted) {
            $this->warn('Interrupted; no results are reported.');

            return self::FAILURE;
        }
        $this->line("Seeded {$monitors} monitors, {$seeded['groups']} groups, {$pages} pages of {$items} items in workspace {$workspace->slug}.");

        // Activated only now, so an interrupted seeding never leaves monitors a worker would check.
        DB::table('monitors')->where('workspace_id', $workspace->id)->where('type', '!=', MonitorType::Group->value)->update(['active' => true]);
        if ($settle > 0) {
            $this->line("Waiting {$settle} s for the check worker to pick up the monitors.");
            $this->sleep($settle);
        }
        $checksBefore = $this->checkCount($workspace);
        $measureStarted = microtime(true);

        $token = $this->token($user, $workspace);
        $api = Http::baseUrl($base.'/api/v1/workspaces/'.$workspace->slug)->withToken($token)->acceptJson()->timeout(60);
        /** @var array<string, int> $errors failed requests by endpoint and status code */
        $errors = [];
        $admin = $this->adminPass($api, $this->endpoints($seeded), $requests, $errors);
        $web = Http::baseUrl($base)->timeout(60);
        $cold = $this->statusPass($web, $seeded['slugs'], count($seeded['slugs']), 0, 'status cold', $errors);
        $warm = $this->statusPass($web, $seeded['slugs'], $requests, count($seeded['slugs']), 'status warm', $errors);
        if ($this->interrupted) {
            $this->warn('Interrupted; no results are reported.');

            return self::FAILURE;
        }

        $elapsed = microtime(true) - $measureStarted;

        return $this->report($workspace, $admin, $cold, $warm, $errors, $seeded, $monitors, $pages, $items, $this->checkCount($workspace) - $checksBefore, $elapsed);
    }

    private function benchUser(Workspace $workspace): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Bench scale',
            'email' => 'bench-scale-'.Str::lower(Str::random(10)).'@bench.invalid',
            'password' => Hash::make(Str::random(40)),
        ])->save();
        (new Membership)->forceFill(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner])->save();

        return $user;
    }

    private function token(User $user, Workspace $workspace): string
    {
        $created = $user->createToken('bench-scale', array_map(static fn (TokenAbility $a): string => $a->value, TokenAbility::cases()));
        $created->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $created->plainTextToken;
    }

    /**
     * Seeds the workspace with direct, chunked inserts.
     *
     * @param  array<string, mixed>  $target
     * @return array{groups: int, slugs: list<string>, tag: int, group: int}
     */
    private function seed(Workspace $workspace, int $count, int $pages, int $items, string $base, array $target): array
    {
        $now = CarbonImmutable::now();
        $stamp = $now->format('Y-m-d H:i:s');
        $host = is_string($target['host']) ? trim($target['host'], '[]') : '127.0.0.1';
        $port = is_int($target['port'] ?? null) ? $target['port'] : (($target['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $groupCount = min(self::MAX_GROUPS, max(1, intdiv($count, 20)));
        $topGroups = max(1, intdiv($groupCount, 5));

        $tagIds = $this->transaction(function () use ($workspace, $stamp): array {
            $this->insertChunked('tags', self::TAGS, static fn (int $i): array => [
                'workspace_id' => $workspace->id,
                'name' => sprintf('tag-%02d', $i + 1),
                'color' => '#059669',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            return $this->ids('tags', $workspace->id);
        });

        $channelIds = [];
        for ($i = 1; $i <= self::CHANNELS; $i++) {
            $channel = new NotificationChannel;
            $channel->forceFill([
                'workspace_id' => $workspace->id,
                'name' => "bench channel {$i}",
                'type' => ChannelType::Slack,
                'config' => [],
                'secrets' => ['webhook_url' => 'https://hooks.slack.invalid/services/bench'.$i],
                'is_default' => false,
                'active' => false,
            ])->save();
            $channelIds[] = (int) $channel->id;
        }

        $groupConfig = json_encode(MonitorType::Group->definition()->defaults(), JSON_THROW_ON_ERROR);
        $group = fn (int $i, ?int $parent): array => [
            'workspace_id' => $workspace->id,
            'name' => sprintf('group %03d', $i + 1),
            'type' => MonitorType::Group->value,
            'active' => true,
            'interval_seconds' => self::INTERVAL,
            'timeout_seconds' => 10,
            'retries' => 0,
            'retry_interval_seconds' => self::INTERVAL,
            'inverted' => false,
            'config' => $groupConfig,
            'parent_id' => $parent,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
        $groupIds = $this->transaction(function () use ($workspace, $groupCount, $topGroups, $group): array {
            $this->insertChunked('monitors', $topGroups, static fn (int $i): array => $group($i, null));
            $top = $this->ids('monitors', $workspace->id);
            $this->insertChunked('monitors', $groupCount - $topGroups, static fn (int $i): array => $group($topGroups + $i, $top[$i % count($top)]));

            return $this->ids('monitors', $workspace->id);
        });

        $configs = $this->configs($base, $host, $port);
        $this->transaction(function () use ($workspace, $count, $groupIds, $configs, $stamp): void {
            $this->insertChunked('monitors', $count, function (int $i) use ($workspace, $groupIds, $configs, $stamp): array {
                $type = self::typeOf($i);

                return [
                    'workspace_id' => $workspace->id,
                    'name' => sprintf('%s-%05d %s', self::NAME_PREFIXES[$i % count(self::NAME_PREFIXES)], $i + 1, $type->value),
                    'type' => $type->value,
                    'active' => false,
                    'interval_seconds' => $type === MonitorType::Push ? self::PUSH_INTERVAL : self::INTERVAL,
                    'timeout_seconds' => 5,
                    'retries' => 0,
                    'retry_interval_seconds' => self::INTERVAL,
                    'inverted' => false,
                    'config' => $configs[$type->value],
                    // Four in five monitors sit in a group.
                    'parent_id' => $i % 5 === 0 ? null : $groupIds[$i % count($groupIds)],
                    'push_token' => $type === MonitorType::Push ? Str::random(32) : null,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            });
        });
        $all = $this->ids('monitors', $workspace->id);
        $leafIds = array_values(array_diff($all, $groupIds));
        $leafCount = count($leafIds);

        $this->transaction(function () use ($all, $leafIds, $leafCount, $tagIds, $channelIds, $stamp): void {
            $this->insertChunked('monitor_states', count($all), static fn (int $i): array => [
                'monitor_id' => $all[$i],
                'status' => 'up',
                'status_since' => $stamp,
                'last_ping_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            // Every monitor carries one tag, every third a second one.
            $pairs = [];
            foreach ($leafIds as $i => $id) {
                $pairs[] = [$id, $tagIds[$i % self::TAGS]];
                if ($i % 3 === 0) {
                    $pairs[] = [$id, $tagIds[($i * 7 + 1) % self::TAGS]];
                }
            }
            $pairs = array_values(array_unique($pairs, SORT_REGULAR));
            $this->insertChunked('monitor_tag', count($pairs), static fn (int $i): array => ['monitor_id' => $pairs[$i][0], 'tag_id' => $pairs[$i][1]]);
            $this->insertChunked('monitor_notification', $leafCount, static fn (int $i): array => [
                'monitor_id' => $leafIds[$i],
                'notification_channel_id' => $channelIds[$i % count($channelIds)],
            ]);
        });

        $today = $now->startOfDay();
        $hour = intdiv($now->getTimestamp(), 3600) * 3600;
        // One transaction per chunk group keeps a SQLite writer from locking out the worker for long.
        for ($start = 0; $start < $leafCount && ! $this->interrupted; $start += 100) {
            $slice = array_slice($leafIds, $start, 100);
            $this->transaction(function () use ($slice, $today, $hour): void {
                $this->insertChunked('check_rollups', count($slice) * self::DAYS, static function (int $i) use ($slice, $today): array {
                    $up = 1430 - ($i % 7);

                    return [
                        'monitor_id' => $slice[intdiv($i, self::DAYS)],
                        'bucket' => CheckRollup::BUCKET_DAY,
                        'bucket_start' => $today->subDays($i % self::DAYS)->getTimestamp(),
                        'total' => 1440, 'up' => $up, 'down' => 1440 - $up,
                        'latency_min' => 40, 'latency_max' => 400, 'latency_avg' => 120,
                        'latency_sum' => 1440 * 120, 'latency_count' => 1440,
                    ];
                });
                $this->insertChunked('check_rollups', count($slice) * self::HOURS, static fn (int $i): array => [
                    'monitor_id' => $slice[intdiv($i, self::HOURS)],
                    'bucket' => CheckRollup::BUCKET_HOUR,
                    'bucket_start' => $hour - ($i % self::HOURS) * 3600,
                    'total' => 60, 'up' => 60,
                    'latency_min' => 40, 'latency_max' => 400, 'latency_avg' => 120,
                    'latency_sum' => 60 * 120, 'latency_count' => 60,
                ]);
            });
        }

        $slugs = $this->transaction(function () use ($workspace, $pages, $items, $leafIds, $leafCount, $stamp): array {
            $prefix = 'bench-scale-'.Str::lower(Str::random(6)).'-';
            $this->insertChunked('status_pages', $pages, static fn (int $i): array => [
                'workspace_id' => $workspace->id,
                'slug' => $prefix.($i + 1),
                'title' => 'Bench page '.($i + 1),
                'published' => true,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $pageIds = $this->ids('status_pages', $workspace->id);
            $this->insertChunked('status_page_sections', $pages, static fn (int $i): array => [
                'status_page_id' => $pageIds[$i],
                'name' => 'Services',
                'position' => 0,
            ]);
            $sections = [];
            foreach (array_chunk($pageIds, self::CHUNK) as $chunk) {
                foreach (DB::table('status_page_sections')->whereIn('status_page_id', $chunk)->get(['id', 'status_page_id']) as $row) {
                    $sections[self::int($row->status_page_id)] = self::int($row->id);
                }
            }
            $this->insertChunked('status_page_items', $pages * $items, static fn (int $i): array => [
                'status_page_section_id' => $sections[$pageIds[intdiv($i, $items)]],
                'monitor_id' => $leafIds[$i % $leafCount],
                'position' => $i % $items,
            ]);

            return array_map(static fn (int $i): string => $prefix.$i, range(1, $pages));
        });

        $this->transaction(fn () => $this->seedHistory($workspace, $leafIds, $now));

        return ['groups' => count($groupIds), 'slugs' => $slugs, 'tag' => $tagIds[0], 'group' => $groupIds[0]];
    }

    /**
     * Alerts, incidents, maintenances and audit events, so their lists have pages to read.
     *
     * @param  list<int>  $leafIds
     */
    private function seedHistory(Workspace $workspace, array $leafIds, CarbonImmutable $now): void
    {
        $leafCount = count($leafIds);
        $this->insertChunked('alert_events', self::ALERTS, static fn (int $i): array => [
            'workspace_id' => $workspace->id,
            'monitor_id' => $leafIds[$i % $leafCount],
            'kind' => $i % 2 === 0 ? 'down' : 'up',
            'status' => $i % 2 === 0 ? 'down' : 'up',
            'previous_status' => $i % 2 === 0 ? 'up' : 'down',
            'message' => $i % 2 === 0 ? 'Connection refused' : '',
            'duration_seconds' => $i % 2 === 0 ? null : 120,
            'created_at' => $now->subMinutes(self::ALERTS - $i)->format('Y-m-d H:i:s'),
        ]);
        $this->insertChunked('incidents', self::INCIDENTS, static fn (int $i): array => [
            'workspace_id' => $workspace->id,
            'title' => 'Bench incident '.($i + 1),
            'impact' => 'minor',
            'status' => 'resolved',
            'started_at' => $now->subHours(2 * (self::INCIDENTS - $i))->format('Y-m-d H:i:s'),
            'resolved_at' => $now->subHours(2 * (self::INCIDENTS - $i) - 1)->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        // Windows that ended in the past, so no monitor is in maintenance during the run.
        $this->insertChunked('maintenances', self::MAINTENANCES, static fn (int $i): array => [
            'workspace_id' => $workspace->id,
            'title' => 'Bench maintenance '.($i + 1),
            'strategy' => 'once',
            'starts_at' => $now->subDays(self::MAINTENANCES - $i + 1)->format('Y-m-d H:i:s'),
            'ends_at' => $now->subDays(self::MAINTENANCES - $i + 1)->addHour()->format('Y-m-d H:i:s'),
            'active' => true,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->insertChunked('audit_events', self::AUDIT_EVENTS, static fn (int $i): array => [
            'workspace_id' => $workspace->id,
            'actor_type' => 'system',
            'action' => 'monitor.updated',
            'subject_type' => 'monitor',
            'subject_id' => (string) $leafIds[$i % $leafCount],
            'changes' => '{"name":["before","after"]}',
            'created_at' => $now->subMinutes(self::AUDIT_EVENTS - $i)->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Checks that answer locally and fast: a static file of the measured application, its port, the loopback
     * address and the localhost name.
     *
     * @return array<string, string>
     */
    private function configs(string $base, string $host, int $port): array
    {
        $http = MonitorType::Http->definition()->defaults();
        $http['url'] = $base.'/robots.txt';
        $http['domain_expiry'] = false;
        $http['tls_checks'] = false;
        $dns = MonitorType::Dns->definition()->defaults();
        $dns['name'] = 'localhost';
        $dns['record_type'] = 'A';

        return array_map(static fn (array $config): string => json_encode($config, JSON_THROW_ON_ERROR), [
            MonitorType::Http->value => $http,
            MonitorType::Tcp->value => ['host' => $host, 'port' => $port],
            MonitorType::Ping->value => ['host' => '127.0.0.1', 'count' => 1],
            MonitorType::Dns->value => $dns,
            MonitorType::Push->value => MonitorType::Push->definition()->defaults(),
        ]);
    }

    /**
     * Six in ten monitors are http, the rest tcp, ping, dns and push.
     */
    private static function typeOf(int $i): MonitorType
    {
        return match ($i % 10) {
            6 => MonitorType::Tcp,
            7 => MonitorType::Ping,
            8 => MonitorType::Dns,
            9 => MonitorType::Push,
            default => MonitorType::Http,
        };
    }

    /**
     * @param  array{groups: int, slugs: list<string>, tag: int, group: int}  $seeded
     * @return array<string, string>
     */
    private function endpoints(array $seeded): array
    {
        return [
            'monitors top level' => '/monitors?parent_id=null&limit=100',
            'monitors search' => '/monitors?q='.self::SEARCH.'&limit=100',
            'monitors status' => '/monitors?status=up&limit=100',
            'monitors tag' => '/monitors?tag='.$seeded['tag'].'&limit=100',
            'group children' => '/monitors?parent_id='.$seeded['group'].'&limit=100',
            'alerts' => '/alerts?limit=100',
            'status pages' => '/status-pages?limit=100',
            'incidents' => '/incidents?limit=100',
            'maintenances' => '/maintenances?limit=100',
            'audit' => '/audit?limit=100',
        ];
    }

    /**
     * Requests the admin lists round-robin; returns each list's durations in milliseconds, sorted.
     *
     * @param  array<string, string>  $endpoints
     * @param  array<string, int>  $errors
     * @return array<string, list<float>>
     */
    private function adminPass(PendingRequest $api, array $endpoints, int $requests, array &$errors): array
    {
        $names = array_keys($endpoints);
        $durations = array_fill_keys($names, []);
        for ($i = 0; $i < $requests && ! $this->interrupted; $i++) {
            $name = $names[$i % count($names)];
            $durations[$name][] = $this->timed(fn () => $api->get(ltrim($endpoints[$name], '/')), $name, $errors);
        }
        foreach ($durations as &$list) {
            sort($list);
        }

        return $durations;
    }

    /**
     * Requests the status pages round-robin, each from a client address of its own so the public rate limit
     * stays out of the measurement (the application must trust the proxy headers of the benchmark's address).
     * Headers merge into a pending request, so each request starts from a copy.
     *
     * @param  list<string>  $slugs
     * @param  array<string, int>  $errors
     * @return list<float>
     */
    private function statusPass(PendingRequest $web, array $slugs, int $requests, int $offset, string $label, array &$errors): array
    {
        $durations = [];
        for ($i = 0; $i < $requests && ! $this->interrupted; $i++) {
            $n = $offset + $i;
            $address = '10.'.(($n >> 16) & 255).'.'.(($n >> 8) & 255).'.'.($n & 255);
            $durations[] = $this->timed(fn () => (clone $web)->withHeaders(['X-Forwarded-For' => $address])->get('status/'.$slugs[$i % count($slugs)]), $label, $errors);
        }
        sort($durations);

        return $durations;
    }

    /**
     * @param  callable(): Response  $request
     * @param  array<string, int>  $errors
     */
    private function timed(callable $request, string $label, array &$errors): float
    {
        $started = hrtime(true);
        try {
            $response = $request();
            if (! $response->successful()) {
                $key = $label.' '.$response->status();
                $errors[$key] = ($errors[$key] ?? 0) + 1;
            }
        } catch (ConnectionException) {
            $errors[$label.' connection'] = ($errors[$label.' connection'] ?? 0) + 1;
        }

        return (hrtime(true) - $started) / 1_000_000;
    }

    /**
     * @param  array<string, list<float>>  $admin
     * @param  list<float>  $cold
     * @param  list<float>  $warm
     * @param  array<string, int>  $errors
     * @param  array{groups: int, slugs: list<string>, tag: int, group: int}  $seeded
     */
    private function report(Workspace $workspace, array $admin, array $cold, array $warm, array $errors, array $seeded, int $monitors, int $pages, int $items, int $checks, float $elapsed): int
    {
        $rows = [];
        $adminSummary = [];
        $adminP95 = [];
        foreach ($admin as $name => $durations) {
            $rows[] = $this->row('admin', $name, $durations);
            $adminSummary[$name] = $this->stats($durations);
            $adminP95[$name] = $this->percentile($durations, 0.95);
        }
        $rows[] = $this->row('status', 'cold', $cold);
        $rows[] = $this->row('status', 'warm', $warm);
        $this->table(['kind', 'endpoint', 'requests', 'p50 (ms)', 'p95 (ms)', 'max (ms)'], $rows);

        $this->line('checks recorded while measuring: '.$checks.' in '.number_format($elapsed, 1, '.', '').' s');

        $failures = self::verdict($adminP95, $this->percentile($warm, 0.95), array_sum($errors));
        foreach ($failures as $failure) {
            $this->error($failure);
        }
        if (($errors['status cold 429'] ?? 0) + ($errors['status warm 429'] ?? 0) > 0) {
            $this->error('Status pages answered 429: the public rate limit counted every request against one address. '
                .'Is TRUSTED_PROXIES=127.0.0.1 set on the app? Set it on the benchmark container only, never in production.');
        }

        $this->line(json_encode([
            'seeded' => ['monitors' => $monitors, 'groups' => $seeded['groups'], 'pages' => $pages, 'items' => $items],
            'admin' => $adminSummary,
            'status' => ['cold' => $this->stats($cold), 'warm' => $this->stats($warm)],
            'errors' => array_sum($errors),
            'failed_requests' => $errors === [] ? new \stdClass : $errors,
            'checks_while_measuring' => $checks,
            'measure_seconds' => round($elapsed, 1),
            'monitor_states' => $this->statuses($workspace),
            'passed' => $failures === [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Why a run failed: an admin list or the warm status pages too slow at the 95th percentile, or requests
     * that did not succeed.
     *
     * @param  array<string, float>  $adminP95Ms
     * @return list<string>
     */
    public static function verdict(array $adminP95Ms, float $warmStatusP95Ms, int $errors): array
    {
        $failures = [];
        foreach ($adminP95Ms as $name => $p95) {
            if ($p95 >= self::MAX_ADMIN_P95_MS) {
                $failures[] = "Admin list \"{$name}\" p95 ".number_format($p95, 1, '.', '').' ms is not below '.self::MAX_ADMIN_P95_MS.' ms.';
            }
        }
        if ($warmStatusP95Ms >= self::MAX_WARM_STATUS_P95_MS) {
            $failures[] = 'Warm status page p95 '.number_format($warmStatusP95Ms, 1, '.', '').' ms is not below '.self::MAX_WARM_STATUS_P95_MS.' ms.';
        }
        if ($errors > 0) {
            $failures[] = "{$errors} requests did not succeed.";
        }

        return $failures;
    }

    /**
     * @param  list<float>  $sorted
     * @return list<string>
     */
    private function row(string $kind, string $name, array $sorted): array
    {
        $stats = $this->stats($sorted);

        return [$kind, $name, (string) $stats['requests'], $this->format($stats['p50']), $this->format($stats['p95']), $this->format($stats['max'])];
    }

    /**
     * @param  list<float>  $sorted
     * @return array{requests: int, p50: float, p95: float, max: float}
     */
    private function stats(array $sorted): array
    {
        return [
            'requests' => count($sorted),
            'p50' => round($this->percentile($sorted, 0.50), 2),
            'p95' => round($this->percentile($sorted, 0.95), 2),
            'max' => round($sorted === [] ? 0.0 : $sorted[count($sorted) - 1], 2),
        ];
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        return $sorted === [] ? 0.0 : $sorted[max(0, (int) ceil($p * count($sorted)) - 1)];
    }

    private function format(float $ms): string
    {
        return number_format($ms, 2, '.', '');
    }

    /**
     * How many of the workspace's monitors are in each state at the end of the run.
     *
     * @return array<string, int>
     */
    private function statuses(Workspace $workspace): array
    {
        $statuses = [];
        foreach (DB::table('monitors')->join('monitor_states', 'monitor_states.monitor_id', '=', 'monitors.id')
            ->where('monitors.workspace_id', $workspace->id)->where('monitors.type', '!=', MonitorType::Group->value)
            ->groupBy('monitor_states.status')->selectRaw('monitor_states.status as status, count(*) as total')->get() as $row) {
            $statuses[is_string($row->status) ? $row->status : ''] = self::int($row->total);
        }
        ksort($statuses);

        return $statuses;
    }

    private function checkCount(Workspace $workspace): int
    {
        return DB::table('check_results')
            ->join('monitors', 'monitors.id', '=', 'check_results.monitor_id')
            ->where('monitors.workspace_id', $workspace->id)
            ->count();
    }

    /**
     * @return list<int> Ids of the workspace's rows in the table, ascending.
     */
    private function ids(string $table, int $workspaceId): array
    {
        return array_values(array_map(self::int(...), DB::table($table)->where('workspace_id', $workspaceId)->orderBy('id')->pluck('id')->all()));
    }

    /**
     * @param  callable(int): array<string, mixed>  $row
     */
    private function insertChunked(string $table, int $count, callable $row): void
    {
        for ($start = 0; $start < $count && ! $this->interrupted; $start += self::CHUNK) {
            $rows = [];
            for ($i = $start, $end = min($count, $start + self::CHUNK); $i < $end; $i++) {
                $rows[] = $row($i);
            }
            DB::table($table)->insert($rows);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return DB::transaction(static fn () => $callback());
    }

    private function sleep(int $seconds): void
    {
        $until = microtime(true) + $seconds;
        while (! $this->interrupted && microtime(true) < $until) {
            usleep(200_000);
        }
    }

    /**
     * Deletes everything the run seeded; returns whether that succeeded.
     */
    private function cleanUp(Workspace $workspace, ?User $user): bool
    {
        try {
            $ids = DB::table('monitors')->where('workspace_id', $workspace->id)->pluck('id')->all();
            // Deactivated first, so the worker stops checking before the rows go.
            DB::table('monitors')->where('workspace_id', $workspace->id)->update(['active' => false]);
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::transaction(static function () use ($chunk): void {
                    DB::table('check_rollups')->whereIn('monitor_id', $chunk)->delete();
                    DB::table('check_results')->whereIn('monitor_id', $chunk)->delete();
                    DB::table('monitor_events')->whereIn('monitor_id', $chunk)->delete();
                });
            }
            DB::transaction(function () use ($workspace, $user, $ids): void {
                DB::table('alert_events')->where('workspace_id', $workspace->id)->delete();
                DB::table('audit_events')->where('workspace_id', $workspace->id)->delete();
                DB::table('status_pages')->where('workspace_id', $workspace->id)->delete();
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    DB::table('monitors')->whereIn('id', $chunk)->update(['parent_id' => null]);
                }
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    DB::table('monitors')->whereIn('id', $chunk)->delete();
                }
                $workspace->delete();
                $user?->tokens()->delete();
                $user?->delete();
            });
            $this->line("Deleted workspace {$workspace->slug}.");

            return true;
        } catch (Throwable $e) {
            $this->error("Could not delete workspace {$workspace->slug}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Ends the run on SIGINT or SIGTERM at the next safe point, so that the clean-up always runs.
     */
    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->interrupted = true;
            });
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : null;
    }
}
