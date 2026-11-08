<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Actions\Monitors\CreateMonitor;
use App\Enums\ImportOutcome;
use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Mapping\Mapped;
use App\Import\Kuma\Mapping\MonitorMapContext;
use App\Import\Kuma\Mapping\MonitorMapper;
use App\Import\Kuma\Mapping\Note;
use App\Models\Monitor;
use App\Monitoring\Types\GroupHierarchy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports Kuma monitors, parents before children, each in its own transaction. Tags and channels resolve
 * through the mappings of the earlier steps; channels are bound exactly as in Kuma, without the workspace's
 * default channels.
 */
final class ImportMonitors implements ImportStep
{
    public const ENTITY = 'monitor';

    /** Push tokens IntraWatch accepts in its push URLs and stores (48 characters at most). */
    private const PUSH_TOKEN = '/^[A-Za-z0-9]{1,48}$/';

    private const DEFAULT_EXPIRY_DAYS = [7, 14, 21];

    /**
     * Group ids from the top level down to the monitor itself, by Kuma id, for every monitor that exists (or
     * would exist, in dry-run) after its row was processed.
     *
     * @var array<int, list<int>>
     */
    private array $chains = [];

    /** @var array<int, list<int>> the same chains, by the id of the group they end in */
    private array $chainsByTarget = [];

    /** @var array<int, bool> whether an imported channel still exists, by channel id */
    private array $channelExists = [];

    public function __construct(
        private readonly MonitorMapper $mapper,
        private readonly CreateMonitor $create,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $this->chains = [];
        $this->chainsByTarget = [];
        $this->channelExists = [];
        $rows = $this->ordered($ctx->source->monitors());
        $mapContext = $this->mapContext($ctx);
        $hasDefaults = $ctx->workspace->notificationChannels()->where('is_default', true)->where('active', true)->exists();

        foreach ($rows as $sourceId => $row) {
            $label = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

            try {
                $this->import($ctx, $mapContext, $sourceId, $row, $hasDefaults);
            } catch (Throwable $e) {
                report($e);
                Log::warning('Kuma monitor import failed', ['source_id' => $sourceId, 'exception' => $e::class]);
                $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Failed, null, [
                    Note::of('unexpected_error', 'The monitor could not be imported; see the application log.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(ImportContext $ctx, MonitorMapContext $mapContext, int $sourceId, array $row, bool $hasDefaults): void
    {
        $label = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';

        $mapping = $ctx->mappings->find(self::ENTITY, $sourceId);
        if ($mapping !== null) {
            $stillThere = Monitor::query()->forWorkspace($ctx->workspace)->whereKey($mapping->target_id)->exists();
            if ($stillThere) {
                $this->remember($sourceId, $this->chainFromDatabase($mapping->target_id));
            }
            $note = $stillThere
                ? Note::of('already_imported', 'The monitor was imported earlier; it was left unchanged.')
                : Note::of('deleted_locally', 'The monitor was imported earlier and has since been deleted; it was not recreated.');
            $ctx->report->add(self::ENTITY, $sourceId, $label, ImportOutcome::Existing, $mapping->target_id, [$note]);

            return;
        }

        $mapped = $this->mapper->map($row, $mapContext);
        if (! $mapped instanceof Mapped) {
            $outcome = $mapped->errors === [] ? ImportOutcome::Skipped : ImportOutcome::Invalid;
            $ctx->report->add(self::ENTITY, $sourceId, $label, $outcome, null, [$mapped->reason], $mapped->errors);

            return;
        }

        $input = $mapped->attributes;
        if ($ctx->options->paused) {
            $input['active'] = false;
        }
        $name = is_string($input['name'] ?? null) && $input['name'] !== '' ? $input['name'] : $label;
        $notes = $mapped->notes;
        if ($hasDefaults && ($input['notification_channel_ids'] ?? []) === []) {
            $notes[] = Note::of('default_channels_not_applied', "The monitor has no notifications in Kuma, so the workspace's default channels were not bound.");
        }

        try {
            $validated = $this->create->validate($ctx->workspace, $ctx->options->dryRun ? $this->withoutPlaceholders($input) : $input);
        } catch (ValidationException $e) {
            $this->invalid($ctx, $sourceId, $name, $e);

            return;
        }
        if ($ctx->options->dryRun) {
            // References to entities that would be created are placeholders; validation saw them removed.
            $validated['parent_id'] = is_int($input['parent_id'] ?? null) ? $input['parent_id'] : null;
        }

        if ($input['type'] === 'push') {
            [$token, $tokenNote] = $this->pushToken($row);
            $validated['push_token'] = $token;
            if ($tokenNote !== null) {
                $notes[] = $tokenNote;
            }
        }

        $parentChain = $validated['parent_id'] !== null ? $this->chainOf($validated['parent_id']) : [];

        if ($ctx->options->dryRun) {
            $ctx->mappings->record(self::ENTITY, $sourceId, new Monitor);
            $placeholder = $ctx->mappings->targetId(self::ENTITY, $sourceId) ?? 0;
            $this->remember($sourceId, [...$parentChain, $placeholder]);
            $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::WouldCreate, null, $notes);

            return;
        }

        try {
            $monitor = $this->create->handle($ctx->workspace, $validated, $ctx->actor, function (Monitor $monitor) use ($ctx, $sourceId): void {
                $ctx->mappings->record(self::ENTITY, $sourceId, $monitor);
            });
        } catch (ValidationException $e) {
            $this->invalid($ctx, $sourceId, $name, $e);

            return;
        }

        $this->remember($sourceId, [...$parentChain, $monitor->id]);
        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Created, $monitor->id, $notes);
    }

    private function mapContext(ImportContext $ctx): MonitorMapContext
    {
        $proxies = [];
        foreach ($ctx->source->proxies() as $proxy) {
            if (is_numeric($proxy['id'] ?? null)) {
                $proxies[(int) $proxy['id']] = $proxy;
            }
        }

        $tags = [];
        foreach ($ctx->source->monitorTags() as $link) {
            if (is_numeric($link['monitor_id'] ?? null)) {
                $tags[(int) $link['monitor_id']][] = $link;
            }
        }

        $channels = [];
        foreach ($ctx->source->monitorNotifications() as $link) {
            $channels[$link['monitor_id']][] = $link['notification_id'];
        }

        return new MonitorMapContext(
            proxies: $proxies,
            tlsExpiryDays: $this->expiryDays($ctx->source->setting('tlsExpiryNotifyDays')),
            monitorTags: $tags,
            monitorNotifications: $channels,
            resolveTag: fn (int $id): ?int => $ctx->mappings->targetId(ImportTags::ENTITY, $id),
            resolveChannel: fn (int $id): ?int => $this->channelId($ctx, $id),
            resolveParent: $this->resolveParent(...),
        );
    }

    /**
     * Only channels the import created or found; a mapping whose channel was deleted locally binds nothing.
     */
    private function channelId(ImportContext $ctx, int $notificationId): ?int
    {
        $id = $ctx->mappings->targetId(ImportNotifications::ENTITY, $notificationId);
        if ($id === null || $id < 0) {
            return $id;
        }

        $this->channelExists[$id] ??= $ctx->workspace->notificationChannels()->whereKey($id)->exists();

        return $this->channelExists[$id] ? $id : null;
    }

    /**
     * The group a child of the given Kuma parent attaches to. A parent that does not exist puts the child at
     * the top level; a parent at the depth limit makes it attach to the deepest ancestor that still has room.
     *
     * @return array{0: ?int, 1: ?Note}
     */
    private function resolveParent(int $kumaParentId): array
    {
        $chain = $this->chains[$kumaParentId] ?? null;
        if ($chain === null) {
            return [null, Note::of('parent_not_imported', 'The parent group of the monitor was not imported; the monitor was placed at the top level.')];
        }

        if (count($chain) >= GroupHierarchy::MAX_DEPTH) {
            return [$chain[GroupHierarchy::MAX_DEPTH - 2], Note::of(
                'depth_capped',
                'Groups nest at most '.GroupHierarchy::MAX_DEPTH.' levels deep in IntraWatch; the monitor was attached to the deepest group that still has room.',
            )];
        }

        return [$chain[count($chain) - 1], null];
    }

    /**
     * @param  list<int>  $chain
     */
    private function remember(int $sourceId, array $chain): void
    {
        $this->chains[$sourceId] = $chain;
        $this->chainsByTarget[$chain[count($chain) - 1]] = $chain;
    }

    /**
     * The chain that ends in a group resolved during this run.
     *
     * @return list<int>
     */
    private function chainOf(int $targetId): array
    {
        return $this->chainsByTarget[$targetId] ?? [$targetId];
    }

    /**
     * Group ids from the top level down to the monitor, read from the monitors as they are now.
     *
     * @return list<int>
     */
    private function chainFromDatabase(int $monitorId): array
    {
        $chain = [$monitorId];
        $parentId = Monitor::query()->whereKey($monitorId)->value('parent_id');
        while (is_numeric($parentId) && count($chain) <= GroupHierarchy::MAX_DEPTH) {
            array_unshift($chain, (int) $parentId);
            $parentId = Monitor::query()->whereKey((int) $parentId)->value('parent_id');
        }

        return $chain;
    }

    /**
     * Rows keyed by Kuma id, every parent before its children. A parent that is not among the rows, or a
     * cycle, leaves the child where it is; the parent resolution then places it at the top level.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function ordered(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $byId[(int) $row['id']] = $row;
            }
        }

        $ordered = [];
        $visiting = [];
        $visit = function (int $id) use (&$visit, &$ordered, &$visiting, $byId): void {
            if (isset($ordered[$id]) || isset($visiting[$id])) {
                return;
            }
            $visiting[$id] = true;
            $parent = $byId[$id]['parent'] ?? null;
            if (is_numeric($parent) && isset($byId[(int) $parent])) {
                $visit((int) $parent);
            }
            unset($visiting[$id]);
            $ordered[$id] = $byId[$id];
        };
        foreach (array_keys($byId) as $id) {
            $visit($id);
        }

        return $ordered;
    }

    /**
     * The Kuma token when IntraWatch can serve it and no other monitor holds it; a fresh one otherwise.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: ?Note}
     */
    private function pushToken(array $row): array
    {
        $token = is_scalar($row['push_token'] ?? null) ? (string) $row['push_token'] : '';
        if ($token === '') {
            return [Str::random(48), Note::of('push_token_generated', 'The push monitor had no token; a new one was generated, so its push URL changes.')];
        }
        if (preg_match(self::PUSH_TOKEN, $token) !== 1) {
            return [Str::random(48), Note::of('push_token_regenerated', 'The push token contains characters IntraWatch does not accept in push URLs; a new token was generated, so the push URL changes.')];
        }
        if (Monitor::query()->where('push_token', $token)->exists()) {
            return [Str::random(48), Note::of('push_token_collision', 'Another monitor already uses this push token; a new token was generated, so the push URL changes.')];
        }

        return [$token, null];
    }

    /**
     * In dry-run, tags, channels and parents that would be created have negative placeholder ids. They are
     * left out of validation, which would otherwise look them up.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withoutPlaceholders(array $input): array
    {
        if (is_array($input['tags'] ?? null)) {
            $input['tags'] = array_values(array_filter($input['tags'], fn (mixed $tag): bool => ! is_array($tag) || ! is_int($tag['id'] ?? null) || $tag['id'] > 0));
        }
        if (is_array($input['notification_channel_ids'] ?? null)) {
            $input['notification_channel_ids'] = array_values(array_filter($input['notification_channel_ids'], fn (mixed $id): bool => ! is_int($id) || $id > 0));
        }
        if (is_int($input['parent_id'] ?? null) && $input['parent_id'] < 0) {
            $input['parent_id'] = null;
        }

        return $input;
    }

    /**
     * @return list<int>
     */
    private function expiryDays(mixed $setting): array
    {
        $days = [];
        if (is_array($setting)) {
            foreach ($setting as $day) {
                if (is_numeric($day) && (int) $day >= 1 && (int) $day <= 3650) {
                    $days[] = (int) $day;
                }
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? self::DEFAULT_EXPIRY_DAYS : array_slice($days, 0, 10);
    }

    private function invalid(ImportContext $ctx, int $sourceId, string $name, ValidationException $e): void
    {
        $errors = [];
        foreach ($e->validator->errors()->messages() as $field => $messages) {
            $errors[(string) $field] = array_values($messages);
        }

        $ctx->report->add(self::ENTITY, $sourceId, $name, ImportOutcome::Invalid, null, [
            Note::of('monitor_invalid', 'The monitor did not pass IntraWatch validation.'),
        ], $errors);
    }
}
