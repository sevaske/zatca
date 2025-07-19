<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    | Available:
    | - sandbox
    | - simulation
    | - production
    |
    */

    'env' => env('ZATCA_ENV', env('APP_ENV')),

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'storage' => [
        'credentials_disk' => env('ZATCA_CREDENTIALS_DISK', env('FILESYSTEM_DISK', 'local')),
        'invoices_disk' => env('ZATCA_INVOICES_DISK', env('FILESYSTEM_DISK', 'local')),

        'paths' => [
            'invoices_folder' => env('ZATCA_INVOICES_FOLDER_PATH', 'zatca/invoices'),
            'certificate' => env('ZATCA_CERTIFICATE_PATH', 'zatca/certificate.csr'),
            'private_pem' => env('ZATCA_PRIVATE_PEM_PATH', 'zatca/private.pem'),
            'dev_credentials' => env('ZATCA_CREDENTIALS_PATH', 'zatca/credentials.json'),
            'prod_credentials' => env('ZATCA_PRODUCTION_PATH', 'zatca/production.json'),
        ],
    ],
];
