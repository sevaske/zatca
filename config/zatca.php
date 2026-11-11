<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | This setting determines which ZATCA environment your application is
    | currently using. It affects how the library behaves and which
    | credentials and endpoints are utilized.
    |
    | Supported values:
    | - sandbox
    | - simulation
    | - production
    |
    */

    'env' => env('ZATCA_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Client
    |--------------------------------------------------------------------------
    |
    | language: language of ZATCA validation messages ("en" or "ar").
    | clearance_enabled: sent as the Clearance-Status header of reporting
    | requests. Set to false only while ZATCA has deactivated clearance for you.
    |
    */

    'language' => env('ZATCA_LANGUAGE', 'en'),

    'clearance_enabled' => env('ZATCA_CLEARANCE_ENABLED', true),

    'http' => [
        'timeout' => env('ZATCA_HTTP_TIMEOUT', 60),
        'connect_timeout' => env('ZATCA_HTTP_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | This section defines filesystem disk and paths used by the ZATCA
    | integration for storing the CSR, private key and CSID credentials.
    | Use a private disk: these files contain secrets.
    |
    */

    'storage' => [

        /*
        |--------------------------------------------------------------------------
        | Filesystem Disk
        |--------------------------------------------------------------------------
        |
        | The disk used for the CSR, private key and credentials. It must be
        | defined in your `config/filesystems.php` and must NOT be public.
        |
        */

        'credentials_disk' => env('ZATCA_CREDENTIALS_DISK', env('FILESYSTEM_DISK', 'local')),

        /*
        |--------------------------------------------------------------------------
        | File and Folder Paths
        |--------------------------------------------------------------------------
        |
        | Define relative paths to important files and folders used by the
        | ZATCA integration. These paths are relative to the disks specified
        | above.
        |
        */

        'paths' => [
            // Path to the Certificate Signing Request (.csr) file used for certificate issuance
            'csr' => env('ZATCA_CSR_PATH', 'zatca/certificate.csr'),

            // Path to the private key file in PEM format used for signing documents
            'private_key' => env('ZATCA_PRIVATE_KEY_PATH', 'zatca/private_key.pem'),

            // Path to credentials used for sandbox/simulation environments (non-production)
            'compliance_credentials' => env('ZATCA_COMPLIANCE_CREDENTIALS_PATH', 'zatca/compliance_credentials.json'),

            // Path to credentials used for production environment
            'production_credentials' => env('ZATCA_PRODUCTION_CREDENTIALS_PATH', 'zatca/production_credentials.json'),
        ],
    ],
];
