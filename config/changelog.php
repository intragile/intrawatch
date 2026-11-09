<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Changelog file
    |--------------------------------------------------------------------------
    |
    | The Keep a Changelog file to serve. Translations live next to it and are
    | named by `translation_pattern`, where {locale} is replaced by the locale.
    |
    */

    'path' => base_path('CHANGELOG.md'),

    'translation_pattern' => 'CHANGELOG.{locale}.md',

    'base_locale' => 'en',

    'locales' => ['en', 'hu'],

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | Who may read the changelog API. Defaults to authenticated users.
    |
    */

    'access' => 'authenticated',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => false,
        'prefix' => 'changelog',
        'middleware' => ['api'],
        'name' => 'changelog.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    'show_unreleased' => false,

    'per_page' => 20,

    'max_per_page' => 100,

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
        'prefix' => 'changelog',
    ],

];
