<?php

return [

    // The built admin single-page application (index.html plus assets/), served under /admin/.
    'spa_path' => base_path('spa/dist'),

    'import' => [
        // Largest file accepted by the import upload, in megabytes. The image's PHP limits allow up to 512 MB;
        // larger databases go through the command-line importer.
        'max_upload_mb' => (int) env('IMPORT_MAX_UPLOAD_MB', 256),
        // Largest JSON backup the importer parses, in megabytes, and the most entities it accepts from one.
        'max_json_mb' => (int) env('IMPORT_MAX_JSON_MB', 20),
        'max_json_items' => (int) env('IMPORT_MAX_JSON_ITEMS', 50000),
    ],

    'workspaces' => [
        // Who may create workspaces through the API: `owners` (anyone who already owns a workspace), `anyone`
        // (every signed-in user) or `none` (only the command line). Unknown values behave like `owners`.
        'creators' => env('WORKSPACE_CREATORS', 'owners'),

        'invitations' => [
            // Invitations a workspace may send per hour (resends included).
            'per_hour' => (int) env('INVITATIONS_PER_HOUR', 30),
            // How long an invitation link stays valid.
            'expire_days' => 7,
        ],
    ],

];
