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
    return [
    'env' => env('ZATCA_ENV', env('APP_ENV')),
    'storage' => [
        'invoices_disk' => env('ZATCA_INVOICES_DISK', env('FILESYSTEM_DISK', 'local')),
        'credentials_disk' => env('ZATCA_CREDENTIALS_DISK', env('FILESYSTEM_DISK', 'local')),

        'paths' => [
            'invoices_folder' => env('ZATCA_INVOICES_FOLDER_PATH', 'zatca/invoices'),
            'certificate' => env('ZATCA_CERTIFICATE_PATH', 'zatca/certificate.csr'),
            'private_pem' => env('ZATCA_PRIVATE_PEM_PATH', 'zatca/private.pem'),
            'dev_credentials' => env('ZATCA_CREDENTIALS_PATH', 'zatca/credentials.json'),
            'prod_credentials' => env('ZATCA_PRODUCTION_PATH', 'zatca/production.json'),
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

```php
$zatca = new Sevaske\Zatca();
echo $zatca->echoPhrase('Hello, Sevaske!');
```

## TODO: Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
