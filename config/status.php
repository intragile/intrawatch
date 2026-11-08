<?php

return [

    'maintenance' => [
        // Window calendar horizon around now, in hours.
        'horizon_hours' => ['past' => 26, 'future' => 50],
        // How often the calendar checks its change stamp, and the forced reload interval.
        'stamp_seconds' => 5,
        'reload_seconds' => 60,
    ],

    'cache' => [
        // Freshness of a rendered page, and how long the last good copy is kept.
        'ttl_seconds' => 60,
        'stale_seconds' => 604800,
    ],

    'custom_domains' => [
        // Host names status pages may claim as their custom domain, comma separated: an exact host
        // (status.example.com) or a suffix (*.example.com, which does not cover example.com itself). Empty
        // turns custom domains off for the API and imports started from it; the command line is exempt.
        'allowed' => array_values(array_filter(
            array_map(static fn (string $host): string => rtrim(strtolower(trim($host)), '.'), explode(',', (string) env('STATUS_CUSTOM_DOMAINS', ''))),
            static fn (string $host): bool => $host !== '',
        )),
    ],

    'rate_limit' => [
        // Public status page requests per minute and client address.
        'per_minute' => (int) env('STATUS_RATE_LIMIT', 300),
    ],

];
