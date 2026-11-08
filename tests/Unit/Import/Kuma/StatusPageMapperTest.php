<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Mapping\MappedStatusPage;
use App\Import\Kuma\Mapping\StatusPageMapContext;
use App\Import\Kuma\Mapping\StatusPageMapper;
use Closure;
use Tests\TestCase;

final class StatusPageMapperTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 1, 'slug' => 'public', 'title' => 'Public', 'description' => 'desc', 'icon' => '/icon.svg',
            'theme' => 'auto', 'published' => 1, 'search_engine_index' => 1, 'show_tags' => 0, 'footer_text' => 'foot',
            'custom_css' => 'body{}', 'show_powered_by' => 1, 'show_certificate_expiry' => 0,
            'auto_refresh_interval' => 300, 'analytics_id' => null, 'analytics_type' => null,
            'analytics_script_url' => null, 'google_analytics_tag_id' => null, 'password' => null, 'rss_title' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $groups
     * @param  array<int, list<array<string, mixed>>>  $items
     * @param  list<string>  $taken
     * @param  (Closure(int): ?int)|null  $resolve
     * @param  list<string>  $domains
     */
    private function map(array $row, array $groups = [], array $items = [], array $taken = [], ?Closure $resolve = null, array $domains = []): MappedStatusPage
    {
        $context = new StatusPageMapContext(
            groups: [1 => $groups],
            items: $items,
            domains: [1 => $domains],
            slugTaken: fn (string $slug): bool => in_array($slug, $taken, true),
            resolveMonitor: $resolve ?? fn (int $id): int => $id + 100,
        );

        return (new StatusPageMapper)->map($row, $context);
    }

    /**
     * @param  list<string>  $codes
     */
    private function assertNotes(array $codes, MappedStatusPage $mapped): void
    {
        $this->assertSame($codes, array_map(fn ($note): string => $note->code, $mapped->notes));
    }

    public function test_plain_page_maps_its_fields(): void
    {
        $mapped = $this->map(self::row(['icon' => 'https://cdn.example.com/logo.svg']));

        $this->assertSame([
            'slug' => 'public', 'title' => 'Public', 'description' => 'desc', 'footer' => 'foot', 'custom_css' => 'body{}',
            'published' => true, 'locale' => 'en', 'logo_url' => 'https://cdn.example.com/logo.svg',
        ], $mapped->page);
        $this->assertNotes([], $mapped);
    }

    public function test_relative_and_plain_http_icons_are_not_imported(): void
    {
        foreach (['/icon.svg', 'http://example.com/logo.png'] as $icon) {
            $mapped = $this->map(self::row(['icon' => $icon]));

            $this->assertArrayNotHasKey('logo_url', $mapped->page);
            $this->assertNotes(['logo_not_imported'], $mapped);
        }
    }

    public function test_non_default_settings_are_listed_once_without_values(): void
    {
        $mapped = $this->map(self::row(['theme' => 'dark', 'show_tags' => 1, 'show_powered_by' => 0, 'analytics_id' => 'G-SECRET-1', 'password' => 'hunter2', 'auto_refresh_interval' => 60]), []);

        $this->assertNotes(['logo_not_imported', 'settings_ignored'], $mapped);
        $message = $mapped->notes[1]->message;
        foreach (['theme', 'show_tags', 'show_powered_by', 'analytics', 'password', 'auto_refresh_interval'] as $setting) {
            $this->assertStringContainsString($setting, $message);
        }
        $this->assertStringNotContainsString('hunter2', $message);
        $this->assertStringNotContainsString('G-SECRET-1', $message);
        $this->assertStringNotContainsString('show_certificate_expiry', $message);
    }

    public function test_slug_collision_gets_suffix(): void
    {
        $mapped = $this->map(self::row(), taken: ['public', 'public-2']);

        $this->assertSame('public-3', $mapped->page['slug']);
        $this->assertContains('slug_renamed', array_map(fn ($n): string => $n->code, $mapped->notes));
    }

    public function test_suffix_keeps_the_slug_within_64_characters(): void
    {
        $long = str_repeat('a', 64);
        $mapped = $this->map(self::row(['slug' => $long]), taken: [$long]);

        $this->assertSame(str_repeat('a', 62).'-2', $mapped->page['slug']);
        $this->assertSame(64, strlen((string) $mapped->page['slug']));
    }

    public function test_reserved_and_unusable_slugs_are_renamed(): void
    {
        $this->assertSame('status-2', $this->map(self::row(['slug' => 'status']))->page['slug']);
        $this->assertSame('my-page', $this->map(self::row(['slug' => 'My_Page']))->page['slug']);
        $this->assertSame('a-status', $this->map(self::row(['slug' => 'a']))->page['slug']);
    }

    public function test_layout_orders_groups_and_items_by_weight_and_resolves_monitors(): void
    {
        $groups = [
            ['id' => 2, 'name' => 'Infra', 'public' => 1, 'weight' => 2],
            ['id' => 1, 'name' => 'Web', 'public' => 1, 'weight' => 1],
        ];
        $items = [
            1 => [['id' => 2, 'monitor_id' => 3, 'weight' => 2, 'send_url' => 0, 'custom_url' => null], ['id' => 1, 'monitor_id' => 2, 'weight' => 1, 'send_url' => 0, 'custom_url' => null]],
            2 => [['id' => 3, 'monitor_id' => 7, 'weight' => 1, 'send_url' => 0, 'custom_url' => null]],
        ];

        $mapped = $this->map(self::row(), $groups, $items);

        $this->assertSame([
            ['name' => 'Web', 'description' => null, 'items' => [['monitor_id' => 102], ['monitor_id' => 103]]],
            ['name' => 'Infra', 'description' => null, 'items' => [['monitor_id' => 107]]],
        ], $mapped->layout['sections']);
    }

    public function test_items_reference_only_imported_monitors(): void
    {
        $groups = [['id' => 1, 'name' => 'Web', 'public' => 1, 'weight' => 1]];
        $items = [1 => [
            ['id' => 1, 'monitor_id' => 2, 'weight' => 1, 'send_url' => 0, 'custom_url' => null],
            ['id' => 2, 'monitor_id' => 99, 'weight' => 2, 'send_url' => 0, 'custom_url' => null],
        ]];

        $mapped = $this->map(self::row(), $groups, $items, resolve: fn (int $id): ?int => $id === 2 ? 22 : null);

        $this->assertSame([['monitor_id' => 22]], $mapped->layout['sections'][0]['items']);
        $this->assertContains('monitors_not_imported', array_map(fn ($n): string => $n->code, $mapped->notes));
    }

    public function test_urls_duplicates_and_private_groups_are_noted(): void
    {
        $groups = [
            ['id' => 1, 'name' => 'A', 'public' => 1, 'weight' => 1],
            ['id' => 2, 'name' => 'B', 'public' => 1, 'weight' => 2],
            ['id' => 3, 'name' => 'Hidden', 'public' => 0, 'weight' => 3],
        ];
        $items = [
            1 => [['id' => 1, 'monitor_id' => 2, 'weight' => 1, 'send_url' => 1, 'custom_url' => null]],
            2 => [['id' => 2, 'monitor_id' => 2, 'weight' => 1, 'send_url' => 0, 'custom_url' => 'https://x.example.com']],
        ];

        $mapped = $this->map(self::row(['icon' => null]), $groups, $items);

        $this->assertCount(2, $mapped->layout['sections']);
        $this->assertSame([], $mapped->layout['sections'][1]['items']);
        $this->assertNotes(['private_groups_skipped', 'duplicate_items', 'item_urls_ignored'], $mapped);
    }

    public function test_layout_is_truncated_at_the_limits(): void
    {
        $groups = [];
        for ($g = 1; $g <= 52; $g++) {
            $groups[] = ['id' => $g, 'name' => "G{$g}", 'public' => 1, 'weight' => $g];
        }
        $items = [1 => []];
        for ($m = 1; $m <= 260; $m++) {
            $items[1][] = ['id' => $m, 'monitor_id' => $m, 'weight' => $m, 'send_url' => 0, 'custom_url' => null];
        }

        $mapped = $this->map(self::row(['icon' => null]), $groups, $items);

        $this->assertCount(50, $mapped->layout['sections']);
        $this->assertCount(250, $mapped->layout['sections'][0]['items']);
        $this->assertNotes(['sections_truncated', 'items_truncated'], $mapped);
    }

    public function test_domains_are_passed_through_in_order(): void
    {
        $mapped = $this->map(self::row(['icon' => null]), domains: ['a.example.com', 'b.example.com']);

        $this->assertSame(['a.example.com', 'b.example.com'], $mapped->domains);
    }
}
