<?php

namespace App\Alerting\Messages;

/**
 * Byte-length limiting that never splits a UTF-8 character.
 */
final class TextLimit
{
    private const ELLIPSIS = '…';

    public static function cut(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $budget = $maxBytes - strlen(self::ELLIPSIS);

        if ($budget <= 0) {
            return '';
        }

        $head = substr($text, 0, $budget);

        // Drop a trailing partial multi-byte sequence.
        while ($head !== '' && ! mb_check_encoding($head, 'UTF-8')) {
            $head = substr($head, 0, -1);
        }

        return $head.self::ELLIPSIS;
    }
}
