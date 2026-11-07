<?php

return [

    'overall' => [
        'all_operational' => 'All systems operational',
        'degraded' => 'Degraded performance',
        'partial_outage' => 'Partial outage',
        'major_outage' => 'Major outage',
        'maintenance' => 'Under maintenance',
        'unknown' => 'Status unknown',
    ],

    'component' => [
        'operational' => 'Operational',
        'degraded' => 'Degraded performance',
        'partial_outage' => 'Partial outage',
        'major_outage' => 'Major outage',
        'maintenance' => 'Maintenance',
        'unknown' => 'No data',
    ],

    'incident_status' => [
        'investigating' => 'Investigating',
        'identified' => 'Identified',
        'monitoring' => 'Monitoring',
        'resolved' => 'Resolved',
    ],

    'impact' => [
        'none' => 'No impact',
        'minor' => 'Minor impact',
        'major' => 'Major impact',
        'critical' => 'Critical impact',
    ],

    'uptime' => 'Uptime',
    'percent' => ':value%',
    'decimal_point' => '.',
    'latency' => 'Response time, last 24 hours',
    'components' => 'Components',
    'days_ago' => ':days days ago',
    'today' => 'Today',
    'no_data' => 'No data',

    'open_incidents' => 'Current incidents',
    'past_incidents' => 'Past incidents',
    'no_incidents' => 'No incidents in the last :days days',
    'affected' => 'Affected: :components',
    'started' => 'Started :date',
    'resolved_at' => 'Resolved :date',

    'active_maintenance' => 'Maintenance in progress',
    'scheduled_maintenance' => 'Scheduled maintenance',
    'in_progress' => 'In progress',
    'until' => 'Until :date',
    'until_further_notice' => 'Until further notice',
    'window' => ':start – :end',

    'last_updated' => 'Last updated :date (:timezone)',
    'powered_by' => 'Powered by IntraWatch',

    // ISO formats for Carbon::isoFormat, in the workspace timezone.
    'formats' => [
        'date' => 'MMM D, YYYY',
        'datetime' => 'MMM D, YYYY HH:mm',
    ],

];
