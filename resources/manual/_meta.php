<?php

declare(strict_types=1);

// The manual's table of contents: section id => icon and chapter slugs, in reading order.
// Each chapter lives at {locale}/{section}/{chapter}.md; section titles are in {locale}/_sections.php.
return [
    'start' => [
        'icon' => 'mdi-information-outline',
        'chapters' => ['getting-started'],
    ],
    'monitoring' => [
        'icon' => 'mdi-monitor-dashboard',
        'chapters' => ['monitors', 'notifications', 'incidents', 'maintenances', 'status-pages'],
    ],
    'workspace' => [
        'icon' => 'mdi-cog-outline',
        'chapters' => ['workspace', 'api-tokens', 'import', 'audit'],
    ],
    'account' => [
        'icon' => 'mdi-shield-account-outline',
        'chapters' => ['account'],
    ],
];
