<?php

namespace App\Alerting\Transport;

final class Redactor
{
    private const MIN_SECRET_LENGTH = 6;

    /**
     * Masks secret values (raw, URL-encoded and JSON-escaped) and strips userinfo, query and fragment from URLs.
     * For URL-valued secrets the path and each path and query segment are masked too, since a provider error
     * may echo only part of the URL.
     *
     * @param  array<array-key, mixed>  $secrets  Secret values; nested arrays are searched recursively.
     */
    public static function redact(string $text, array $secrets): string
    {
        $values = [];
        self::collect($secrets, $values);

        /** @var array<string, bool> $needles Needle => match case-insensitively. */
        $needles = [];
        foreach (array_unique($values) as $value) {
            foreach (self::variants($value) as $variant) {
                $needles[$variant] = false;
                foreach ([rawurlencode($variant), urlencode($variant)] as $encoded) {
                    $needles[$encoded] ??= true;
                }
            }
        }
        uksort($needles, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($needles as $needle => $insensitive) {
            $text = $insensitive ? str_ireplace((string) $needle, '***', $text) : str_replace((string) $needle, '***', $text);
        }

        return (string) preg_replace_callback(
            '#\bhttps?://[^\s\'"<>]+#i',
            fn (array $m): string => self::stripUrl($m[0]),
            $text,
        );
    }

    /**
     * The value, its JSON-escaped form and, for a URL, its path and path and query segments.
     *
     * @return list<string>
     */
    private static function variants(string $value): array
    {
        $parts = [$value];
        $url = parse_url($value);
        if (is_array($url) && isset($url['scheme'], $url['host'])) {
            $path = $url['path'] ?? '';
            $parts[] = $path;
            $segments = array_merge(
                explode('/', $path),
                preg_split('/[\/&=?;]/', $url['query'] ?? '') ?: [],
            );
            array_push($parts, ...$segments);
        }

        $variants = [];
        foreach ($parts as $part) {
            if (strlen($part) < self::MIN_SECRET_LENGTH) {
                continue;
            }
            $variants[] = $part;
            $json = json_encode($part, JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $variants[] = substr($json, 1, -1);
            }
        }

        return $variants;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $out
     */
    private static function collect(array $values, array &$out): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                self::collect($value, $out);
            } elseif (is_string($value) && strlen($value) >= self::MIN_SECRET_LENGTH) {
                $out[] = $value;
            }
        }
    }

    private static function stripUrl(string $url): string
    {
        $url = (string) preg_replace('#^(https?://)[^/?\#@]*@#i', '$1', $url);

        return (string) preg_replace('#[?\#].*$#s', '', $url);
    }
}
