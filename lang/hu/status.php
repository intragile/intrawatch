<?php

return [

    'overall' => [
        'all_operational' => 'Minden rendszer működik',
        'degraded' => 'Csökkent teljesítmény',
        'partial_outage' => 'Részleges kiesés',
        'major_outage' => 'Súlyos kiesés',
        'maintenance' => 'Karbantartás folyik',
        'unknown' => 'Az állapot ismeretlen',
    ],

    'component' => [
        'operational' => 'Működik',
        'degraded' => 'Csökkent teljesítmény',
        'partial_outage' => 'Részleges kiesés',
        'major_outage' => 'Súlyos kiesés',
        'maintenance' => 'Karbantartás',
        'unknown' => 'Nincs adat',
    ],

    'incident_status' => [
        'investigating' => 'Vizsgálat alatt',
        'identified' => 'Azonosítva',
        'monitoring' => 'Megfigyelés alatt',
        'resolved' => 'Megoldva',
    ],

    'impact' => [
        'none' => 'Nincs hatás',
        'minor' => 'Kisebb hatás',
        'major' => 'Jelentős hatás',
        'critical' => 'Kritikus hatás',
    ],

    'uptime' => 'Rendelkezésre állás',
    'percent' => ':value%',
    'decimal_point' => ',',
    'latency' => 'Válaszidő, utolsó 24 óra',
    'components' => 'Összetevők',
    'bars_summary' => ':name, utolsó :days nap',
    'days_ago' => ':days nappal ezelőtt',
    'today' => 'Ma',
    'no_data' => 'Nincs adat',

    'open_incidents' => 'Aktuális incidensek',
    'past_incidents' => 'Korábbi incidensek',
    'no_incidents' => 'Nem volt incidens az elmúlt :days napban',
    'affected' => 'Érintett: :components',
    'started' => 'Kezdete: :date',
    'resolved_at' => 'Megoldva: :date',

    'active_maintenance' => 'Karbantartás folyamatban',
    'scheduled_maintenance' => 'Ütemezett karbantartás',
    'in_progress' => 'Folyamatban',
    'until' => 'Várható vége: :date',
    'until_further_notice' => 'További értesítésig',
    'window' => ':start – :end',

    'last_updated' => 'Frissítve: :date (:timezone)',
    'powered_by' => 'Az oldalt az IntraWatch működteti',

    // ISO formats for Carbon::isoFormat, in the workspace timezone.
    'formats' => [
        'date' => 'YYYY. MMMM D.',
        'datetime' => 'YYYY. MMMM D. HH:mm',
    ],

];
