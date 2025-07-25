# Laravel Integration for ZATCA e-Invoicing

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
composer require sevaske/zatca:dev-master
```

Install

```bash
php artisan zatca:install
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

Available commands

```bash
php artisan zatca:generate-csr
php artisan zatca:compliance-certificate
```

## XML Generation and Signing

This library uses [php-zatca-xml](https://github.com/sevaske/php-zatca-xml) for generating and signing XML files.  
More details: [https://github.com/sevaske/php-zatca-xml](https://github.com/sevaske/php-zatca-xml)

### API Methods

The `\Zatca::api()` method is a wrapper around the [sevaske/zatca-api](https://github.com/sevaske/zatca-api) package, providing a simplified interface for interacting with ZATCA services:

```php
use Illuminate\Support\Str;

// Reporting Invoice
\Zatca::api()->reporting('signed xml', 'hash', Str::uuid());

// Clearance Invoice
\Zatca::api()->clearance('signed xml', 'hash', Str::uuid());

// Compliance Check
\Zatca::api()->compliance('signed xml', 'hash', Str::uuid());

// Compliance Certificate Request
\Zatca::api()->complianceCertificate('csr', 'otp');

// Production Certificate Request
\Zatca::api()->productionCertificate('complianceRequestId');
```

More details: [https://github.com/sevaske/zatca-api](https://github.com/sevaske/zatca-api)

### File Access Helpers

The `\Zatca::files()` method provides access to stored production credentials:

```php
// Get the production certificate
\Zatca::files()->productionCredentials()->certificate();

// Get the secret associated with the certificate
\Zatca::files()->productionCredentials()->secret();

// Get the request ID used for the certificate
\Zatca::files()->productionCredentials()->requestId();
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
