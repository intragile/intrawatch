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

];
