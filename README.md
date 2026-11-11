# Laravel ZATCA

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![Total Downloads](https://img.shields.io/packagist/dt/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![License](https://img.shields.io/packagist/l/sevaske/zatca.svg?style=flat-square)](LICENSE)

Laravel integration for ZATCA (Fatoora) e-invoicing, phase 2 (Saudi Arabia). Onboard an EGS unit from Artisan, then
build, sign and submit invoices, credit notes and debit notes with a few method calls.

It is a thin Laravel layer over two framework-agnostic libraries:

| Package | Does |
|---|---|
| [sevaske/php-zatca-xml](https://github.com/sevaske/php-zatca-xml) | UBL XML, XAdES signature, QR code, invoice hash, private key and CSR |
| [sevaske/zatca-api](https://github.com/sevaske/zatca-api) | ZATCA HTTP API: CSIDs, compliance checks, reporting, clearance |

> Unofficial package, not affiliated with ZATCA. Validate your integration in the sandbox and simulation
> environments before going live.

## Contents

- [Requirements and installation](#requirements-and-installation)
- [How it fits together](#how-it-fits-together)
- [Onboarding an EGS unit](#onboarding-an-egs-unit)
- [Issuing documents](#issuing-documents)
- [Results and errors](#results-and-errors)
- [Credit and debit notes](#credit-and-debit-notes)
- [What your application must store](#what-your-application-must-store)
- [Renewing the production CSID](#renewing-the-production-csid)
- [Configuration](#configuration)
- [Stored files](#stored-files)
- [Testing your application](#testing-your-application)
- [Extending](#extending)
- [Upgrading from 1.x](#upgrading-from-1x)

## Requirements and installation

PHP 8.1+ (`dom`, `libxml`, `openssl`, `mbstring`, `fileinfo`), Laravel 10, 11, 12 or 13.

```bash
composer require sevaske/zatca
php artisan zatca:install        # publishes config/zatca.php
```

The HTTP transport is Guzzle, which Laravel already ships. Translations of the command prompts can be published with
`php artisan vendor:publish --tag=zatca-translations`.

## How it fits together

```php
use Sevaske\Zatca\Facades\Zatca;

Zatca::invoices();   // InvoiceService: build, sign, check, report, clear, submit
Zatca::client();     // Sevaske\ZatcaApi\Client: every ZATCA endpoint
Zatca::files();      // CSR, private key and CSIDs on the configured disk
```

The package covers the per-document work and the one-time onboarding. It does **not** keep your invoice chain: you
provide totals, the counter (ICV) and the previous hash (PIH) in the invoice data, and store what comes back (see
[What your application must store](#what-your-application-must-store)).

Two ZATCA concepts you will meet everywhere:

- **EGS unit**: one device or software instance that issues invoices. It has its own private key, its own
  certificate (**CSID**) and its own invoice chain. This package manages **one** unit per application.
- **CSID**: the certificate ZATCA issues for your CSR. It signs invoices and authenticates API calls. First you get a
  *compliance* CSID, pass the compliance checks with it, then exchange it for a *production* CSID.

A fuller explanation of the process, the invoice chain and the data fields is in the
[php-zatca-xml README](https://github.com/sevaske/php-zatca-xml#readme).

## Onboarding an EGS unit

Done once per environment. Set `ZATCA_ENV` first (`sandbox`, `simulation` or `production`).

### 1. Private key and CSR

```bash
php artisan zatca:generate-csr
```

The command asks for the VAT number, the organization, the branch, the unit's unique serial number and the document
types the unit will issue (`1000` standard, `0100` simplified, `1100` both). It writes `certificate.csr` and
`private_key.pem` to the configured disk. Every answer is also an option, so it runs in scripts:

```bash
php artisan zatca:generate-csr --env=simulation --organization-id=399999999900003 \
    --organization-name="Maximum Speed Tech Supply LTD" --common-name="POS-0001 Riyadh" \
    --unit-name="Riyadh Branch" --category="Supply activities" --address="King Fahd Road, Riyadh" \
    --solution=MyErp --model=v1 --serial=POS-0001 --invoice-type=1100
```

The private key never leaves your system and cannot be recovered: losing it means onboarding the unit again.

### 2. Compliance CSID

Get a one-time password (OTP) in the Fatoora portal; it is valid for one hour. In the **sandbox** the OTP is
`123345`.

```bash
php artisan zatca:compliance-certificate --otp=123456
```

### 3. Compliance checks

ZATCA requires a signed sample of every document type the unit declared in the CSR: standard invoice, credit note
and debit note for `1000`; the simplified ones for `0100`; all six for `1100`. Sign each sample with the compliance
CSID and check it:

```php
$csid = Zatca::files()->complianceCredentials()->csid();

foreach ($samples as $data) {   // six invoice arrays, each with its own ICV, UUID and PIH
    $document = Zatca::invoices()->sign($data, $csid);
    $check = Zatca::invoices()->check($document, $csid);

    if (! $check->isPassed()) {
        foreach ($check->getValidationResults()->getErrorMessages() as $error) {
            echo $error, PHP_EOL;   // "[BR-KSA-37] The seller address building number must contain 4 digits."
        }
    }
}
```

The samples form a chain like real documents: each one takes the next ICV and the hash of the previous one as PIH.
Credit and debit samples need a billing reference ([details](#credit-and-debit-notes)).

### 4. Production CSID

```bash
php artisan zatca:production-certificate
```

From now on real documents are signed with the production CSID.

### Files written by the commands

| Command | Writes (default path) |
|---|---|
| `zatca:generate-csr` | `zatca/certificate.csr`, `zatca/private_key.pem` |
| `zatca:compliance-certificate` | `zatca/compliance_credentials.json` |
| `zatca:production-certificate` | `zatca/production_credentials.json` |

Existing files are never overwritten without confirmation or `--force`. Secrets are printed to the terminal only if
they could not be saved. All commands accept `--env`, `--disk` and `--output` / `--input` style path options; see
`php artisan <command> --help`.

## Issuing documents

### Sign

`sign()` maps the array to a UBL document, validates it, builds the XML and signs it with the production CSID and
the stored private key:

```php
$document = Zatca::invoices()->sign([
    'uuid' => (string) Str::uuid(),
    'id' => 'INV-000123',
    'issueDate' => now('Asia/Riyadh')->format('Y-m-d H:i:s'),
    'issueTime' => now('Asia/Riyadh')->format('Y-m-d H:i:s'),
    'currencyCode' => 'SAR',
    'taxCurrencyCode' => 'SAR',
    'invoiceType' => ['invoice' => 'simplified', 'type' => 'invoice'],
    'additionalDocuments' => [
        ['id' => 'ICV', 'uuid' => '124'],                                     // counter value of this unit
        ['id' => 'PIH', 'attachment' => ['content' => $previousHash]],        // hash of the previous document
    ],
    'supplier' => [/* ... */],
    'invoiceLines' => [/* ... */],
    'taxTotal' => [/* ... */],
    'legalMonetaryTotal' => [/* ... */],
    // ...
]);
```

The complete field list, a worked example, the order of calculating totals and VAT categories are in the
[php-zatca-xml data reference](https://github.com/sevaske/php-zatca-xml#invoice-data-reference). The library does not
calculate totals for you; it checks them.

`sign()` returns a `SignedDocument`:

| Property | Meaning |
|---|---|
| `xml` | the signed document. Store and submit it byte for byte: reformatting breaks the hash and the signature |
| `hash` | invoice hash. Store it: it is the `PIH` of the next document |
| `uuid` | document UUID |
| `qrCode` | base64 TLV, already embedded in the XML; render it as a QR image on the printout |
| `standard` | `true` for standard (B2B) documents. `requiresClearance()` is the same |

Validation is on by default: required fields, `taxInclusiveAmount = taxExclusiveAmount + taxAmount`, and per-line
amounts. A problem throws `InvalidArgumentException` naming the field, before anything is sent. Pass
`validate: false` only if you know why.

`sign()` also accepts a JSON string or a ready `Saleh7\Zatca\Invoice` object, and `build()` returns the unsigned XML.

> **Timezone.** `issueDate` and `issueTime` are combined, parsed in the application timezone and written in UTC. Use
> `Asia/Riyadh` as the application timezone or build the strings in it, otherwise ZATCA may warn
> `invoiceTimeStamp_QRCODE_INVALID`.

### Submit

Simplified (B2C) documents are **reported** within 24 hours of issuing. Standard (B2B) documents are **cleared**
before they go to the buyer.

```php
$result = Zatca::invoices()->submit($document);   // clears standard, reports simplified
```

`submit()` picks the endpoint from the document type. If ZATCA answers that clearance is deactivated for you
(HTTP 303), a standard document is reported instead. To choose yourself:

```php
Zatca::invoices()->report($document);   // ReportingResult
Zatca::invoices()->clear($document);    // ClearanceResult
```

For a cleared standard document, store and hand to the buyer **ZATCA's** version, not yours:

```php
if ($result->isCleared()) {
    $clearedXml = $result->getClearedInvoice();
}
```

### A complete example

```php
public function issue(Order $order): void
{
    DB::transaction(function () use ($order) {
        $unit = EgsUnit::lockForUpdate()->firstOrFail();           // your own table: last_icv, last_hash

        $document = Zatca::invoices()->sign(
            InvoiceData::fromOrder($order, icv: $unit->last_icv + 1, pih: $unit->last_hash)
        );

        $unit->update(['last_icv' => $unit->last_icv + 1, 'last_hash' => $document->hash]);
        $order->zatcaInvoice()->create(['uuid' => $document->uuid, 'hash' => $document->hash, 'xml' => $document->xml]);
    });

    SubmitToZatca::dispatch($order->zatcaInvoice);                 // sending is a separate, retryable step
}
```

Update the counter and hash in the same transaction that creates the document, and never issue two documents of one
unit in parallel. Submit from a queued job: network errors are retryable (see below).

## Results and errors

A validation outcome is a **result**, not an exception. Check it:

```php
$result = Zatca::invoices()->report($document);

if ($result->isReported()) {
    $result->hasWarnings();                       // accepted, but fix the warnings
} elseif ($result->isAlreadyReported()) {
    // HTTP 409: same document was sent before; treat as success
} else {
    foreach ($result->getValidationResults()->getErrorMessages() as $error) {
        logger()->warning((string) $error);       // "[BR-KSA-37] The seller address ..."
    }
}
```

| HTTP | Endpoint | Meaning | Method |
|---|---|---|---|
| 200 | both | accepted | `isReported()` / `isCleared()` |
| 202 | both | accepted with warnings | `hasWarnings()` |
| 208 | clearance | cleared before; cleared invoice returned again | `isAlreadyCleared()` |
| 303 | clearance | clearance is deactivated, use reporting | `isClearanceDisabled()` |
| 400 | both | rejected | `isReported()` / `isCleared()` is `false` |
| 409 | reporting | already reported | `isAlreadyReported()` |

**Exceptions.** Everything thrown by the API client implements `Sevaske\ZatcaApi\Exception\ZatcaException`:

```php
use Sevaske\ZatcaApi\Exception\ApiException;
use Sevaske\ZatcaApi\Exception\AuthenticationException;
use Sevaske\ZatcaApi\Exception\TransportException;

try {
    Zatca::invoices()->submit($document);
} catch (AuthenticationException $e) {
    // wrong or expired CSID, or a compliance CSID used in production
} catch (ApiException $e) {
    $e->getStatusCode(); $e->getErrors(); $e->getResponseBody();
} catch (TransportException $e) {
    // timeout / DNS / connection: retry. Resubmitting is safe, ZATCA identifies a document by its hash
}
```

Missing or unreadable credentials, private key or CSR files throw `Sevaske\Zatca\Exceptions\ZatcaConfigurationException`
with the path and a hint. Invalid invoice data throws `InvalidArgumentException` before any request.

A rejected document is never resubmitted as is. Fix the data and issue a **new** document with a new ICV, UUID and
hash. Its PIH is the hash of the rejected one, because that was the unit's previous document. The
[rejection cheat sheet](https://github.com/sevaske/php-zatca-xml#common-rejections-and-their-causes) maps the common
codes to causes.

## Credit and debit notes

Same call, different `invoiceType` plus a reference to the original document:

```php
$data['invoiceType'] = ['invoice' => 'standard', 'type' => 'credit'];     // or 'debit'
$data['billingReferences'] = [['id' => 'INV-000123', 'uuid' => $originalUuid]];
$data['paymentMeans'] = ['code' => '10', 'note' => 'Returned goods'];     // the reason
```

Amounts stay positive: the type expresses the correction. A note belongs to the same family as the original document
(standard note for a standard invoice) and takes its own place in the chain.

## What your application must store

Per EGS unit:

| Data | Where |
|---|---|
| private key, production CSID | handled by the package (`Zatca::files()`) |
| last ICV and last hash | **you**, updated atomically with each new document, accepted or rejected |

Per document: the signed XML exactly as returned, its hash, UUID and ICV, the ZATCA status and validation messages,
and for standard documents the cleared invoice.

## Renewing the production CSID

A production CSID expires and is renewed with a new CSR and a renewal OTP. Generate the new CSR with
`zatca:generate-csr` (new files, or `--force` to replace), then:

```php
$csid = Zatca::files()->productionCredentials()->csid();
$renewal = Zatca::client()->onboarding()->renewProductionCsid($csid, $newCsr, $otp);

if ($renewal->isIssued()) {
    Zatca::files()->productionCredentials()->put($renewal);
} elseif ($renewal->isNotCompliant()) {
    // HTTP 428: ZATCA issued a compliance CSID instead. Repeat the compliance checks with
    // $renewal->toComplianceCsid(), then request a production CSID.
}
```

The new CSID belongs to the new private key: replace the stored key together with the credentials.

## Configuration

`config/zatca.php`:

| Key | Env | Default | |
|---|---|---|---|
| `env` | `ZATCA_ENV` | `sandbox` | `sandbox`, `simulation` or `production` |
| `language` | `ZATCA_LANGUAGE` | `en` | language of ZATCA validation messages: `en` or `ar` |
| `clearance_enabled` | `ZATCA_CLEARANCE_ENABLED` | `true` | `Clearance-Status` header of reporting requests; `false` only while ZATCA has deactivated clearance for you |
| `http.timeout` | `ZATCA_HTTP_TIMEOUT` | `60` | seconds |
| `http.connect_timeout` | `ZATCA_HTTP_CONNECT_TIMEOUT` | `10` | seconds |
| `storage.credentials_disk` | `ZATCA_CREDENTIALS_DISK` | `FILESYSTEM_DISK` or `local` | disk of all files below |
| `storage.paths.csr` | `ZATCA_CSR_PATH` | `zatca/certificate.csr` | |
| `storage.paths.private_key` | `ZATCA_PRIVATE_KEY_PATH` | `zatca/private_key.pem` | |
| `storage.paths.compliance_credentials` | `ZATCA_COMPLIANCE_CREDENTIALS_PATH` | `zatca/compliance_credentials.json` | |
| `storage.paths.production_credentials` | `ZATCA_PRODUCTION_CREDENTIALS_PATH` | `zatca/production_credentials.json` | |

Each environment has its own CSIDs, so keep separate credentials (and usually a separate storage path) per
environment.

## Stored files

The private key and the CSID secret are credentials. Use a **private** disk: never `public`. Encrypt at rest if your
disk supports it (for example an S3 bucket with server-side encryption), and keep them out of backups that leave your
control. Credentials are stored as:

```json
{"binarySecurityToken": "...", "secret": "...", "requestId": "..."}
```

`Zatca::files()` gives typed access:

```php
Zatca::files()->privateKey()->get();                   // PEM string or null
Zatca::files()->complianceCredentials()->csid();       // Sevaske\ZatcaApi\Credentials\ComplianceCsid
Zatca::files()->productionCredentials()->csid();       // Sevaske\ZatcaApi\Credentials\ProductionCsid
Zatca::files()->productionCredentials()->put($csid);   // also accepts a CsidResponse or an array
```

`csid()` throws `ZatcaConfigurationException` when the file is missing or malformed. The package reads the disk and
config on every call, so it behaves correctly under Octane and when the config changes at runtime.

Need the credentials elsewhere (database, a vault, per-tenant)? Pass them explicitly instead of reading files:

```php
Zatca::invoices()->sign($data, $csid);          // any ProductionCsid / ComplianceCsid
Zatca::invoices()->submit($document, $csid);
```

The private key is still read from `Zatca::files()->privateKey()`; replace that binding to change it (see
[Extending](#extending)).

## Testing your application

Bind a client backed by a mock HTTP handler; no network is involved:

```php
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;

$mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'reportingStatus' => 'REPORTED',
        'validationResults' => ['status' => 'PASS'],
    ])),
]);

$this->app->instance(Client::class, Client::sandbox(
    ClientOptions::create()->withHttpClient(new Guzzle(['handler' => HandlerStack::create($mock)]))
));
```

To exercise signing you need a CSID and a private key. Run the flow once against the sandbox (public OTP `123345`)
or use `Storage::fake()` with credentials issued there.

The package's own sandbox test (onboarding, signing and compliance-checking a simplified and a standard invoice) is
skipped by default:

```bash
ZATCA_LIVE=1 vendor/bin/pest --group=sandbox
```

## Extending

- **Macros.** `Sevaske\Zatca\Zatca` is `Macroable`: `Zatca::macro('issue', fn (Order $o) => ...)`.
- **Services.** The API `Client` is bound as a scoped singleton (rebuilt per request and per Octane tick);
  `Zatca` and `InvoiceService` look it up on every call, so replacing it in the container always takes effect. Rebind
  or extend any of them in a service provider, for example to read the private key from a vault: bind your own
  `ZatcaFilesContract` that returns subclasses of the file classes in `Sevaske\Zatca\Files`.
- **API client.** `Zatca::client()` exposes everything zatca-api offers; see its
  [README](https://github.com/sevaske/zatca-api#readme).

## Upgrading from 1.x

2.x is a rewrite on top of zatca-api v3 and php-zatca-xml v4.

| 1.x | 2.x |
|---|---|
| `Zatca::api()` | `Zatca::client()` (`Sevaske\ZatcaApi\Client`) |
| `api()->reporting($xml, $hash, $uuid)` | `Zatca::invoices()->report($document)` |
| `api()->clearance(...)` | `Zatca::invoices()->clear($document)` |
| `api()->compliance(...)` | `Zatca::invoices()->check($document)` |
| `api()->complianceCertificate($csr, $otp)` | `client()->onboarding()->requestComplianceCsid($csr, $otp)` |
| `api()->productionCertificate($requestId)` | `client()->onboarding()->requestProductionCsid($complianceCsid)` |
| `Http::zatca()` | removed |
| credentials JSON: `certificate`, `secret`, `requestId` | `binarySecurityToken`, `secret`, `requestId` (old files are still read) |
| `files()->...->certificate()` | unchanged: the decoded certificate. New: `->csid()` |

Also new: rejected / already reported documents are results instead of exceptions; commands take options; config keys
`language`, `clearance_enabled` and `http.*`; the unused `invoices_disk` and `storage.paths.invoices` keys are gone.

## License

MIT. See [LICENSE](LICENSE).
