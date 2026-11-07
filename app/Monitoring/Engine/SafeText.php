<?php

namespace App\Monitoring\Engine;

/**
 * Makes text from checked targets storable in every supported database.
 *
 * Response bodies, DNS records and push messages can contain NUL bytes and invalid UTF-8. SQLite stores them,
 * but Postgres rejects both in text columns and MySQL rejects invalid UTF-8, so one such value would fail the
 * whole statement it is part of.
 */
final class SafeText
{
    /**
     * Replaces invalid UTF-8 sequences with "?" and removes NUL and the other C0 control characters except
     * tab, line feed and carriage return.
     */
    public static function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = mb_scrub($text, 'UTF-8');

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $text);
    }
}
