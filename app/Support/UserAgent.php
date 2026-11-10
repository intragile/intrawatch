<?php

namespace App\Support;

/**
 * Picks the browser and operating system out of a User-Agent header, so a person can recognise their
 * own devices in the session list. It knows the common browsers and systems and nothing more; anything
 * else comes back as null and the raw header is shown instead.
 */
final class UserAgent
{
    /**
     * Checked in order: several browsers also name the ones they are built on.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Vivaldi/' => 'Vivaldi',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /**
     * @var array<string, string>
     */
    private const PLATFORMS = [
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'CrOS' => 'ChromeOS',
        'Macintosh' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * @return array{browser: string|null, platform: string|null}
     */
    public static function parse(?string $userAgent): array
    {
        $userAgent ??= '';

        return [
            'browser' => self::first(self::BROWSERS, $userAgent),
            'platform' => self::first(self::PLATFORMS, $userAgent),
        ];
    }

    /**
     * @param  array<string, string>  $needles
     */
    private static function first(array $needles, string $haystack): ?string
    {
        foreach ($needles as $needle => $name) {
            if (str_contains($haystack, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
