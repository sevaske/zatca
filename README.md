# Zatca. Laravel package.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sevaske/zatca/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sevaske/zatca/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sevaske/zatca/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sevaske/zatca/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)

Laravel package to generate the certificate, invoices and working with the API.

## Installation

You can install the package via composer:

```bash
composer require sevaske/zatca
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="zatca-config"
```

This is the contents of the published config file:

```php
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

    'env' => env('ZATCA_ENV',  'sandbox'),

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
```

TODO (not ready):
You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="zatca-migrations"
php artisan migrate
```

## Usage

TODO: more examples and description

```php
\Zatca::api() // \Sevaske\ZatcaApi\Api;
    ->reporting('xml', 'hash', 'uuid');

\Zatca::files() // \Sevaske\Zatca\ZatcaFiles;
    ->productionCredentials()
    ->certificate();
```

## Macro

```php
\Illuminate\Support\Facades\Http::zatca(); // \Sevaske\ZatcaApi\Api;

\Zatca::macro('foo', function() {
    return 'bar';
});

Zatca::foo(); // bar
```

## TODO: Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
