<?php

namespace App\Monitoring\Types;

/**
 * Syntax-only validation of a JSONPath expression (root `$`, child and descendant segments, wildcards,
 * indexes, slices, unions and filters). It guarantees that the path is well formed, not that it matches.
 */
final class JsonPathSyntax
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_-]*';

    private const INT = '-?\d+';

    private const QUOTED = "'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"";

    public static function isValid(string $path): bool
    {
        if ($path === '' || $path[0] !== '$' || strlen($path) > 1024) {
            return false;
        }

        $length = strlen($path);
        $i = 1;
        while ($i < $length) {
            if ($path[$i] === '.') {
                $descendant = ($path[$i + 1] ?? '') === '.';
                $i += $descendant ? 2 : 1;

                if (($path[$i] ?? '') === '[' && $descendant) {
                    continue;
                }
                if (preg_match('/\G(?:'.self::NAME.'|\*)/', $path, $m, 0, $i) !== 1) {
                    return false;
                }
                $i += strlen($m[0]);

                continue;
            }

            if ($path[$i] === '[') {
                $end = self::closingBracket($path, $i);
                if ($end === null || ! self::isValidSelector(substr($path, $i + 1, $end - $i - 1))) {
                    return false;
                }
                $i = $end + 1;

                continue;
            }

            return false;
        }

        return true;
    }

    private static function isValidSelector(string $selector): bool
    {
        $selector = trim($selector);
        if ($selector === '' || $selector === '*') {
            return $selector === '*';
        }

        if (str_starts_with($selector, '?')) {
            return preg_match('/^\?\s*\(.+\)$/s', $selector) === 1 && self::isBalanced($selector);
        }

        foreach (self::splitUnion($selector) as $part) {
            $part = trim($part);
            $ok = preg_match('/^(?:'.self::INT.'|'.self::QUOTED.'|(?:'.self::INT.')?:(?:'.self::INT.')?(?::(?:'.self::INT.')?)?)$/', $part) === 1;
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function splitUnion(string $selector): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        foreach (str_split($selector) as $char) {
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote && ! str_ends_with(substr($current, 0, -1), '\\')) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            }
            if ($char === ',' && $quote === null) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Position of the `]` that closes the `[` at $open, skipping quoted strings and nested brackets.
     */
    private static function closingBracket(string $path, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($path);
        for ($i = $open; $i < $length; $i++) {
            $char = $path[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private static function isBalanced(string $expression): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0 && $quote === null;
    }
}
