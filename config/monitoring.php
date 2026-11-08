<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'retention' => [
        // Raw rows must outlive the minute they belong to, and minute rows must cover a whole local day (up to
        // 25 hours) so that day rollups stay exact; smaller values are raised to these minimums.
        'raw_hours' => max(1, (int) env('RETENTION_RAW_HOURS', 24)),
        'minute_hours' => max(25, (int) env('RETENTION_MINUTE_HOURS', 48)),
        'hour_days' => max(1, (int) env('RETENTION_HOUR_DAYS', 90)),
        'day_days' => (int) env('RETENTION_DAY_DAYS', 730),
        // Cached registration lookups no check has refreshed for this long are dropped.
        'domain_lookup_days' => 30,
        // Pruning deletes at most this many rows per statement and pauses between statements, so the hot
        // tables are never locked for long.
        'chunk_size' => (int) env('RETENTION_CHUNK_SIZE', 5000),
        'chunk_sleep_ms' => (int) env('RETENTION_CHUNK_SLEEP_MS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rollups
    |--------------------------------------------------------------------------
    */
    'rollups' => [
        // Newly inserted results scanned per run for minutes before the regular window (late, buffered outcomes).
        'late_id_batch' => 50000,
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
        // Networks a check may never connect to (CIDR notation). The list extends, never replaces, the built-in
        // cloud metadata list (NetworkPolicy::METADATA_NETWORKS), which is always blocked unless allow_metadata
        // is set; notification targets never reach the metadata endpoints either way.
        'blocked_networks' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CHECK_BLOCKED_NETWORKS', '')),
        ))),
        // Lets monitors reach the built-in cloud metadata endpoints (off by default).
        'allow_metadata' => (bool) env('CHECK_ALLOW_METADATA', false),
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

    /*
    |--------------------------------------------------------------------------
    | Domain expiry lookups
    |--------------------------------------------------------------------------
    */
    'domain' => [
        // Seconds one registry lookup may take, WHOIS server discovery included.
        'lookup_timeout' => 30,
        // The server asked for the WHOIS server of a TLD without RDAP.
        'iana_whois' => 'whois.iana.org',
        // TLD => WHOIS server ("host" or "host:port"), for registries IANA does not refer to correctly.
        'whois_servers' => [],
    ],

];
