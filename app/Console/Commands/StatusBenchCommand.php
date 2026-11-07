<?php

namespace App\Console\Commands;

use App\Listeners\StampStatusPages;
use App\Models\CheckRollup;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

#[Signature('intrawatch:bench:status {--pages=300} {--items=20} {--requests=3000} {--keep : Keep the seeded workspace} {--force : Run even though the database holds other workspaces}')]
#[Description('Measure public status page latency and database queries, cold and served from the page cache')]
class StatusBenchCommand extends Command
{
    /**
     * The warm 95th percentile at which a run fails, in milliseconds.
     */
    private const MAX_WARM_P95_MS = 100.0;

    private const MAX_ITEMS = 250;

    private const CHUNK = 500;

    private const HISTORY_DAYS = 90;

    private const HOURS = 24;

    /** Paths of a page requested in the warm pass, besides the page itself. */
    private const ENDPOINTS = ['', '/feed.atom', '/feed.rss', '/summary.json', '/badge.svg'];

    private bool $interrupted = false;

    public function handle(): int
    {
        $pages = $this->positiveOption('pages');
        $items = $this->positiveOption('items');
        $requests = $this->positiveOption('requests');
        if ($pages === null || $items === null || $requests === null) {
            $this->error('--pages, --items and --requests must be positive whole numbers.');

            return self::INVALID;
        }
        if ($items > self::MAX_ITEMS) {
            $this->error('--items must not exceed '.self::MAX_ITEMS.', the most a status page holds.');

            return self::INVALID;
        }
        if (! $this->option('force') && Workspace::query()->exists()) {
            $this->error('The database already holds workspaces. Run the benchmark against an empty database, or pass --force.');

            return self::FAILURE;
        }

        $this->trapSignals();
        // The benchmark renders into a cache directory of its own, so it never touches rendered pages of the
        // installation it runs next to, and starts cold.
        $cachePath = sys_get_temp_dir().'/intrawatch-bench-status-'.getmypid();
        File::deleteDirectory($cachePath);
        $previous = [config('cache.stores.status_pages.path'), config('cache.stores.status_pages.lock_path')];
        config(['cache.stores.status_pages.path' => $cachePath, 'cache.stores.status_pages.lock_path' => $cachePath.'/locks']);
        Cache::forgetDriver('status_pages');
        StampStatusPages::reset();

        $name = 'Status benchmark '.Str::lower(Str::random(8));
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => Workspace::uniqueSlug($name), 'timezone' => 'UTC']);
        $original = $this->laravel->bound('request') ? $this->laravel->make('request') : null;

        try {
            $slugs = $this->seed($workspace, $pages, $items);
            if ($this->wasInterrupted()) {
                $this->warn('Interrupted; no results are reported.');

                return self::FAILURE;
            }
            $this->line("Seeded {$pages} pages with {$items} components each, ".self::HISTORY_DAYS.' days of rollups, in workspace '.$workspace->slug.'.');

            $kernel = $this->laravel->make(Kernel::class);
            $failed = 0;
            $cold = $this->pass($kernel, $slugs, count($slugs), 0, $failed, false);
            // Renders the feeds, summary and badge of every page once, so the warm pass starts from a full cache.
            $this->prime($kernel, $slugs, $failed);
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $warm = $this->pass($kernel, $slugs, $requests, 0, $failed, true);
            if ($this->wasInterrupted()) {
                $this->warn('Interrupted; no results are reported.');

                return self::FAILURE;
            }

            return $this->report($cold, $warm, $queries, $failed);
        } finally {
            if ($original !== null) {
                $this->laravel->instance('request', $original);
            }
            if ($this->option('keep')) {
                $this->line("Kept workspace {$workspace->slug}.");
            } else {
                $this->cleanUp($workspace);
            }
            File::deleteDirectory($cachePath);
            config(['cache.stores.status_pages.path' => $previous[0], 'cache.stores.status_pages.lock_path' => $previous[1]]);
            Cache::forgetDriver('status_pages');
        }
    }

    /**
     * Seeds pages, components and rollups with direct inserts; returns the page slugs.
     *
     * @return list<string>
     */
    private function seed(Workspace $workspace, int $pages, int $items): array
    {
        $now = CarbonImmutable::now();
        $stamp = $now->format('Y-m-d H:i:s');
        $total = $pages * $items;
        $prefix = 'bench-'.Str::lower(Str::random(6)).'-';
        $config = json_encode(['url' => 'http://127.0.0.1/'], JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($workspace, $pages, $items, $total, $now, $stamp, $prefix, $config): void {
            $this->insertChunked('monitors', $total, static fn (int $i): array => [
                'workspace_id' => $workspace->id,
                'name' => 'bench '.($i + 1),
                'type' => 'http',
                'active' => false,
                'interval_seconds' => 60,
                'timeout_seconds' => 10,
                'retries' => 0,
                'retry_interval_seconds' => 60,
                'inverted' => false,
                'config' => $config,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            /** @var list<int> $monitorIds */
            $monitorIds = array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
                DB::table('monitors')->where('workspace_id', $workspace->id)->orderBy('id')->pluck('id')->all(),
            );

            $this->insertChunked('monitor_states', $total, static fn (int $i): array => [
                'monitor_id' => $monitorIds[$i],
                'status' => 'up',
                'status_since' => $stamp,
                'updated_at' => $stamp,
            ]);

            $this->insertChunked('status_pages', $pages, static fn (int $i): array => [
                'workspace_id' => $workspace->id,
                'slug' => $prefix.($i + 1),
                'title' => 'Bench page '.($i + 1),
                'published' => true,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            /** @var array<string, int> $pageIds */
            $pageIds = [];
            foreach (DB::table('status_pages')->where('workspace_id', $workspace->id)->orderBy('id')->get(['id', 'slug']) as $row) {
                $pageIds[is_string($row->slug) ? $row->slug : ''] = is_numeric($row->id) ? (int) $row->id : 0;
            }
            $ids = array_values($pageIds);

            $this->insertChunked('status_page_sections', $pages, static fn (int $i): array => [
                'status_page_id' => $ids[$i],
                'name' => 'Services',
                'position' => 0,
            ]);
            $sectionIds = [];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach (DB::table('status_page_sections')->whereIn('status_page_id', $chunk)->get(['id', 'status_page_id']) as $row) {
                    $sectionIds[is_numeric($row->status_page_id) ? (int) $row->status_page_id : 0] = is_numeric($row->id) ? (int) $row->id : 0;
                }
            }

            $this->insertChunked('status_page_items', $total, static fn (int $i): array => [
                'status_page_section_id' => $sectionIds[$ids[intdiv($i, $items)]],
                'monitor_id' => $monitorIds[$i],
                'position' => $i % $items,
            ]);

            $today = $now->startOfDay();
            $this->insertChunked('check_rollups', $total * self::HISTORY_DAYS, static function (int $i) use ($monitorIds, $today): array {
                $day = $i % self::HISTORY_DAYS;
                $up = 1430 - ($i % 7);

                return [
                    'monitor_id' => $monitorIds[intdiv($i, self::HISTORY_DAYS)],
                    'bucket' => CheckRollup::BUCKET_DAY,
                    'bucket_start' => $today->subDays($day)->getTimestamp(),
                    'total' => 1440, 'up' => $up, 'down' => 1440 - $up,
                ];
            });
            $hour = intdiv($now->getTimestamp(), 3600) * 3600;
            $this->insertChunked('check_rollups', $total * self::HOURS, static fn (int $i): array => [
                'monitor_id' => $monitorIds[intdiv($i, self::HOURS)],
                'bucket' => CheckRollup::BUCKET_HOUR,
                'bucket_start' => $hour - ($i % self::HOURS) * 3600,
                'total' => 60, 'up' => 60, 'latency_sum' => 60 * 120, 'latency_count' => 60,
            ]);
        });

        return array_map(static fn (int $i): string => $prefix.$i, range(1, $pages));
    }

    /**
     * @param  callable(int): array<string, mixed>  $row
     */
    private function insertChunked(string $table, int $count, callable $row): void
    {
        for ($start = 0; $start < $count && ! $this->wasInterrupted(); $start += self::CHUNK) {
            $rows = [];
            for ($i = $start, $end = min($count, $start + self::CHUNK); $i < $end; $i++) {
                $rows[] = $row($i);
            }
            DB::table($table)->insert($rows);
        }
    }

    /**
     * One pass of in-process requests, round-robin over the pages; returns the durations in milliseconds, sorted.
     *
     * @param  list<string>  $slugs
     * @return list<float>
     */
    private function pass(Kernel $kernel, array $slugs, int $requests, int $offset, int &$failed, bool $mixed): array
    {
        $durations = [];
        $count = count($slugs);
        for ($i = 0; $i < $requests && ! $this->wasInterrupted(); $i++) {
            $n = $offset + $i;
            // The warm pass cycles through the page and its machine-readable endpoints.
            $path = $mixed ? self::ENDPOINTS[($n + intdiv($n, $count)) % count(self::ENDPOINTS)] : '';
            $started = hrtime(true);
            $response = $this->send($kernel, '/status/'.$slugs[$n % $count].$path, $n);
            $durations[] = (hrtime(true) - $started) / 1_000_000;
            if ($response !== 200) {
                $failed++;
            }
        }
        sort($durations);

        return $durations;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function prime(Kernel $kernel, array $slugs, int &$failed): void
    {
        $n = 1_000_000;
        foreach ($slugs as $slug) {
            foreach (array_slice(self::ENDPOINTS, 1) as $path) {
                if ($this->send($kernel, '/status/'.$slug.$path, $n++) !== 200) {
                    $failed++;
                }
            }
        }
    }

    /**
     * One in-process request from a client address of its own, so the public rate limit stays out of the
     * measurement; returns the status code.
     */
    private function send(Kernel $kernel, string $path, int $n): int
    {
        $request = Request::create($path, 'GET', server: [
            'REMOTE_ADDR' => '10.'.(($n >> 16) & 255).'.'.(($n >> 8) & 255).'.'.($n & 255),
        ]);
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $kernel->terminate($request, $response);

        return $status;
    }

    /**
     * @param  list<float>  $cold
     * @param  list<float>  $warm
     */
    private function report(array $cold, array $warm, int $warmQueries, int $failed): int
    {
        $this->table(['pass', 'requests', 'p50 (ms)', 'p95 (ms)', 'max (ms)'], [$this->row('cold', $cold), $this->row('warm', $warm)]);
        $this->line("warm queries: {$warmQueries}");

        $failures = self::verdict($this->percentile($warm, 0.95), $warmQueries, $failed);
        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<float>  $sorted
     * @return list<string>
     */
    private function row(string $name, array $sorted): array
    {
        return [
            $name,
            (string) count($sorted),
            $this->format($this->percentile($sorted, 0.50)),
            $this->format($this->percentile($sorted, 0.95)),
            $this->format($sorted === [] ? 0.0 : $sorted[count($sorted) - 1]),
        ];
    }

    private function wasInterrupted(): bool
    {
        return $this->interrupted;
    }

    /**
     * Why a run failed: a slow warm tail, a warm request that queried the database, or a page that did not
     * answer 200.
     *
     * @return list<string>
     */
    public static function verdict(float $warmP95Ms, int $warmQueries, int $failed): array
    {
        $failures = [];
        if ($warmP95Ms >= self::MAX_WARM_P95_MS) {
            $failures[] = 'Warm p95 '.number_format($warmP95Ms, 1, '.', '').' ms is not below '.self::MAX_WARM_P95_MS.' ms.';
        }
        if ($warmQueries > 0) {
            $failures[] = "Warm requests ran {$warmQueries} database queries; there must be none.";
        }
        if ($failed > 0) {
            $failures[] = "{$failed} requests did not answer 200.";
        }

        return $failures;
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

    private function cleanUp(Workspace $workspace): void
    {
        try {
            DB::transaction(function () use ($workspace): void {
                $ids = DB::table('monitors')->where('workspace_id', $workspace->id)->pluck('id')->all();
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    DB::table('check_rollups')->whereIn('monitor_id', $chunk)->delete();
                }
                DB::table('status_pages')->where('workspace_id', $workspace->id)->delete();
                $workspace->delete();
            });
            $this->line("Deleted workspace {$workspace->slug}.");
        } catch (Throwable $e) {
            $this->error("Could not delete workspace {$workspace->slug}: ".$e->getMessage());
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

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : null;
    }
}
