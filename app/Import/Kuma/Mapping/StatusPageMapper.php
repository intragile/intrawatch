<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

use App\Http\Requests\StatusPageLayoutRequest;
use App\Http\Requests\StatusPageRequest;
use App\Import\Kuma\Source\Cast;
use Closure;

/**
 * Translates a Kuma `status_page` row with its groups and members. Theme, analytics, password and the other
 * display switches have no counterpart; the non-default ones are listed in a note.
 */
final class StatusPageMapper
{
    private const MAX_SLUG = 64;

    private const MIN_SLUG = 3;

    /**
     * Kuma settings with no IntraWatch counterpart and the value Kuma gives a new page.
     *
     * @var array<string, scalar>
     */
    private const IGNORED_DEFAULTS = [
        'theme' => 'auto',
        'show_tags' => 0,
        'show_powered_by' => 1,
        'show_certificate_expiry' => 0,
        'auto_refresh_interval' => 300,
        'search_engine_index' => 1,
        'show_only_last_heartbeat' => 0,
    ];

    /**
     * Columns whose mere presence is a setting that is ignored, by the name reported; their values (a
     * password, an analytics id) are never reported.
     *
     * @var array<string, list<string>>
     */
    private const IGNORED_PRESENT = [
        'analytics' => ['analytics_id', 'analytics_script_url', 'google_analytics_tag_id'],
        'rss_title' => ['rss_title'],
        'password' => ['password'],
    ];

    /**
     * @param  array<string, mixed>  $row  A Kuma `status_page` row.
     */
    public function map(array $row, StatusPageMapContext $ctx): MappedStatusPage
    {
        $sourceId = Cast::int($row['id'] ?? null);
        $notes = [];

        $title = trim(Cast::string($row['title'] ?? null));
        [$slug, $slugNote] = $this->slug(Cast::string($row['slug'] ?? null), $ctx->slugTaken);
        if ($slugNote !== null) {
            $notes[] = $slugNote;
        }

        $page = [
            'slug' => $slug,
            'title' => $title,
            'description' => $this->text($row['description'] ?? null),
            'footer' => $this->text($row['footer_text'] ?? null),
            'custom_css' => $this->text($row['custom_css'] ?? null),
            'published' => true,
            'locale' => 'en',
        ];

        $icon = trim(Cast::string($row['icon'] ?? null));
        if ($icon !== '') {
            if (preg_match('#^https://#i', $icon) === 1) {
                $page['logo_url'] = $icon;
            } else {
                $notes[] = Note::of('logo_not_imported', 'The page icon is not an absolute https URL, so it was not imported as the logo.');
            }
        }

        $ignored = $this->ignoredSettings($row);
        if ($ignored !== []) {
            $notes[] = Note::of('settings_ignored', 'These Kuma settings have no IntraWatch equivalent and were ignored: '.implode(', ', $ignored).'.');
        }

        [$sections, $layoutNotes] = $this->layout($ctx->groups[$sourceId] ?? [], $ctx);

        return new MappedStatusPage($page, ['sections' => $sections], $ctx->domains[$sourceId] ?? [], [...$notes, ...$layoutNotes]);
    }

    /**
     * Lower-case letters, digits and hyphens, 3 to 64 characters. A slug that had to change, is reserved or is
     * in use gets the first free `-2`, `-3`, ... suffix.
     *
     * @param  Closure(string): bool  $taken
     * @return array{0: string, 1: ?Note}
     */
    private function slug(string $source, Closure $taken): array
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($source)), '-');
        $base = substr($base, 0, self::MAX_SLUG);
        $base = trim($base, '-');
        if (strlen($base) < self::MIN_SLUG) {
            $base = substr($base === '' ? 'status' : $base.'-status', 0, self::MAX_SLUG);
        }

        $slug = $base;
        for ($n = 2; $this->unavailable($slug, $taken); $n++) {
            $suffix = '-'.$n;
            $slug = rtrim(substr($base, 0, self::MAX_SLUG - strlen($suffix)), '-').$suffix;
        }

        if ($slug === $source) {
            return [$slug, null];
        }

        return [$slug, Note::of('slug_renamed', "The slug '{$source}' could not be used as it was; the page was imported as '{$slug}'.")];
    }

    /**
     * @param  Closure(string): bool  $taken
     */
    private function unavailable(string $slug, Closure $taken): bool
    {
        return in_array($slug, StatusPageRequest::RESERVED_SLUGS, true)
            || preg_match(StatusPageRequest::SLUG_PATTERN, $slug) !== 1
            || $taken($slug);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array{0: list<array{name: string, description: null, items: list<array{monitor_id: int}>}>, 1: list<Note>}
     */
    private function layout(array $groups, StatusPageMapContext $ctx): array
    {
        $notes = [];
        $private = 0;
        $public = [];
        foreach ($groups as $group) {
            if (Cast::int($group['public'] ?? 0) === 1) {
                $public[] = $group;
            } else {
                $private++;
            }
        }
        usort($public, fn (array $a, array $b): int => [Cast::int($a['weight'] ?? 0), Cast::int($a['id'] ?? 0)] <=> [Cast::int($b['weight'] ?? 0), Cast::int($b['id'] ?? 0)]);
        if ($private > 0) {
            $notes[] = Note::of('private_groups_skipped', "{$private} non-public group(s) were not imported.");
        }

        $sections = [];
        $totalItems = 0;
        $seen = [];
        $missing = 0;
        $urls = 0;
        $duplicates = 0;
        $itemsDropped = 0;
        foreach ($public as $position => $group) {
            if ($position >= StatusPageLayoutRequest::MAX_SECTIONS) {
                $notes[] = Note::of('sections_truncated', 'A page holds at most '.StatusPageLayoutRequest::MAX_SECTIONS.' sections; the remaining groups were not imported.');
                break;
            }

            $members = $ctx->items[Cast::int($group['id'] ?? null)] ?? [];
            usort($members, fn (array $a, array $b): int => [Cast::int($a['weight'] ?? 0), Cast::int($a['id'] ?? 0)] <=> [Cast::int($b['weight'] ?? 0), Cast::int($b['id'] ?? 0)]);

            $items = [];
            foreach ($members as $member) {
                $monitorId = ($ctx->resolveMonitor)(Cast::int($member['monitor_id'] ?? null));
                if ($monitorId === null) {
                    $missing++;

                    continue;
                }
                if (isset($seen[$monitorId])) {
                    $duplicates++;

                    continue;
                }
                if ($totalItems >= StatusPageLayoutRequest::MAX_ITEMS) {
                    $itemsDropped++;

                    continue;
                }
                $seen[$monitorId] = true;
                $totalItems++;
                if (Cast::int($member['send_url'] ?? 0) === 1 || trim(Cast::string($member['custom_url'] ?? null)) !== '') {
                    $urls++;
                }
                $items[] = ['monitor_id' => $monitorId];
            }

            $sections[] = ['name' => trim(Cast::string($group['name'] ?? null)), 'description' => null, 'items' => $items];
        }

        if ($missing > 0) {
            $notes[] = Note::of('monitors_not_imported', "{$missing} monitor(s) of the page were not imported and are missing from its layout.");
        }
        if ($duplicates > 0) {
            $notes[] = Note::of('duplicate_items', "{$duplicates} monitor(s) appeared in more than one group; IntraWatch shows each monitor once per page.");
        }
        if ($itemsDropped > 0) {
            $notes[] = Note::of('items_truncated', 'A page holds at most '.StatusPageLayoutRequest::MAX_ITEMS." monitors; {$itemsDropped} further monitor(s) were not imported.");
        }
        if ($urls > 0) {
            $notes[] = Note::of('item_urls_ignored', "{$urls} monitor(s) linked to their URL on the Kuma page; IntraWatch does not link components.");
        }

        return [$sections, $notes];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function ignoredSettings(array $row): array
    {
        $ignored = [];
        foreach (self::IGNORED_DEFAULTS as $column => $default) {
            if (array_key_exists($column, $row) && $row[$column] !== null && Cast::string($row[$column]) !== (string) $default) {
                $ignored[] = $column;
            }
        }
        foreach (self::IGNORED_PRESENT as $name => $columns) {
            foreach ($columns as $column) {
                if (trim(Cast::string($row[$column] ?? null)) !== '') {
                    $ignored[] = $name;

                    break;
                }
            }
        }

        return $ignored;
    }

    private function text(mixed $value): ?string
    {
        $text = Cast::string($value);

        return trim($text) === '' ? null : $text;
    }
}
