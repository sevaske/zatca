# Laravel ZATCA

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![Total Downloads](https://img.shields.io/packagist/dt/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![License](https://img.shields.io/packagist/l/sevaske/zatca.svg?style=flat-square)](LICENSE)

Laravel integration for ZATCA (Fatoora) e-invoicing, phase 2 (Saudi Arabia). Onboard EGS units with one Artisan
command, then issue and submit invoices, credit notes and debit notes. The package keeps each unit's invoice chain in
your database, safely across queue workers and servers, for any number of units and sellers.

```php
$document = Zatca::unit()->issue($invoiceData, owner: $order);   // ICV, PIH, signature, stored
$document->submit();                                              // cleared or reported, outcome stored
```

It is a Laravel layer over two framework-agnostic libraries:

| Package | Does |
|---|---|
| [sevaske/php-zatca-xml](https://github.com/sevaske/php-zatca-xml) | UBL XML, XAdES signature, QR code, invoice hash, private key and CSR |
| [sevaske/zatca-api](https://github.com/sevaske/zatca-api) | ZATCA HTTP API: CSIDs, compliance checks, reporting, clearance |

> Unofficial package, not affiliated with ZATCA. Validate your integration in the sandbox and simulation
> environments before going live.

## Contents

- [Requirements and installation](#requirements-and-installation)
- [Concepts](#concepts)
- [Onboarding a unit](#onboarding-a-unit)
- [Issuing documents](#issuing-documents)
- [Submitting documents](#submitting-documents)
- [Several units and sellers](#several-units-and-sellers)
- [Adopting an existing unit](#adopting-an-existing-unit)
- [Renewing the CSID](#renewing-the-csid)
- [The database](#the-database)
- [Without the database](#without-the-database)
- [Configuration](#configuration)
- [Testing your application](#testing-your-application)
- [Upgrading from 1.x](#upgrading-from-1x)

## Requirements and installation

PHP 8.1+ (`dom`, `libxml`, `openssl`, `mbstring`), Laravel 10 to 13, and a database with row locks for concurrent
issuing (MySQL, MariaDB, PostgreSQL, SQL Server).

```bash
composer require sevaske/zatca
php artisan zatca:install        # publishes config/zatca.php and the migration, offers to migrate
```

Or step by step: `php artisan vendor:publish --tag=zatca-config`, `--tag=zatca-migrations`, `php artisan migrate`.

## Concepts

- **EGS unit**: a device or software instance that issues documents for one seller (VAT number). It has its own
  private key, its own certificate (**CSID**) and its own invoice chain. A seller may have many units; an application
  may serve many sellers. Each unit is a row in `zatca_egs_units`.
- **CSID**: the certificate ZATCA issues for the unit's CSR. It signs documents and authenticates API calls. A
  *compliance* CSID is used for the compliance checks, then exchanged for a *production* CSID.
- **Invoice chain**: every document a unit generates gets the next counter value (ICV) and the hash of the
  previous document (PIH).

The rules the package follows, from ZATCA's E-invoicing Detailed Technical Guideline (FAQ, pages 71-78):

1. One chain per unit, covering all its documents: standard and simplified, invoices, credit and debit notes.
2. The ICV goes up by one for every generated document and is never reused.
3. The PIH is the hash of the document generated immediately before, **even if that one was rejected**: ZATCA
   tracks the hashes of rejected documents too.
4. A rejected document is never fixed in place. The correction is a new document: new UUID, new ICV, and its PIH is
   the rejected document's hash.
5. Documents may be submitted in any order, as long as they were generated in sequence. Gaps are investigated by
   ZATCA and may lead to penalties.
6. Submitting the same document twice is safe: ZATCA identifies it by UUID and hash.
7. The first document of a unit uses the initial PIH, the base64 of the hex SHA-256 of `0`
   (`NWZlY2Vi...OQ==`, see `InvoiceChain::INITIAL_HASH`).
8. Standard (B2B) documents are cleared before the buyer gets them, and the buyer gets ZATCA's cleared XML.
   Simplified (B2C) documents are reported within 24 hours.

## Onboarding a unit

Get a one-time password (OTP) from the Fatoora portal of the environment (valid for one hour; in the sandbox it is
`123345`) and run:

```bash
php artisan zatca:onboard
```

The command asks for the seller VAT number, the document types the unit will issue (`1000` standard, `0100`
simplified, `1100` both) and the CSR details, then:

1. generates the private key and the CSR,
2. requests the compliance CSID with the OTP,
3. signs a sample of every declared document type and runs ZATCA's compliance checks,
4. requests the production CSID.

Every step is saved on the unit. If a step fails (an expired OTP, a failed check), fix it and run the command again:
it continues where it stopped. `--fresh` starts over with a new key and CSR, as long as the unit is not onboarded.

Units belong to the environment in `ZATCA_ENV` (`sandbox`, `simulation`, `production`) unless you pass `--env`. Name
units when you have several (`php artisan zatca:onboard store-15`); without a name the unit is `default`. All answers
are also options, so onboarding can be scripted:

```bash
php artisan zatca:onboard store-15 --env=simulation --otp=123456 --vat=399999999900003 --invoice-types=1100 \
    --organization-name="Maximum Speed Tech Supply LTD" --organizational-unit="Riyadh Branch" \
    --common-name="POS-0001" --solution=MyErp --model=v1 --serial=POS-0001 \
    --address="King Fahd Road, Riyadh" --category="Supply activities" --no-interaction
```

`php artisan zatca:units` lists the units with their state, last ICV, pending documents and CSID expiry.

<details>
<summary>Onboarding from code, with your own compliance samples</summary>

```php
$unit = EgsUnit::create(['key' => 'store-15', 'environment' => 'simulation', 'vat_number' => '399999999900003']);
$onboarding = Zatca::onboarding();

$onboarding->generateCsr($unit, [
    'organization_name' => 'Maximum Speed Tech Supply LTD', 'organizational_unit' => 'Riyadh Branch',
    'common_name' => 'POS-0001', 'solution' => 'MyErp', 'model' => 'v1', 'serial' => 'POS-0001',
    'address' => 'King Fahd Road, Riyadh', 'business_category' => 'Supply activities',
]);
$onboarding->requestComplianceCsid($unit, $otp);

foreach ($onboarding->runComplianceChecks($unit, $samples ?? null) as $label => $result) {
    $result->isPassed();   // ComplianceCheckResult
}

$onboarding->requestProductionCsid($unit);
```

`$samples` is an array of invoice data keyed by a label; by default the package generates one invoice, credit note
and debit note per declared profile. Samples form a chain of their own and are not stored.

</details>

## Issuing documents

```php
use Sevaske\Zatca\Facades\Zatca;

$document = Zatca::unit()->issue([
    'id' => 'INV-000123',
    'issueDate' => now('Asia/Riyadh')->format('Y-m-d H:i:s'),
    'issueTime' => now('Asia/Riyadh')->format('Y-m-d H:i:s'),
    'currencyCode' => 'SAR',
    'taxCurrencyCode' => 'SAR',
    'invoiceType' => ['invoice' => 'simplified', 'type' => 'invoice'],
    'supplier' => [/* ... */],
    'invoiceLines' => [/* ... */],
    'taxTotal' => [/* ... */],
    'legalMonetaryTotal' => [/* ... */],
], owner: $order);
```

You build the data and the totals (the field list, a worked example and the order of calculating totals are in the
[php-zatca-xml data reference](https://github.com/sevaske/php-zatca-xml#invoice-data-reference)). `issue()`:

1. validates the data: required fields, totals, lines. Invalid data throws `InvalidArgumentException` naming the
   field, and nothing happens to the chain;
2. checks that the unit may issue this profile and that the seller VAT number is the unit's;
3. in one database transaction, with the unit's row locked: takes ICV = last + 1 and PIH = last hash, puts them into
   the document (replacing any you passed), adds a UUID if there is none, signs it with the unit's key and production
   CSID, stores it and moves the chain forward.

The lock makes every other request or queue worker issuing for the same unit wait, on any number of servers, so
counters never repeat or skip. Units do not block each other.

The returned `Document` holds the signed XML byte for byte, its hash, UUID, ICV, PIH, QR code (render it as a QR
image on the printout), profile, type and status `pending`.

**Credit and debit notes** are issued the same way, with their type, a reference to the original invoice and the
reason. They take their own place in the chain:

```php
$data['invoiceType'] = ['invoice' => 'standard', 'type' => 'credit'];     // or 'debit'
$data['billingReferences'] = [['id' => $original->number]];
$data['paymentMeans'] = ['code' => '10', 'note' => 'Returned goods'];     // the reason

$note = Zatca::unit()->issue($data, owner: $refund);
```

Amounts stay positive: the type expresses the correction. A note belongs to the same profile as the original.

> **Timezone.** `issueDate` and `issueTime` are parsed in the application timezone and written in UTC. Use
> `Asia/Riyadh` as the application timezone or build the strings in it, otherwise ZATCA may warn
> `invoiceTimeStamp_QRCODE_INVALID`.

## Submitting documents

```php
$document->submit();
```

Standard documents are cleared, simplified ones reported. If ZATCA answers that clearance is deactivated (HTTP 303),
the document is reported instead, with the `Clearance-Status: 0` header ZATCA expects. The outcome is stored:

| `status` | Meaning |
|---|---|
| `pending` | signed and stored, not accepted yet: never submitted, or the last attempt did not reach ZATCA |
| `reported` | accepted (simplified, or standard while clearance is deactivated) |
| `cleared` | accepted and stamped; `cleared_xml` is ZATCA's version and `qr` its QR code |
| `rejected` | ZATCA found errors (`errors`); issue a corrected document as a new one |

`warnings` holds the warnings of accepted documents (fix them in later documents), `attempts` and `submitted_at`
count the tries. `$document->buyerXml()` is the XML to hand over: the cleared one for standard documents.

`submit()` is idempotent: a document that is reported, cleared or rejected is not sent again. Network failures and
API errors (`Sevaske\ZatcaApi\Exception\ZatcaException`) are thrown after the attempt is counted, and the document
stays `pending`: retry, typically from a queued job. Submitting outside the issuing transaction keeps the lock short
and survives a ZATCA outage:

```php
DB::transaction(function () use ($order, $data) {
    $document = Zatca::unit()->issue($data, owner: $order);
    SubmitZatcaDocument::dispatch($document)->afterCommit();
});

class SubmitZatcaDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        $this->document->submit();
    }
}
```

Simplified documents must be reported within 24 hours; a scheduled sweep catches anything left behind:

```php
Schedule::call(fn () => Document::pending()->where('created_at', '<', now()->subMinutes(15))
    ->each(fn (Document $document) => SubmitZatcaDocument::dispatch($document)))->everyFifteenMinutes();
```

A **rejected** document stays in the chain as it is. Fix the data and issue a new document: it gets the next ICV
and the rejected document's hash as PIH. For a rejected simplified invoice the buyer already has, ZATCA expects a
credit note and a new invoice. The [rejection cheat sheet](https://github.com/sevaske/php-zatca-xml#common-rejections-and-their-causes)
maps common codes to causes.

Your models can list their documents with the `HasZatcaDocuments` trait:

```php
class Order extends Model
{
    use \Sevaske\Zatca\Concerns\HasZatcaDocuments;
}

$order->zatcaDocuments;          // all documents, including rejected ones
$order->latestZatcaDocument;     // the most recent one
```

## Several units and sellers

Each unit has its own key, CSID and chain, so several units are just several rows. A marketplace that issues
commission invoices as the platform and customer invoices on behalf of its stores could look like this:

```bash
php artisan zatca:onboard platform --vat=300000000000003 --invoice-types=1000
php artisan zatca:onboard store-15 --vat=311111111100003 --invoice-types=1100
```

```php
// a customer invoice of store 15: its own seller, unit and chain
$invoice = Zatca::unit("store-{$order->store_id}")->issue(InvoiceData::forOrder($order), owner: $order);

// the platform's commission invoice to the store
$commission = Zatca::unit('platform')->issue(InvoiceData::forCommission($fee), owner: $fee);

// a credit note for the order: same unit as the invoice it corrects
$note = $invoice->unit->issue(InvoiceData::forRefund($refund, original: $invoice), owner: $refund);
```

`issue()` refuses data whose seller VAT number is not the unit's, so a document cannot end up in the wrong
seller's chain. Units of different sellers, environments or branches never wait for each other.

## Adopting an existing unit

An application that already issues documents (with 1.x, or with its own code) keeps its unit and continues its
chain:

1. Install the package and run the migration.
2. Stop issuing documents for the unit (pause the queue workers, or deploy during a quiet moment).
3. Find the **last generated** document of the unit, rejected ones included: the highest ICV. Note its ICV and hash.
4. Import the unit's files and that position:

   ```bash
   php artisan zatca:import bayt --env=production --vat=399999999900003 --invoice-types=1100 \
       --disk=local --private-key=zatca/private_key.pem --credentials=zatca/production_credentials.json \
       --icv=1532 --hash="kL0r...Zg=="
   ```

   The command checks that the private key belongs to the certificate and reads credentials files written by 1.x and
   2.x. It prints the ICV and PIH of the next document; check them. From code, the position can also be read from
   the last signed XML: `$unit->continueAfter($lastSignedXml)->save()`.
5. Switch your issuing code to `Zatca::unit('bayt')->issue(...)` and resume.

Your old documents stay where they are. Notes that correct them refer to them by number in `billingReferences`.
Never run the old and the new issuing code at the same time: two chains for one unit break the sequence.

## Renewing the CSID

A production CSID expires (see `php artisan zatca:units` or `$unit->certificateExpiresAt()`). Get a renewal OTP from
the Fatoora portal and run:

```bash
php artisan zatca:renew store-15 --otp=123456
```

A new private key and CSR are generated with the details of the previous CSR (an imported unit is asked for them
once), and the key and the CSID are replaced together. The chain continues. If ZATCA asks for compliance checks
first (HTTP 428), they run automatically.

## The database

**`zatca_egs_units`**: `key`, `environment` (unique together), `name`, `vat_number`, `invoice_types`, `csr_options`,
`csr`, `private_key`, `compliance_csid`, `production_csid`, `last_icv`, `last_hash`.

**`zatca_documents`**: `egs_unit_id`, `owner_type` / `owner_id` (your model), `uuid` (unique), `icv` (unique per
unit), `pih`, `hash`, `number`, `profile` (`standard` / `simplified`), `type` (`invoice` / `credit` / `debit`),
`billing_reference`, `issued_at`, `xml`, `cleared_xml`, `qr`, `status`, `errors`, `warnings`, `attempts`,
`submitted_at`.

The signed part of a document (unit, UUID, ICV, PIH, hash, XML, profile, type) cannot be updated: the model throws.
Units cannot be deleted while they have documents.

**Credentials.** The private key and the CSIDs are stored in the unit's row, encrypted with your application key
(Laravel's `encrypted` casts), and hidden from `toArray()` / JSON. Keeping them in the database rather than in files
means every server and worker reads the same credentials without a shared disk, and a renewal replaces the key and
the CSID in one atomic update. In exchange the `APP_KEY` protects them: back it up with the database, keep it out of
the repository, and re-encrypt when you rotate it (Laravel 11+ reads values encrypted with `APP_PREVIOUS_KEYS`).
To keep them in a vault instead, extend the model and replace the casts.

**Custom tables and models.** Table names, the connection and the model classes are configurable. Extend the models
to add relations or casts:

```php
class EgsUnit extends \Sevaske\Zatca\Models\EgsUnit
{
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);   // add a store_id column to the published migration
    }
}

// config/zatca.php
'models' => ['unit' => App\Models\EgsUnit::class, 'document' => Sevaske\Zatca\Models\Document::class],
```

## Without the database

Everything above uses `InvoiceService`, which keeps no state. Use it directly when you store credentials and the
chain yourself:

```php
use Sevaske\Zatca\Invoice\SigningCredentials;

$credentials = new SigningCredentials($productionCsid, $privateKeyPem);

$xml = Zatca::invoices()->build($data);                            // unsigned XML
$document = Zatca::invoices()->sign($data, $credentials);          // the data must contain ICV and PIH
$result = Zatca::invoices()->submit($document, $credentials->csid);// ClearanceResult|ReportingResult
Zatca::invoices()->check($sample, $complianceCsid);                // compliance check
Zatca::invoices('simulation');                                     // another environment
```

`Zatca::client()` is the [zatca-api](https://github.com/sevaske/zatca-api#readme) client with every endpoint.

## Configuration

| Key | Env | Default | |
|---|---|---|---|
| `env` | `ZATCA_ENV` | `sandbox` | environment of `Zatca::unit()` and the commands |
| `unit` | `ZATCA_UNIT` | `default` | key of the unit `Zatca::unit()` returns without arguments |
| `language` | `ZATCA_LANGUAGE` | `en` | language of ZATCA validation messages: `en` or `ar` |
| `clearance_enabled` | `ZATCA_CLEARANCE_ENABLED` | `true` | `false` only while ZATCA has deactivated clearance for you: standard documents are then reported |
| `http.timeout` | `ZATCA_HTTP_TIMEOUT` | `60` | seconds |
| `http.connect_timeout` | `ZATCA_HTTP_CONNECT_TIMEOUT` | `10` | seconds |
| `database.connection` | `ZATCA_DB_CONNECTION` | default connection | |
| `database.tables.units` | | `zatca_egs_units` | |
| `database.tables.documents` | | `zatca_documents` | |
| `models.unit`, `models.document` | | the package's models | your subclasses |

| Command | |
|---|---|
| `zatca:onboard {unit?}` | onboard a unit; resumable |
| `zatca:renew {unit?}` | renew the production CSID |
| `zatca:import {unit?}` | adopt a unit onboarded elsewhere and continue its chain |
| `zatca:units` | list units, onboarding state, last ICV, pending documents, CSID expiry |

## Testing your application

Bind an API client backed by a mock HTTP handler; no network is involved:

```php
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;

$mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['reportingStatus' => 'REPORTED'])),
]);

$this->app->instance(Client::class, Client::sandbox(
    ClientOptions::create()->withHttpClient(new Guzzle(['handler' => HandlerStack::create($mock)]))
));
```

Clients of other environments reuse its options, so the mock serves every unit. Signing needs a key and a
certificate; onboard a unit in the sandbox once, or create one with a self-signed certificate as the package's
tests do (`tests/Fixtures/Credentials.php`).

The package's own opt-in tests:

```bash
ZATCA_MYSQL=1 vendor/bin/pest --group=mysql      # concurrent issuing against MySQL (pcntl)
ZATCA_LIVE=1 vendor/bin/pest --group=sandbox     # onboarding, issuing and submitting in the ZATCA sandbox
```

## Upgrading from 1.x

2.x is a rewrite on top of zatca-api v3 and php-zatca-xml v4, centred on EGS units stored in the database.

1. Publish and run the migration (`php artisan zatca:install`).
2. Import your unit from its files and continue its chain: see [Adopting an existing unit](#adopting-an-existing-unit).
3. Replace the calls:

| 1.x | 2.x |
|---|---|
| files on a disk (`Zatca::files()`) | an EGS unit in the database (`Zatca::unit()`), imported with `zatca:import` |
| ICV / PIH kept by your application | kept by the unit: `Zatca::unit()->issue($data)` |
| `api()->reporting(...)`, `api()->clearance(...)` | `$document->submit()` |
| `zatca:generate-csr`, `zatca:compliance-certificate`, `zatca:production-certificate` | `zatca:onboard` |
| `Zatca::api()` | `Zatca::client()` (`Sevaske\ZatcaApi\Client`) |
| `Http::zatca()` | removed |

Removed config keys: `storage.*`, `invoices_disk`. Rejections and "already reported" are results, not exceptions.

## License

MIT. See [LICENSE](LICENSE).
