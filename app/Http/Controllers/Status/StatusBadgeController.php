<?php

namespace App\Http\Controllers\Status;

use App\Enums\DisplayStatus;
use App\Models\StatusPage;
use App\Status\Pages\Badge;
use App\Status\Pages\BadgeData;
use App\Status\Pages\CachedPage;
use App\Status\Pages\PageView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Status, uptime and latency badges of a public status page and its components. The numbers come from the
 * cached badge data of the page; only the SVG is drawn per request.
 */
final class StatusBadgeController
{
    private const DEFAULT_DAYS = 30;

    public function __construct(private readonly PublicPageGateway $gateway) {}

    public function overall(Request $request, ?string $slug = null): Response
    {
        return $this->serve($request, $slug, static function (array $data, Request $request): string {
            $status = DisplayStatus::tryFrom(self::string($data['status'] ?? '')) ?? DisplayStatus::Unknown;

            return Badge::render(
                self::label($request, mb_substr(self::string($data['title'] ?? ''), 0, Badge::MAX_LABEL)),
                Badge::statusMessage($status),
                Badge::statusColor($status),
                self::style($request),
            );
        });
    }

    public function monitor(Request $request): Response
    {
        // Read by name: the custom-domain route has no slug, and route parameters would bind by position.
        $monitor = self::string($request->route('monitor'));
        $kind = self::string($request->route('kind'));
        $slug = $request->route('slug');

        return $this->serve($request, is_string($slug) ? $slug : null, static function (array $data, Request $request) use ($monitor, $kind): string {
            $monitors = is_array($data['monitors'] ?? null) ? $data['monitors'] : [];
            $entry = $monitors[$monitor] ?? null;
            if (! is_array($entry)) {
                throw new NotFoundHttpException;
            }
            $style = self::style($request);

            if ($kind === 'status') {
                $status = DisplayStatus::tryFrom(self::string($entry['status'] ?? '')) ?? DisplayStatus::Unknown;

                return Badge::render(self::label($request, 'status'), Badge::statusMessage($status), Badge::statusColor($status), $style);
            }
            if ($kind === 'uptime') {
                $days = self::days($request);
                $uptime = is_array($entry['uptime'] ?? null) ? $entry['uptime'] : [];
                $percent = $uptime[(string) $days] ?? null;
                $label = self::label($request, 'uptime '.$days.'d');

                return is_int($percent) || is_float($percent)
                    ? Badge::render($label, Badge::uptimeMessage((float) $percent), Badge::uptimeColor((float) $percent), $style)
                    : Badge::render($label, 'n/a', Badge::GREY, $style);
            }
            $latency = $entry['latency'] ?? null;
            $label = self::label($request, 'latency');

            return is_int($latency)
                ? Badge::render($label, $latency.' ms', '#007ec6', $style)
                : Badge::render($label, 'n/a', Badge::GREY, $style);
        });
    }

    /**
     * @param  callable(array<string, mixed>, Request): string  $draw
     */
    private function serve(Request $request, ?string $slug, callable $draw): Response
    {
        return $this->gateway->serve(
            $request,
            $slug,
            'badges',
            static fn (StatusPage $page, PageView $view): string => BadgeData::encode($view),
            static function (CachedPage $cached) use ($request, $draw): Response {
                $data = json_decode($cached->html, true);
                if (! is_array($data)) {
                    throw new NotFoundHttpException;
                }
                /** @var array<string, mixed> $data */
                $svg = $draw($data, $request);

                return PublicPageGateway::body($request, $svg, '"'.hash('sha256', $svg).'"', [
                    'Content-Type' => 'image/svg+xml',
                    'Cache-Control' => 'public, max-age=60',
                ], $cached->stale);
            },
        );
    }

    private static function label(Request $request, string $default): string
    {
        $label = $request->query('label');
        if (! is_string($label)) {
            return $default;
        }
        $label = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $label) ?? '');

        return $label === '' || mb_strlen($label) > Badge::MAX_LABEL ? $default : $label;
    }

    private static function days(Request $request): int
    {
        $days = $request->query('days');

        return is_string($days) && in_array($days, array_map('strval', Badge::UPTIME_DAYS), true) ? (int) $days : self::DEFAULT_DAYS;
    }

    private static function style(Request $request): string
    {
        $style = $request->query('style');

        return is_string($style) && in_array($style, Badge::STYLES, true) ? $style : 'flat';
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
