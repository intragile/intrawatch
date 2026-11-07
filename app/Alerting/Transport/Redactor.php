<?php

namespace App\Alerting\Transport;

final class Redactor
{
    private const MIN_SECRET_LENGTH = 6;

    /**
     * Masks secret values (raw and URL-encoded) and strips userinfo, query and fragment from URLs.
     *
     * @param  array<array-key, mixed>  $secrets  Secret values; nested arrays are searched recursively.
     */
    public static function redact(string $text, array $secrets): string
    {
        $needles = [];
        self::collect($secrets, $needles);
        usort($needles, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach (array_unique($needles) as $needle) {
            $text = str_replace($needle, '***', $text);
        }

        return (string) preg_replace_callback(
            '#\bhttps?://[^\s\'"<>]+#i',
            fn (array $m): string => self::stripUrl($m[0]),
            $text,
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $needles
     */
    private static function collect(array $values, array &$needles): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                self::collect($value, $needles);
            } elseif (is_string($value) && strlen($value) >= self::MIN_SECRET_LENGTH) {
                array_push($needles, $value, rawurlencode($value), urlencode($value));
            }
        }
    }

    private static function stripUrl(string $url): string
    {
        $url = (string) preg_replace('#^(https?://)[^/?\#@]*@#i', '$1', $url);

        return (string) preg_replace('#[?\#].*$#s', '', $url);
    }
}
