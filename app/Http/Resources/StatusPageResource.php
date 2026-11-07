<?php

namespace App\Http\Resources;

use App\Models\StatusPage;

/**
 * The JSON shape of a status page; sections and items are included when the sections are loaded.
 */
final class StatusPageResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(StatusPage $page): array
    {
        $data = [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'custom_domain' => $page->custom_domain,
            'published' => $page->published,
            'locale' => $page->locale,
            'accent_color' => $page->accent_color,
            'logo_url' => $page->logo_url,
            'favicon_url' => $page->favicon_url,
            'custom_css' => $page->custom_css,
            'footer' => $page->footer,
            'bar_days' => $page->bar_days,
            'uptime_days' => $page->uptime_days,
            'upcoming_maintenance_days' => $page->upcoming_maintenance_days,
            'incident_history_days' => $page->incident_history_days,
            'show_latency' => $page->show_latency,
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];

        if ($page->relationLoaded('sections')) {
            $data['sections'] = $page->sections->map(static fn ($section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'position' => $section->position,
                'items' => $section->items->map(static fn ($item): array => [
                    'id' => $item->id,
                    'monitor_id' => $item->monitor_id,
                    'display_name' => $item->display_name,
                    'description' => $item->description,
                    'position' => $item->position,
                    'expand_children' => $item->expand_children,
                    'show_uptime' => $item->show_uptime,
                ])->values()->all(),
            ])->values()->all();
        }

        return $data;
    }
}
