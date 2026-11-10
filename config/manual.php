<?php

declare(strict_types=1);

use IntrAgile\Manual\Search\EnglishStemmer;
use IntrAgile\Manual\Search\HungarianStemmer;

return [

    /*
    |--------------------------------------------------------------------------
    | Manual directory
    |--------------------------------------------------------------------------
    |
    | The directory holding the Markdown manual, one sub-directory per locale.
    | Hungarian is written first; the English translation must keep the same
    | block structure (`php artisan manual:check` compares them).
    |
    */

    'path' => resource_path('manual'),

    'locales' => ['hu', 'en'],

    'source_locale' => 'hu',

    // Content fallback chain: requested locale, then these, then `source_locale`.
    'fallback' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | public | authenticated | disabled | any other value is a gate name.
    | Any signed-in user or API token may read it, whatever its abilities.
    |
    */

    'access' => 'authenticated',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    // Off: routes/api.php registers Manual::routes() inside the auth:sanctum group.
    'routes' => [
        'enabled' => false,
        'prefix' => 'manual',
        'middleware' => ['api'],
        'name' => 'manual.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    'callouts' => ['tip', 'warning', 'note', 'steps'],

    // null = `{path}/_links.php`, when it exists.
    'links' => null,

    // Route names `[[route:name|...]]` may use, checked by `manual:check`: the SPA route names,
    // kept by hand in resources/manual/_routes.php (ManualContentTest compares the list with
    // spa/src/router/index.ts).
    'known_routes' => require resource_path('manual/_routes.php'),

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    'stemmers' => [
        'hu' => HungarianStemmer::class,
        'en' => EnglishStemmer::class,
    ],

    'search' => [
        'min_term_length' => 3,
        'limit' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | A null store uses the application's default cache store.
    |
    */

    'cache' => [
        'store' => null,
        'prefix' => 'manual',
    ],

];
