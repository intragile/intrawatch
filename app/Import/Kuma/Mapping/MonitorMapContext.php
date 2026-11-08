<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

use Closure;

/**
 * What the monitor mapper needs beyond the row itself: the instance's proxies and TLS expiry setting, the
 * monitor's tag and notification links, and how Kuma ids of tags, notifications and parents resolve to
 * IntraWatch ids. Without resolvers, ids map to themselves.
 */
final readonly class MonitorMapContext
{
    /** @var Closure(int): ?int */
    private Closure $resolveTag;

    /** @var Closure(int): ?int */
    private Closure $resolveChannel;

    /** @var Closure(int): array{0: ?int, 1: ?Note} */
    private Closure $resolveParent;

    /**
     * @param  array<int, array<string, mixed>>  $proxies  Kuma `proxy` rows by id.
     * @param  list<int>  $tlsExpiryDays  The instance-wide `tlsExpiryNotifyDays`.
     * @param  array<int, list<array<string, mixed>>>  $monitorTags  Kuma `monitor_tag` rows by monitor id.
     * @param  array<int, list<int>>  $monitorNotifications  Kuma notification ids by monitor id.
     * @param  (Closure(int): ?int)|null  $resolveTag  Kuma tag id to tag id; null when the tag was not imported.
     * @param  (Closure(int): ?int)|null  $resolveChannel  Kuma notification id to channel id; null when not imported.
     * @param  (Closure(int): array{0: ?int, 1: ?Note})|null  $resolveParent  Kuma parent id to the group to attach
     *                                                                        to (null for the top level), with a note when it differs from the source.
     */
    public function __construct(
        private array $proxies = [],
        public array $tlsExpiryDays = [7, 14, 21],
        private array $monitorTags = [],
        private array $monitorNotifications = [],
        ?Closure $resolveTag = null,
        ?Closure $resolveChannel = null,
        ?Closure $resolveParent = null,
    ) {
        $this->resolveTag = $resolveTag ?? static fn (int $id): int => $id;
        $this->resolveChannel = $resolveChannel ?? static fn (int $id): int => $id;
        $this->resolveParent = $resolveParent ?? static fn (int $id): array => [$id, null];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function proxy(int $id): ?array
    {
        return $this->proxies[$id] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tagsOf(int $monitorId): array
    {
        return $this->monitorTags[$monitorId] ?? [];
    }

    /**
     * @return list<int>
     */
    public function notificationsOf(int $monitorId): array
    {
        return $this->monitorNotifications[$monitorId] ?? [];
    }

    public function tagId(int $kumaTagId): ?int
    {
        return ($this->resolveTag)($kumaTagId);
    }

    public function channelId(int $kumaNotificationId): ?int
    {
        return ($this->resolveChannel)($kumaNotificationId);
    }

    /**
     * @return array{0: ?int, 1: ?Note}
     */
    public function parent(int $kumaParentId): array
    {
        return ($this->resolveParent)($kumaParentId);
    }
}
