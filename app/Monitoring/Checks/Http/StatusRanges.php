<?php

namespace App\Monitoring\Checks\Http;

/**
 * Accepted HTTP status codes, written as single codes ("200") or inclusive ranges ("200-299").
 */
final readonly class StatusRanges
{
    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function __construct(private array $ranges) {}

    /**
     * Entries that are not a code or a range are ignored; an empty list falls back to 200-299.
     *
     * @param  array<array-key, mixed>  $entries
     */
    public static function parse(array $entries): self
    {
        $ranges = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) && ! is_int($entry)) {
                continue;
            }
            if (preg_match('/^\s*(\d{3})\s*(?:-\s*(\d{3}))?\s*$/', (string) $entry, $m) === 1) {
                $ranges[] = [(int) $m[1], (int) ($m[2] ?? $m[1])];
            }
        }

        return new self($ranges === [] ? [[200, 299]] : $ranges);
    }

    public function accepts(int $status): bool
    {
        foreach ($this->ranges as [$low, $high]) {
            if ($status >= $low && $status <= $high) {
                return true;
            }
        }

        return false;
    }
}
