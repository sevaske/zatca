<?php

use Sevaske\Zatca\Models\Document;
use Sevaske\Zatca\Models\EgsUnit;

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "sandbox", "simulation" or "production". Every EGS unit belongs to one
    | environment; Zatca::unit() looks units up in this one.
    |
    */

    'env' => env('ZATCA_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Default EGS unit
    |--------------------------------------------------------------------------
    |
    | Key of the unit returned by Zatca::unit() without arguments. Applications
    | with a single unit never need to name it.
    |
    */

    'unit' => env('ZATCA_UNIT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | API client
    |--------------------------------------------------------------------------
    |
    | language: language of ZATCA validation messages ("en" or "ar").
    | clearance_enabled: set to false only while ZATCA has deactivated
    | clearance for you; standard documents are then reported. When ZATCA
    | answers HTTP 303 to a clearance request this happens automatically.
    |
    */

    'language' => env('ZATCA_LANGUAGE', 'en'),

    'clearance_enabled' => (bool) env('ZATCA_CLEARANCE_ENABLED', true),

    'http' => [
        'timeout' => env('ZATCA_HTTP_TIMEOUT', 60),
        'connect_timeout' => env('ZATCA_HTTP_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Units hold the private key, the CSIDs and the position of the invoice
    | chain; documents hold every signed invoice, credit and debit note.
    | Publish the migration with: php artisan vendor:publish --tag=zatca-migrations
    |
    | Swap the models for your own subclasses to add relations or casts.
    |
    */

    'database' => [
        'connection' => env('ZATCA_DB_CONNECTION'),

        'tables' => [
            'units' => 'zatca_egs_units',
            'documents' => 'zatca_documents',
        ],
    ],

    'models' => [
        'unit' => EgsUnit::class,
        'document' => Document::class,
    ],
];
