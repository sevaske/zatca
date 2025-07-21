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
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | This section defines filesystem disks and paths used by the ZATCA
    | integration for storing invoices, certificates, keys, and credentials.
    | You may configure different disks for invoices and credentials, as well
    | as specify custom file paths relative to those disks.
    |
    */

    'storage' => [

        /*
        |--------------------------------------------------------------------------
        | Filesystem Disks
        |--------------------------------------------------------------------------
        |
        | Specify which filesystem disks to use for storing credentials and
        | invoices. These disks should be defined in your `config/filesystems.php`.
        |
        */

        'credentials_disk' => env('ZATCA_CREDENTIALS_DISK', env('FILESYSTEM_DISK', 'local')),
        'invoices_disk' => env('ZATCA_INVOICES_DISK', env('FILESYSTEM_DISK', 'local')),

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
            // Folder path to store generated invoice files (e.g., PDFs, XMLs)
            'invoices' => env('ZATCA_INVOICES_FOLDER_PATH', 'zatca/invoices'),

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
