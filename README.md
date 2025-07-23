# Zatca – Laravel Integration for ZATCA e-Invoicing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![Total Downloads](https://img.shields.io/packagist/dt/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)

**Zatca** is a Laravel package that integrates with the [ZATCA e-invoicing system](https://zatca.gov.sa/), wrapping the [php-zatca-xml](https://github.com/sevaske/php-zatca-xml) core for certificate generation, XML invoice signing, and submission to the ZATCA API — all the Laravel way.

## 🧱 Under the Hood

This package is a Laravel wrapper around:

- [sevaske/php-zatca-xml](https://github.com/sevaske/php-zatca-xml) – core logic for XML building and signing
- [sevaske/zatca-api](https://github.com/sevaske/zatca-api) – ZATCA API client

## ✨ Features

- 🧾 Generate and sign e-invoices (XML + QR)
- 🔐 Manage CSRs, private keys, credentials
- 🌍 Sandbox, simulation & production environments
- 📂 Uses Laravel's filesystem to store certs and invoices
- ⚙️ Laravel service provider, config, macros and bindings
- 📦 Clean and extendable codebase

## 📦 Installation

Install via Composer:

```bash
composer require sevaske/zatca
```

Publish the config file:

```bash
php artisan vendor:publish --tag="zatca-config"
```

## ⚙️ Configuration

```php
return [
    'env' => env('ZATCA_ENV', 'sandbox'),
    'storage' => [
        'credentials_disk' => env('ZATCA_CREDENTIALS_DISK', env('FILESYSTEM_DISK', 'local')),
        'invoices_disk' => env('ZATCA_INVOICES_DISK', env('FILESYSTEM_DISK', 'local')),
        'paths' => [
            'invoices' => env('ZATCA_INVOICES_FOLDER_PATH', 'zatca/invoices'),
            'csr' => env('ZATCA_CSR_PATH', 'zatca/certificate.csr'),
            'private_key' => env('ZATCA_PRIVATE_KEY_PATH', 'zatca/private_key.pem'),
            'compliance_credentials' => env('ZATCA_COMPLIANCE_CREDENTIALS_PATH', 'zatca/compliance_credentials.json'),
            'production_credentials' => env('ZATCA_PRODUCTION_CREDENTIALS_PATH', 'zatca/production_credentials.json'),
        ],
    ],
];
```

## ✅ Usage

### Commands

Generating CSR and Private key (PEM):

```apacheconf
 php artisan  zatca:generate-csr

 Mode [sandbox]:
  [0] sandbox
  [1] simulation
  [2] production
 > 

 Organization Identifier (3*************3):
 > 333333333333333

 Organization Name:
 > Kanoha

 Organization Common Name:
 > Kanoha Inn

 Tax Identification Number:
 > 1234567891

 Business Category:
 > Information Technology

 Organization Country [SA]:
 > SA

 Organization Address:
 > Kanoha village 123

 Invoice Type [1100]:
 > 1100

 Device Solution Name:
 > API

 Device Model:
 > Z

 Device Serial Number:
 > 1

 Choose disk (only local driver) [local]:
 > local

 Path to save the CSR file? [zatca/certificate.csr]:
 > zatca/certificate.csr

 Path to save the private key (.pem) file? [zatca/private_key.pem]:
 > zatca/private_key.pem

Done.
CSR: /var/www/laravel/storage/app/zatca/certificate.csr
Private Key: /var/www/laravel/storage/app/zatca/private_key.pem

```

```php
use Zatca;

Zatca::api()->reporting($xml, $hash, $uuid);

$cert = Zatca::files()
    ->productionCredentials()
    ->certificate();
```

## 🔌 HTTP Macro

```php
use Illuminate\Support\Facades\Http;

Http::zatca(); // \Sevaske\ZatcaApi\Api
```

## 🧩 Zatca Macro

The main `Zatca` class uses Laravel’s `Macroable` trait, allowing you to define your own methods at runtime:

```php
use Sevaske\Zatca\Facades\Zatca;

Zatca::macro('hello', function () {
    return '👋 Hello from macro!';
});

Zatca::hello(); // "👋 Hello from macro!"
```

You can register macros in a service provider or any bootstrap code (like `AppServiceProvider`).

## 🧪 Testing

```bash
composer test
```

## 📜 Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## ⚖ License

MIT. See [LICENSE](LICENSE) for details.

## 🙌 Credits

Made by [Sevaske](https://github.com/sevaske)
