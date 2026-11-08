<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Input preparation shared by actions that validate payloads outside an HTTP request.
 */
final class RequestInput
{
    /**
     * Applies what the HTTP middleware does to a request body: strings are trimmed and empty ones become null.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::normalize($value);
            } elseif (is_string($value)) {
                $value = trim($value);
                $out[$key] = $value === '' ? null : $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
