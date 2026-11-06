<?php

namespace App\Monitoring\Checks\Http;

use App\Monitoring\Types\Definitions\HttpDefinition;
use App\Monitoring\Types\JsonPath;
use Throwable;

/**
 * Evaluates the keyword, JSON query and header assertions of an http monitor against a received response.
 *
 * Failure messages name the failed assertion only. The JSON query message repeats the configured path and
 * expected value and up to 100 characters of the selected value; it never contains the rest of the body or
 * any header value.
 */
final class ResponseAssertions
{
    private const ACTUAL_LENGTH = 100;

    /**
     * The first failed assertion, in the order keyword, JSON query, headers; null when all pass.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, list<string>>  $headers  Response headers keyed by lowercase name.
     */
    public static function failure(array $config, string $body, array $headers): ?string
    {
        return self::keyword($config['keyword'] ?? null, $body)
            ?? self::jsonQuery($config['json_query'] ?? null, $body)
            ?? self::headers($config['header_checks'] ?? null, $headers);
    }

    private static function keyword(mixed $keyword, string $body): ?string
    {
        if (! is_array($keyword) || ! is_string($keyword['value'] ?? null) || $keyword['value'] === '') {
            return null;
        }

        $value = $keyword['value'];
        $found = ($keyword['case_sensitive'] ?? HttpDefinition::KEYWORD_CASE_SENSITIVE) === false
            ? stripos($body, $value) !== false
            : str_contains($body, $value);

        if (($keyword['mode'] ?? 'contains') === 'not_contains') {
            return $found ? 'Forbidden keyword found' : null;
        }

        return $found ? null : 'Keyword not found';
    }

    private static function jsonQuery(mixed $query, string $body): ?string
    {
        if (! is_array($query) || ! is_string($query['path'] ?? null)) {
            return null;
        }

        $path = $query['path'];
        $operator = is_string($query['operator'] ?? null) ? $query['operator'] : '==';
        $expected = $query['expected'] ?? null;

        try {
            $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return self::jsonFailure($path, $operator, $expected, 'invalid JSON');
        }

        try {
            $matches = JsonPath::query($document, $path);
        } catch (Throwable) {
            return self::jsonFailure($path, $operator, $expected, 'invalid path');
        }

        if ($operator === 'exists') {
            return $matches === [] ? self::jsonFailure($path, $operator, $expected, 'no match') : null;
        }

        if ($matches === []) {
            return self::jsonFailure($path, $operator, $expected, 'no match');
        }

        $actual = $matches[0];

        return self::compare($actual, $operator, $expected)
            ? null
            : self::jsonFailure($path, $operator, $expected, self::display($actual));
    }

    private static function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($operator === 'contains') {
            if (is_array($actual)) {
                foreach ($actual as $element) {
                    if (self::equals($element, $expected)) {
                        return true;
                    }
                }

                return false;
            }

            return is_string($actual) && str_contains($actual, self::text($expected));
        }

        if ($operator === '==' || $operator === '!=') {
            return self::equals($actual, $expected) === ($operator === '==');
        }

        if (is_array($actual)) {
            return false;
        }

        $left = self::number($actual);
        $right = self::number($expected);
        $order = $left !== null && $right !== null
            ? $left <=> $right
            : strcmp(self::text($actual), self::text($expected)) <=> 0;

        return match ($operator) {
            '>' => $order > 0,
            '>=' => $order >= 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            default => false,
        };
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        $left = self::number($actual);
        $right = self::number($expected);
        if ($left !== null && $right !== null) {
            return $left === $right;
        }

        return self::text($actual) === self::text($expected);
    }

    /**
     * The numeric value of a number or numeric string; null for anything else.
     */
    private static function number(mixed $value): ?float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric(trim($value)) => (float) trim($value),
            default => null,
        };
    }

    /**
     * The JSON-like text form of a value: strings as they are, booleans and null as literals, the rest encoded.
     */
    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    private static function display(mixed $value): string
    {
        return mb_substr(self::text($value), 0, self::ACTUAL_LENGTH);
    }

    private static function jsonFailure(string $path, string $operator, mixed $expected, string $actual): string
    {
        $assertion = $operator === 'exists' ? "{$path} exists" : "{$path} {$operator} ".self::text($expected);

        return "JSON query {$assertion} failed (got {$actual})";
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private static function headers(mixed $checks, array $headers): ?string
    {
        if (! is_array($checks)) {
            return null;
        }

        foreach ($checks as $check) {
            if (! is_array($check) || ! is_string($check['name'] ?? null)) {
                continue;
            }

            $name = $check['name'];
            $values = $headers[strtolower($name)] ?? [];
            $expected = is_string($check['value'] ?? null) ? $check['value'] : '';

            $passed = match ($check['operator'] ?? 'exists') {
                'equals' => in_array($expected, $values, true),
                'contains' => str_contains(implode(', ', $values), $expected),
                'not_exists' => $values === [],
                default => $values !== [],
            };

            if (! $passed) {
                return "Header {$name} check failed";
            }
        }

        return null;
    }
}
