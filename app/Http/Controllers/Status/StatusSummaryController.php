<?php

namespace App\Http\Controllers\Status;

use App\Models\StatusPage;
use App\Status\Pages\CachedPage;
use App\Status\Pages\ItemView;
use App\Status\Pages\PageView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A compact JSON summary of a public status page for embedding in other sites.
 */
final class StatusSummaryController
{
    public function __construct(private readonly PublicPageGateway $gateway) {}

    public function show(Request $request, ?string $slug = null): Response
    {
        return $this->gateway->serve(
            $request,
            $slug,
            'summary',
            static fn (StatusPage $page, PageView $view): string => self::encode($page, $view),
            static fn (CachedPage $cached): Response => PublicPageGateway::body($request, $cached->html, $cached->etag, [
                'Content-Type' => 'application/json',
                'Cache-Control' => PublicPageGateway::CACHE_CONTROL,
                'Access-Control-Allow-Origin' => '*',
            ], $cached->stale),
        );
    }

    private static function encode(StatusPage $page, PageView $view): string
    {
        $url = $page->publicUrl();
        $components = [];
        foreach ($view->sections as $section) {
            foreach ($section->items as $item) {
                $components[] = self::component($item);
            }
        }

        return json_encode([
            'page' => [
                'title' => $view->title,
                'url' => $url,
                'status' => $view->overall->value,
                'updated_at' => $view->generatedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'components' => $components,
            'incidents' => array_map(static fn ($incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'impact' => $incident->impact->value,
                'status' => $incident->status->value,
                'url' => $url.'#incident-'.$incident->id,
            ], $view->openIncidents),
            'maintenances' => array_map(static fn ($maintenance): array => [
                'id' => $maintenance->id,
                'title' => $maintenance->title,
                'starts_at' => $maintenance->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $maintenance->endsAt?->utc()->format('Y-m-d\TH:i:s\Z'),
            ], [...$view->activeMaintenance, ...$view->upcomingMaintenance]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{id: int, name: string, status: string, children?: list<array<string, mixed>>}
     */
    private static function component(ItemView $item): array
    {
        $component = ['id' => $item->monitorId, 'name' => $item->name, 'status' => $item->status->value];
        if ($item->children !== []) {
            $component['children'] = array_map(self::component(...), $item->children);
        }

        return $component;
    }
}
