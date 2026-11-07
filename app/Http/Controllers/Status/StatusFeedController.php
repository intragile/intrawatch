<?php

namespace App\Http\Controllers\Status;

use App\Models\StatusPage;
use App\Status\Pages\CachedPage;
use App\Status\Pages\FeedBuilder;
use App\Status\Pages\PageView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Atom and RSS feeds of a public status page.
 */
final class StatusFeedController
{
    public function __construct(private readonly PublicPageGateway $gateway) {}

    public function atom(Request $request, ?string $slug = null): Response
    {
        return $this->feed($request, $slug, 'atom', 'application/atom+xml', FeedBuilder::atom(...));
    }

    public function rss(Request $request, ?string $slug = null): Response
    {
        return $this->feed($request, $slug, 'rss', 'application/rss+xml', FeedBuilder::rss(...));
    }

    /**
     * @param  callable(StatusPage, PageView): string  $build
     */
    private function feed(Request $request, ?string $slug, string $variant, string $type, callable $build): Response
    {
        return $this->gateway->serve(
            $request,
            $slug,
            $variant,
            static fn (StatusPage $page, PageView $view): string => $build($page, $view),
            static fn (CachedPage $cached): Response => PublicPageGateway::body($request, $cached->html, $cached->etag, [
                'Content-Type' => $type.'; charset=utf-8',
                'Cache-Control' => PublicPageGateway::CACHE_CONTROL,
            ], $cached->stale),
        );
    }
}
