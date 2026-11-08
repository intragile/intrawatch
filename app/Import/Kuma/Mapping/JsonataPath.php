<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * Translates the subset of JSONata that is a plain path into JSONPath: field names (identifiers or
 * backtick-quoted), dots and non-negative array indexes, optionally starting at the context (`$`) or the
 * root (`$$`). Anything else (functions, filters, wildcards, operators, variables) has no faithful
 * JSONPath equivalent and is refused.
 */
final class JsonataPath
{
    private const IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';

    private const QUOTED = '`([^`]*)`';

    public static function toJsonPath(string $expr): ?string
    {
        $expr = trim($expr);
        if ($expr === '' || strlen($expr) > 1024) {
            return null;
        }

        // `$` is the context and `$$` the root; for a monitor both are the response document.
        if (preg_match('/^\$\$?(\.|$)/', $expr, $m) === 1) {
            $expr = substr($expr, strlen($m[0]));
            if ($expr === '') {
                return $m[1] === '' ? '$' : null;
            }
        }

        $segment = '/\G(?:('.self::IDENTIFIER.')|'.self::QUOTED.')((?:\[\d+\])*)(\.|$)/';
        $path = '$';
        $offset = 0;
        $length = strlen($expr);
        while ($offset < $length) {
            if (preg_match($segment, $expr, $m, 0, $offset) !== 1) {
                return null;
            }
            $offset += strlen($m[0]);
            if ($m[4] === '.' && $offset === $length) {
                return null;
            }

            if ($m[1] !== '') {
                $path .= '.'.$m[1];
            } else {
                if ($m[2] === '') {
                    return null;
                }
                $path .= "['".addcslashes($m[2], "'\\")."']";
            }
            $path .= $m[3];
        }

        return $path;
    }
}
