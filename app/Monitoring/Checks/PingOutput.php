<?php

namespace App\Monitoring\Checks;

/**
 * Parses the output of the system ping binary (iputils, macOS/BSD and busybox).
 */
final readonly class PingOutput
{
    private function __construct(
        public int $received,
        public ?float $averageMs,
    ) {}

    /**
     * Reads the summary lines printed when ping exits.
     */
    public static function fromSummary(string $output): ?self
    {
        if (preg_match('/(\d+) packets transmitted, (\d+) (?:packets )?received/', $output, $m) !== 1) {
            return null;
        }

        // iputils: "rtt min/avg/max/mdev", macOS: "round-trip min/avg/max/stddev", busybox: "round-trip min/avg/max".
        $average = preg_match('#min/avg/max(?:/[a-z-]+)? = [\d.]+/([\d.]+)/#', $output, $r) === 1 ? (float) $r[1] : null;

        return new self((int) $m[2], $average);
    }

    /**
     * Reads the per-packet reply lines, for output cut short before the summary was printed.
     */
    public static function fromReplies(string $output): ?self
    {
        $count = preg_match_all('/\bbytes from\b.*\btime[=<]([\d.]+)/', $output, $m);
        if ($count === false || $count === 0) {
            return null;
        }

        return new self($count, array_sum(array_map(floatval(...), $m[1])) / $count);
    }
}
