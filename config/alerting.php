<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flap detection
    |--------------------------------------------------------------------------
    */
    'flap' => [
        // Class changes within the window that mark a monitor as flapping.
        'threshold' => max(2, (int) env('ALERT_FLAP_THRESHOLD', 4)),
        'window_seconds' => max(60, (int) env('ALERT_FLAP_WINDOW_SECONDS', 600)),
        // A flapping monitor is stable again after this long without a class change.
        'stable_seconds' => max(60, (int) env('ALERT_FLAP_STABLE_SECONDS', 600)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digests
    |--------------------------------------------------------------------------
    */
    'digest' => [
        // More alerts than this for one channel in one evaluation are sent as a single digest.
        'threshold' => max(1, (int) env('ALERT_DIGEST_THRESHOLD', 5)),
    ],

    // Seconds between re-checks of alerts held back by an ancestor's outage.
    'suppression_recheck_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */
    'send' => [
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 10,
        // Response bodies are read up to this many bytes.
        'max_response_bytes' => 65536,
        'tries' => 5,
        // Seconds to wait before each retry.
        'backoff_seconds' => [10, 30, 120, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'retention_days' => max(1, (int) env('RETENTION_ALERT_DAYS', 90)),

];
