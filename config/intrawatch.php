<?php

return [

    // The built admin single-page application (index.html plus assets/), served under /admin/.
    'spa_path' => base_path('spa/dist'),

    'import' => [
        // Largest file accepted by the import upload, in megabytes. The image's PHP limits allow up to 512 MB;
        // larger databases go through the command-line importer.
        'max_upload_mb' => (int) env('IMPORT_MAX_UPLOAD_MB', 256),
    ],

];
