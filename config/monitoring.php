<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'raw_hours' => (int) env('RETENTION_RAW_HOURS', 24),
        'minute_hours' => (int) env('RETENTION_MINUTE_HOURS', 48),
        'hour_days' => (int) env('RETENTION_HOUR_DAYS', 90),
        'day_days' => (int) env('RETENTION_DAY_DAYS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | Check worker
    |--------------------------------------------------------------------------
    */
    'worker' => [
        'workers' => (int) env('CHECK_WORKERS', 1),
        'shard_offset' => (int) env('CHECK_SHARD_OFFSET', 0),
        'shards_total' => (int) env('CHECK_SHARDS_TOTAL', env('CHECK_WORKERS', 1)),
        'concurrency' => (int) env('CHECK_CONCURRENCY', 200),
        // Networks a check may never connect to (CIDR notation).
        'blocked_networks' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CHECK_BLOCKED_NETWORKS', '169.254.169.254/32,fd00:ec2::254/128')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitor limits and defaults
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'interval' => [
            'min' => 20,
            'max' => 86400,
            'default' => 60,
            // Types that poll slow-changing facts use a longer floor and default.
            'domain_min' => 3600,
            'slow_default' => 3600,
        ],
        'timeout' => [
            'min' => 1,
            'max' => 120,
            'default' => 10,
            // The timeout may be at most this fraction of the interval.
            'max_interval_ratio' => 0.8,
        ],
        'retries' => [
            'min' => 0,
            'max' => 10,
            'default' => 1,
        ],
        'retry_interval_min' => 20,
        'http' => [
            'url_length' => 2048,
            'max_headers' => 50,
            'header_value_length' => 4096,
            'body_length' => 65536,
            'max_redirects' => 20,
            'default_redirects' => 10,
            'max_header_checks' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate and domain expiry thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'expiry_days' => [30, 14, 7, 1],
        'degraded_below_days' => 7,
    ],

];
