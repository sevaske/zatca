# Laravel ZATCA

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![Total Downloads](https://img.shields.io/packagist/dt/sevaske/zatca.svg?style=flat-square)](https://packagist.org/packages/sevaske/zatca)
[![License](https://img.shields.io/packagist/l/sevaske/zatca.svg?style=flat-square)](LICENSE)

Laravel integration for ZATCA (Fatoora) e-invoicing, phase 2 (Saudi Arabia). Onboard EGS units with one Artisan
command, then issue and submit invoices, credit notes and debit notes. Each unit's invoice chain is kept in your
database and stays correct across queue workers and servers, for any number of units and sellers.

```php
$document = Zatca::unit()->issue($invoiceData, owner: $order);   // counter, previous hash, signature, stored
$document->submit();                                              // cleared or reported, outcome stored
```

The package builds on two framework-agnostic libraries:

| Package | Does |
|---|---|
| [sevaske/php-zatca-xml](https://github.com/sevaske/php-zatca-xml) | UBL XML, XAdES signature, QR code, invoice hash, private key and CSR |
| [sevaske/zatca-api](https://github.com/sevaske/zatca-api) | ZATCA HTTP API: CSIDs, compliance checks, reporting, clearance |

> Unofficial package, not affiliated with ZATCA. Validate your integration in the sandbox and simulation
> environments before going live.

## Contents

- [Installation](#installation)
- [How it works](#how-it-works)
- [Onboarding a unit](#onboarding-a-unit)
- [Issuing documents](#issuing-documents)
- [Submitting documents](#submitting-documents)
- [Events](#events)
- [Working with documents](#working-with-documents)
- [Several units and sellers](#several-units-and-sellers)
- [Adopting an existing unit](#adopting-an-existing-unit)
- [Renewing the CSID](#renewing-the-csid)
- [Database reference](#database-reference)
- [API reference](#api-reference)
- [Without the database](#without-the-database)
- [Configuration and commands](#configuration-and-commands)
- [Testing your application](#testing-your-application)
- [Upgrading from 1.x](#upgrading-from-1x)

## Installation

Requirements: PHP 8.1+ with `dom`, `libxml`, `openssl` and `mbstring`; Laravel 10 to 13. Concurrent issuing needs a
database with row locks: MySQL, MariaDB, PostgreSQL or SQL Server. SQLite works for a single process.

Laravel 10 and 11 no longer receive security fixes, and every release of them has open security advisories.
Recent Composer versions refuse to install such packages, so on these versions Composer may stop with "affected by
security advisories". Its message names the setting that allows them (in Composer 2.11:
`composer config policy.advisories.block false`, or ignore the listed advisory IDs). Upgrading Laravel is the better
fix.

```bash
composer require sevaske/zatca
php artisan zatca:install
```

`zatca:install` publishes `config/zatca.php` and the migration and offers to run it. To do it by hand:

```bash
php artisan vendor:publish --tag=zatca-config
php artisan vendor:publish --tag=zatca-migrations
php artisan migrate
```

## How it works

**EGS unit.** A device or software instance that issues documents for one seller (VAT number). A unit has its own
private key, its own certificate (CSID) and its own invoice chain. A seller can have several units, and an
application can serve several sellers. Each unit is a row in `zatca_egs_units` and belongs to one environment:
`sandbox`, `simulation` or `production`.

**CSID.** The certificate ZATCA issues for the unit's CSR. It signs documents and authenticates API calls.
Onboarding first gets a *compliance* CSID, passes the compliance checks with it, then exchanges it for a *production*
CSID. Only the production CSID signs and submits real documents.

**Invoice chain.** Each document gets the next invoice counter value (ICV) and the hash of the previous document
(PIH). ZATCA uses the chain to detect missing or altered documents.

The package enforces these rules, taken from ZATCA's *E-invoicing Detailed Technical Guideline* (FAQ, pages 71-78):

1. A unit has one chain for all its documents: standard and simplified, invoices, credit notes and debit notes.
2. The ICV increases by one for each generated document and is never reused.
3. The PIH is the hash of the document generated immediately before, **even if that document was rejected**. ZATCA
   stores the hashes of rejected documents too.
4. A rejected document is never fixed or deleted. The correction is a new document with a new UUID and ICV; its PIH
   is the rejected document's hash.
5. Documents can be submitted in any order, as long as they were generated in sequence. ZATCA checks the sequence
   later; gaps are investigated and can lead to penalties.
6. Submitting the same document again is safe: ZATCA identifies a document by its UUID and hash.
7. A unit's first document uses the initial PIH: base64 of the hex SHA-256 of `0`
   (`InvoiceChain::INITIAL_HASH`, `NWZlY2Vi...OQ==`).
8. Standard (B2B) documents are cleared before they reach the buyer, and the buyer gets ZATCA's cleared XML.
   Simplified (B2C) documents are reported within 24 hours of issuing.

A document's life:

```
issue()  ──>  pending  ──submit()──>  reported | cleared      accepted, final
                 │                    rejected                final; issue a corrected document
                 └── network or API error: stays pending, submit() again
```

## Onboarding a unit

Onboarding gives a unit its private key and production CSID. Do it once per unit and environment.

### With the command

Get a one-time password (OTP) for the environment, then run the command:

| Environment | Where the OTP comes from |
|---|---|
| `sandbox` | not needed: the sandbox accepts `123345` (the command's default) |
| `simulation` | the Fatoora simulation portal |
| `production` | the Fatoora portal |

An OTP is valid for one hour.

```bash
php artisan zatca:onboard
```

The command asks for the seller's VAT number, the document types the unit will issue and the CSR details, then
runs four steps:

| Step | Saved on the unit |
|---|---|
| 1. Generate a private key and a CSR | `private_key`, `csr`, `csr_options` |
| 2. Exchange the CSR and the OTP for a compliance CSID | `compliance_csid` |
| 3. Sign a sample of each declared document type and send it to ZATCA's compliance checks | nothing; failed checks are printed with their codes |
| 4. Exchange the compliance CSID for a production CSID | `production_csid` |

Each step saves its result, so if one fails (an expired OTP, a failed check), fix the cause and run the command
again: it continues from that step. The command stops early if the unit already has a production CSID; to renew it,
use [`zatca:renew`](#renewing-the-csid).

The samples are generated from the unit's VAT number and organization name: for each declared profile, an invoice,
a credit note and a debit note of 100.00 SAR + 15% VAT. They use their own chain and are not stored.

Without an argument the unit is called `default` (config `zatca.unit`). Name units when you have several:
`php artisan zatca:onboard store-15`. The environment is `ZATCA_ENV` unless you pass `--env`.

Every prompt has an option, so onboarding can run from a script (`--no-interaction` fails on any missing value
instead of asking):

```bash
php artisan zatca:onboard store-15 --env=simulation --otp=123456 \
    --vat=399999999900003 --invoice-types=1100 \
    --organization-name="Maximum Speed Tech Supply LTD" --organizational-unit="Riyadh Branch" \
    --common-name="POS-0001" --solution=MyErp --model=v1 --serial=POS-0001 \
    --address="King Fahd Road, Riyadh" --category="Supply activities" --no-interaction
```

| Option | Meaning |
|---|---|
| `--vat` | seller VAT number: 15 digits, starting and ending with 3 |
| `--invoice-types` | `1000` standard only, `0100` simplified only, `1100` both |
| `--organization-name` | registered name of the seller |
| `--organizational-unit` | branch name; in a VAT group, the TIN of the member |
| `--common-name` | name of the unit |
| `--solution`, `--model`, `--serial` | your solution, its version and the unit's serial number (unique per unit) |
| `--address`, `--category` | branch address and business category |
| `--country` | two-letter code, default `SA` |
| `--name` | your own label for the unit |
| `--fresh` | discard the key, CSR and compliance CSID and start over (only for a unit that is not onboarded yet) |

Run `php artisan zatca:units` to see every unit with its state (`new`, `CSR generated`, `compliance checks`,
`onboarded`), last ICV, number of pending documents and CSID expiry date.

### From code

The steps are methods of `Zatca::onboarding()`. Use them to onboard from a UI, or to run the compliance checks with
your own sample data:

```php
use Sevaske\Zatca\Facades\Zatca;
use Sevaske\Zatca\Models\EgsUnit;

$unit = EgsUnit::create([
    'key' => 'store-15',
    'environment' => 'simulation',
    'vat_number' => '399999999900003',
    'invoice_types' => '1100',
]);

$onboarding = Zatca::onboarding();

$onboarding->generateCsr($unit, [
    'organization_name' => 'Maximum Speed Tech Supply LTD',
    'organizational_unit' => 'Riyadh Branch',
    'common_name' => 'POS-0001',
    'solution' => 'MyErp',
    'model' => 'v1',
    'serial' => 'POS-0001',
    'address' => 'King Fahd Road, Riyadh',
    'business_category' => 'Supply activities',
]);

$onboarding->requestComplianceCsid($unit, $otp);

$results = $onboarding->runComplianceChecks($unit);   // or ($unit, ['my invoice' => $data, ...])

foreach ($results as $label => $result) {
    if (! $result->isPassed()) {
        $errors = $result->getValidationResults()->getErrorMessages();   // "[BR-KSA-37] ..."
    }
}

$onboarding->requestProductionCsid($unit);
```

## Issuing documents

### A complete example

The application builds the document data, including all totals. The package adds the chain fields and signs. This
simplified invoice (2 × 100.00 + 15% VAT) is valid as it is:

```php
use Sevaske\Zatca\Facades\Zatca;

$now = now('Asia/Riyadh')->format('Y-m-d H:i:s');

$document = Zatca::unit()->issue([
    'id' => 'INV-000123',                                    // your invoice number
    'issueDate' => $now,
    'issueTime' => $now,
    'currencyCode' => 'SAR',
    'taxCurrencyCode' => 'SAR',
    'invoiceType' => ['invoice' => 'simplified', 'type' => 'invoice'],
    'supplier' => [
        'registrationName' => 'Maximum Speed Tech Supply LTD',
        'taxId' => '399999999900003',                        // must be the unit's VAT number
        'identificationId' => '1010010000',
        'identificationType' => 'CRN',
        'address' => [
            'street' => 'King Fahd Road',
            'buildingNumber' => '1234',                       // 4 digits
            'subdivision' => 'Al Olaya',
            'city' => 'Riyadh',
            'postalZone' => '12345',                          // 5 digits
            'country' => 'SA',
        ],
    ],
    'paymentMeans' => ['code' => '10'],                      // 10 cash, 30 credit transfer, 48 card ...
    'taxTotal' => [
        'taxAmount' => 30.00,
        'subTotals' => [[
            'taxableAmount' => 200.00,
            'taxAmount' => 30.00,
            'taxCategory' => ['percent' => 15, 'taxScheme' => ['id' => 'VAT']],
        ]],
    ],
    'legalMonetaryTotal' => [
        'lineExtensionAmount' => 200.00,
        'taxExclusiveAmount' => 200.00,
        'taxInclusiveAmount' => 230.00,
        'allowanceTotalAmount' => 0.00,
        'prepaidAmount' => 0.00,
        'payableAmount' => 230.00,
    ],
    'invoiceLines' => [[
        'id' => 1,
        'unitCode' => 'PCE',
        'quantity' => 2,
        'lineExtensionAmount' => 200.00,
        'item' => [
            'name' => 'Office chair',
            'classifiedTaxCategory' => [['percent' => 15, 'taxScheme' => ['id' => 'VAT']]],
        ],
        'price' => ['amount' => 100.00, 'unitCode' => 'PCE'],
        'taxTotal' => ['taxAmount' => 30.00, 'roundingAmount' => 230.00],
    ]],
], owner: $order);
```

A **standard** (B2B) document also needs the buyer and the delivery date:

```php
$data['invoiceType'] = ['invoice' => 'standard', 'type' => 'invoice'];
$data['delivery'] = ['actualDeliveryDate' => $now];
$data['customer'] = [
    'registrationName' => 'Buyer Company LLC',
    'taxId' => '399999999800003',
    'address' => ['street' => 'Al Urubah Road', 'buildingNumber' => '7176', 'subdivision' => 'Al Olaya',
        'city' => 'Riyadh', 'postalZone' => '12251', 'country' => 'SA'],
];
```

Leave out `uuid` and the `ICV` / `PIH` entries of `additionalDocuments`: the unit fills them in. Any you pass are
replaced. The full field list, discounts, other VAT categories and the order in which to calculate totals are in the
[php-zatca-xml data reference](https://github.com/sevaske/php-zatca-xml#invoice-data-reference). `issue()` also
accepts a ready `Saleh7\Zatca\Invoice` object.

> **Timezone.** `issueDate` and `issueTime` are parsed in the application timezone and written in UTC. Use
> `Asia/Riyadh` as the application timezone, or build the strings in it as above. Otherwise ZATCA may warn
> `invoiceTimeStamp_QRCODE_INVALID`.

### What `issue()` does

1. **Validates** the data: required fields, `taxInclusiveAmount = taxExclusiveAmount + taxAmount`, line amounts.
2. **Checks the unit** may issue this profile (`invoice_types`).
3. In one transaction, with the unit's row **locked** (`SELECT ... FOR UPDATE`):
   - reads `last_icv` and `last_hash`, and sets ICV = `last_icv + 1` and PIH = `last_hash` (or the initial hash);
   - adds a UUID if the data has none;
   - signs the document with the unit's private key and production CSID;
   - checks that the seller VAT number in the document is the unit's;
   - stores the document with status `pending`, and saves its ICV and hash on the unit.
4. After the commit, dispatches [`DocumentIssued`](#events).
5. Returns the `Document`.

If any step fails, nothing is stored and no counter value is used:

| Exception | Cause |
|---|---|
| `InvalidArgumentException` | invalid data (the message names the field), a profile the unit did not declare, or another seller's VAT number |
| `Sevaske\Zatca\Exceptions\ZatcaConfigurationException` | the unit has no production CSID or private key |

**Concurrency.** The lock makes every other process issuing for the same unit wait until the transaction commits,
whether it is a web request, a queue worker or another server. Counters never repeat or skip, and each PIH is the
hash of the real previous document. Different units never wait for each other.

**Inside your own transaction.** `issue()` can run inside `DB::transaction()`, for example to create the order and
its invoice together. The lock is then held until *your* transaction commits, so keep the transaction short. If it
rolls back, the document and its counter value disappear together, which is correct because the document was never
submitted. Never submit inside the transaction: dispatch the submission after the commit (see below).

### Credit and debit notes

A note uses the same data as an invoice, plus three fields: its type, the invoice it corrects and the reason. It
takes the next position in the unit's chain like any other document.

```php
$data['invoiceType'] = ['invoice' => 'standard', 'type' => 'credit'];    // or 'debit'
$data['billingReferences'] = [['id' => $original->number]];             // the corrected invoice
$data['paymentMeans'] = ['code' => '10', 'note' => 'Returned goods'];    // the reason (required)

$note = $original->unit->issue($data, owner: $refund);
```

Amounts stay positive: the type says whether it is a correction down or up. A note has the same profile as the
invoice it corrects, and is issued by the same unit.

## Submitting documents

```php
$document->submit();
```

`submit()` clears standard documents and reports simplified ones, then stores ZATCA's answer on the document and
returns it.

| `status` | Meaning | What to do |
|---|---|---|
| `pending` | not accepted yet: never submitted, or the last attempt failed before ZATCA answered | submit again |
| `reported` | accepted: a simplified document, or a standard one while clearance is deactivated | nothing; check `warnings` |
| `cleared` | accepted and stamped by ZATCA | give the buyer `buyerXml()`; check `warnings` |
| `rejected` | ZATCA found errors, listed in `errors` | fix the cause and issue a new document |

`errors` and `warnings` are lists of `['code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => '...']`. A document
with warnings is accepted, but fix the cause in later documents. `attempts` counts the submissions and
`submitted_at` holds the time of the last one.

**Idempotent.** A document that is `reported`, `cleared` or `rejected` is never sent again: calling `submit()` on it
does nothing and makes no HTTP request. Submitting a `pending` document twice, for example from two workers, is
harmless: ZATCA answers "already reported" (stored as `reported`) or returns the cleared invoice again.

**Clearance deactivated.** If ZATCA has deactivated clearance for the taxpayer, it answers HTTP 303 to a clearance
request. `submit()` then reports the document instead, with the `Clearance-Status: 0` header ZATCA requires. The
status becomes `reported` and there is no cleared XML.

### Errors and retries

Errors that prevent ZATCA from answering are thrown. Before throwing, `submit()` counts the attempt; the document
stays `pending`.

| Exception (namespace `Sevaske\ZatcaApi\Exception`) | Cause | Retry? |
|---|---|---|
| `TransportException` | timeout, DNS, connection refused | yes |
| `ServerException` (HTTP 5xx), `RateLimitException` (429) | ZATCA unavailable or throttling | yes, with a delay |
| `AuthenticationException` (401) | wrong or revoked CSID, or a compliance CSID in production | no: fix the unit's credentials |
| `ApiException` (other statuses) | unexpected HTTP error; `getStatusCode()`, `getErrors()`, `getResponseBody()` | investigate |

All of them implement `Sevaske\ZatcaApi\Exception\ZatcaException`. A rejection (HTTP 400 with validation results)
is not an exception: it is stored as `rejected`.

### Submitting from a queue

Submit outside the request that issued the document. The issuing transaction stays short, and a ZATCA outage only
delays the submission:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($order, $data) {
    $document = Zatca::unit()->issue($data, owner: $order);

    SubmitZatcaDocument::dispatch($document)->afterCommit();
});
```

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sevaske\Zatca\Models\Document;
use Sevaske\ZatcaApi\Exception\AuthenticationException;

class SubmitZatcaDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        try {
            $this->document->submit();
        } catch (AuthenticationException $e) {
            $this->fail($e);   // retrying will not help: the credentials need fixing
        }
    }
}
```

Simplified documents must be reported within 24 hours. A scheduled job resubmits anything left `pending`:

```php
use Illuminate\Support\Facades\Schedule;   // Laravel 11+; in Laravel 10 use the console kernel

Schedule::call(function () {
    Document::pending()
        ->where('created_at', '<', now()->subMinutes(15))
        ->each(fn (Document $document) => SubmitZatcaDocument::dispatch($document));
})->everyFifteenMinutes();
```

### After a rejection

A rejected document stays in the chain unchanged. Fix the cause (the data, the seller's address, the totals) and
issue a **new** document. It gets the next ICV and the rejected document's hash as its PIH.

- **Standard invoice rejected:** it is not a valid tax invoice and the buyer has not received it. Issue the corrected
  invoice with the current date.
- **Simplified invoice rejected:** the buyer usually has it already. Issue the corrected document with the original
  transaction date (FAQ, page 77). The FAQ also states that a "not reported" invoice is invalid and should be
  cancelled with a credit note and replaced; check with your tax adviser which applies to you.

The [rejection cheat sheet](https://github.com/sevaske/php-zatca-xml#common-rejections-and-their-causes) maps common
error codes to their causes.

## Events

| Event (namespace `Sevaske\Zatca\Events`) | Dispatched when | Typical listener |
|---|---|---|
| `DocumentIssued` | `issue()` stored a document (status `pending`) | queue the submission |
| `DocumentSubmitted` | ZATCA decided on a document: `cleared`, `reported` or `rejected` | send `buyerXml()` to the buyer, alert someone about a rejection, update your order |

Both carry the document as `$event->document`. The package registers no listeners of its own.

**After the commit.** Both events wait until the database transaction commits, including a transaction of yours
around `issue()`. If it rolls back, the event is never dispatched, so a listener never sees a document that does not
exist.

**Once per decision.** `DocumentSubmitted` is dispatched when the status becomes final. Calling `submit()` on a
decided document dispatches nothing, and neither does an attempt that failed with an exception: the document stays
`pending` and the retry dispatches the event when ZATCA answers.

Submitting every issued document from one place, instead of where each document is issued:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Events\DocumentIssued;
use Sevaske\Zatca\Events\DocumentSubmitted;

// in a service provider's boot()
Event::listen(fn (DocumentIssued $event) => SubmitZatcaDocument::dispatch($event->document));

Event::listen(function (DocumentSubmitted $event) {
    $document = $event->document;

    match ($document->status) {
        DocumentStatus::Rejected => Notification::route('mail', 'accounting@example.com')
            ->notify(new ZatcaDocumentRejected($document)),
        DocumentStatus::Cleared => $document->owner?->sendInvoiceToBuyer($document->buyerXml()),
        default => null,
    };
});
```

`SubmitZatcaDocument` is the job from [Submitting from a queue](#submitting-from-a-queue); the notification and the
owner's method are yours. A listener that does slow work should implement `ShouldQueue`.

## Working with documents

**What to give the buyer.** `$document->buyerXml()` returns ZATCA's cleared XML for a cleared document, and the
signed XML otherwise. `$document->qr` is the base64 QR payload; render it as a QR image on the printout. For a cleared
document it is ZATCA's QR code taken from the cleared XML.

**Your models.** Add `HasZatcaDocuments` to any model that owns documents:

```php
use Sevaske\Zatca\Concerns\HasZatcaDocuments;

class Order extends Model
{
    use HasZatcaDocuments;
}

$order->zatcaDocuments;          // every document of the order, rejected ones included
$order->latestZatcaDocument;     // the most recent one: after a rejection, the correction
```

**Queries.** `Document` is an Eloquent model:

```php
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Models\Document;

Document::pending()->count();                                               // waiting for ZATCA
Document::where('status', DocumentStatus::Rejected)->whereDate('created_at', today())->get();
Document::where('number', 'INV-000123')->first();
Zatca::unit('store-15')->documents()->orderByDesc('icv')->first();          // the unit's last document
```

## Several units and sellers

Each unit has its own key, CSID and chain, so several units are just several rows. For example, a marketplace that
issues commission invoices as the platform and customer invoices on behalf of its stores:

```bash
php artisan zatca:onboard platform --vat=300000000000003 --invoice-types=1000
php artisan zatca:onboard store-15 --vat=311111111100003 --invoice-types=1100
```

```php
// a customer invoice of store 15: the store's unit and chain
$invoice = Zatca::unit("store-{$order->store_id}")->issue($orderData, owner: $order);

// the platform's commission invoice to the store
$commission = Zatca::unit('platform')->issue($commissionData, owner: $fee);

// a credit note for the order: the unit that issued the invoice
$note = $invoice->unit->issue($refundData, owner: $refund);
```

- `issue()` refuses data whose seller VAT number differs from the unit's, so a document cannot land in another
  seller's chain or reach ZATCA with the wrong certificate.
- Units are independent: a slow or failing unit does not block the others.
- The same key can exist in several environments: `store-15` in `simulation` on staging and in `production` live.
  `Zatca::unit()` looks in the environment of `ZATCA_ENV`.

## Adopting an existing unit

An application that already issues documents, with 1.x or its own code, keeps its unit and continues its chain.

1. **Install** the package and run the migration.
2. **Stop issuing** for the unit: pause the queue workers or put the application in maintenance mode.
3. **Find the last generated document** of the unit, rejected ones included: the one with the highest ICV. Take its
   ICV and its hash (the `invoiceHash` that was sent to ZATCA, or the hash your signer returned).
4. **Import** the private key, the production CSID and that position:

   ```bash
   php artisan zatca:import bayt --env=production --vat=399999999900003 --invoice-types=1100 \
       --disk=local --private-key=zatca/private_key.pem \
       --credentials=zatca/production_credentials.json \
       --icv=1532 --hash="kL0r...Zg=="
   ```

   The command reads credentials files written by 1.x (`certificate`, `secret`) and 2.x (`binarySecurityToken`,
   `secret`), checks that the private key belongs to the certificate, and prints the ICV and PIH of the next
   document. Compare them with your records before going on.
5. **Switch** your issuing code to `Zatca::unit('bayt')->issue(...)` and resume.

If you have the last document's signed XML rather than its hash, set the position from code. The ICV is read from
the XML and the hash is recomputed:

```php
Zatca::unit('bayt')->continueAfter($lastSignedXml)->save();
```

Your old documents stay in your own tables. Notes that correct them refer to them by number in
`billingReferences`. Never run the old and the new issuing code at the same time: two processes keeping separate
chains for one unit break the sequence.

`zatca:import` refuses to overwrite an existing unit unless you pass `--force`. With `--force` it replaces the
credentials and keeps the chain position. A new `--icv` / `--hash` is accepted only while the unit has no documents
in this package.

## Renewing the CSID

A production CSID has an expiry date: see `php artisan zatca:units` or `$unit->certificateExpiresAt()`. Renew it
before then with a renewal OTP from the Fatoora portal:

```bash
php artisan zatca:renew store-15 --otp=123456
```

The command generates a new private key and CSR with the details of the previous CSR. An imported unit has none
stored, so it asks for them once. It then replaces the key and the CSID in a single update. The chain continues
unchanged. If ZATCA answers that compliance checks are needed first (HTTP 428), they run automatically with
generated samples.

From code: `Zatca::onboarding()->renew($unit, $otp)`.

## Database reference

### `zatca_egs_units`

| Column | Content |
|---|---|
| `key` | your identifier: `default`, `store-15` ...; unique together with `environment` |
| `environment` | `sandbox`, `simulation` or `production` |
| `name` | your label |
| `vat_number` | seller VAT number, as in the CSR |
| `invoice_types` | `1000`, `0100` or `1100` |
| `csr_options` | CSR details, reused for renewals |
| `csr` | the CSR (PEM) |
| `private_key` | encrypted |
| `compliance_csid`, `production_csid` | encrypted JSON: `binarySecurityToken`, `secret` (and `requestId`) |
| `last_icv` | ICV of the last generated document; `0` before the first one |
| `last_hash` | hash of the last generated document; `null` before the first one |

### `zatca_documents`

| Column | Content |
|---|---|
| `egs_unit_id` | the unit; units with documents cannot be deleted |
| `owner_type`, `owner_id` | your model (nullable) |
| `uuid` | unique |
| `icv` | unique per unit |
| `pih`, `hash` | previous hash and this document's hash |
| `number` | your invoice number (`id` in the data) |
| `profile` | `standard` or `simplified` (`Profile` enum) |
| `type` | `invoice`, `credit` or `debit` (`DocumentType` enum) |
| `billing_reference` | numbers of the invoices a note refers to |
| `issued_at` | issue date and time |
| `xml` | the signed XML, byte for byte |
| `cleared_xml` | ZATCA's cleared XML (cleared documents) |
| `qr` | QR payload, base64 |
| `status` | `DocumentStatus` enum |
| `errors`, `warnings` | ZATCA's messages, JSON |
| `attempts`, `submitted_at` | number and time of submissions |

The signed columns (`egs_unit_id`, `uuid`, `icv`, `pih`, `hash`, `profile`, `type`, `xml`, `qr`) cannot change after
signing: saving a change throws `LogicException`. The only exception is `qr`, which is replaced together with the
cleared XML.

### Credentials

The private key and the CSIDs are encrypted with the application key (Laravel's `encrypted` casts) and hidden from
`toArray()` and JSON. Commands never print them.

They are stored in the database rather than in files for two reasons: every server and worker reads the same
credentials without a shared disk, and a renewal replaces the key and the CSID in one atomic update. The consequence
is that `APP_KEY` protects them:

- back up `APP_KEY` together with the database: without it the credentials cannot be decrypted, and the unit has to
  be onboarded again;
- when you rotate `APP_KEY`, re-encrypt the units, or keep the old key in `APP_PREVIOUS_KEYS` (Laravel 11+).

To store them elsewhere, such as a vault, extend the model and replace the casts.

### Custom tables, connection and models

Table names and the connection are set in `config/zatca.php`, and the models can be replaced by subclasses, for
example to link units to your stores:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EgsUnit extends \Sevaske\Zatca\Models\EgsUnit
{
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);   // add store_id to the published migration
    }
}
```

```php
// config/zatca.php
'models' => [
    'unit' => App\Models\EgsUnit::class,
    'document' => Sevaske\Zatca\Models\Document::class,
],
```

## API reference

### `Zatca` facade

| Method | Returns |
|---|---|
| `unit(?string $key = null)` | the `EgsUnit` with this key in the configured environment (default key: `zatca.unit`); throws `ZatcaConfigurationException` if there is none |
| `invoices(?string $environment = null)` | `InvoiceService`: stateless build, sign, check and submit |
| `onboarding()` | `Onboarding`: the onboarding steps and renewal |
| `client(?string $environment = null)` | the zatca-api `Client` for an environment |

### `EgsUnit`

| Method | |
|---|---|
| `issue(array\|Invoice $data, ?Model $owner = null): Document` | sign and store the next document of the chain |
| `documents(): HasMany` | the unit's documents |
| `continueAfter(string $signedXml): static` | set the chain position after a document issued elsewhere (call `save()`) |
| `previousHash(): string` | the PIH of the next document |
| `allows(Profile $profile): bool` | whether the unit may issue standard or simplified documents |
| `isOnboarded(): bool` | has a production CSID and a private key |
| `certificateExpiresAt(): ?Carbon` | expiry of the production CSID |
| `credentials()`, `complianceCredentials()` | `SigningCredentials` with the production or compliance CSID |
| `productionCsid()`, `complianceCsid()` | the CSIDs as zatca-api objects |

### `Document`

| Method | |
|---|---|
| `submit(): static` | clear or report, store the outcome |
| `buyerXml(): string` | cleared XML if any, otherwise the signed XML |
| `hasWarnings(): bool` | |
| `toSignedDocument(): SignedDocument` | for the stateless API |
| `unit()`, `owner()` | relations |
| `Document::pending()` | query scope: documents waiting for ZATCA |

`$document->status->isFinal()` and `isAccepted()` describe the status.

### `Onboarding`

| Method | |
|---|---|
| `generateCsr(EgsUnit $unit, array $options)` | new private key and CSR; refuses an onboarded unit |
| `requestComplianceCsid(EgsUnit $unit, string $otp)` | |
| `runComplianceChecks(EgsUnit $unit, ?array $samples = null)` | `ComplianceCheckResult` per sample label |
| `requestProductionCsid(EgsUnit $unit)` | |
| `renew(EgsUnit $unit, string $otp, ?array $options = null)` | new key and production CSID; the chain continues |

## Without the database

`InvoiceService` does the per-document work and keeps no state; the units use it internally. Use it directly if you
store the credentials and the chain yourself:

```php
use Sevaske\Zatca\Facades\Zatca;
use Sevaske\Zatca\Invoice\SigningCredentials;

$credentials = new SigningCredentials($productionCsid, $privateKeyPem);
$invoices = Zatca::invoices();                 // or Zatca::invoices('simulation')

$xml = $invoices->build($data);                // unsigned XML
$signed = $invoices->sign($data, $credentials);   // $data must contain its ICV and PIH
$result = $invoices->submit($signed, $credentials->csid);   // ClearanceResult or ReportingResult
$check = $invoices->check($signed, $complianceCsid);        // compliance check during onboarding
```

`SignedDocument` holds `xml`, `hash`, `uuid`, `qrCode`, `profile` and `type`. `report()` and `clear()` choose the
endpoint explicitly. `$credentials->keyMatchesCertificate()` checks that the key belongs to the certificate.

For any other endpoint, `Zatca::client()` returns the [zatca-api](https://github.com/sevaske/zatca-api#readme)
client.

## Configuration and commands

| Key | Env | Default | |
|---|---|---|---|
| `env` | `ZATCA_ENV` | `sandbox` | environment of `Zatca::unit()` and of the commands |
| `unit` | `ZATCA_UNIT` | `default` | key of the unit `Zatca::unit()` returns without an argument |
| `language` | `ZATCA_LANGUAGE` | `en` | language of ZATCA's validation messages: `en` or `ar` |
| `clearance_enabled` | `ZATCA_CLEARANCE_ENABLED` | `true` | set to `false` only while ZATCA has deactivated clearance for you: standard documents are then reported directly |
| `http.timeout` | `ZATCA_HTTP_TIMEOUT` | `60` | seconds |
| `http.connect_timeout` | `ZATCA_HTTP_CONNECT_TIMEOUT` | `10` | seconds |
| `database.connection` | `ZATCA_DB_CONNECTION` | the default connection | |
| `database.tables.units` | | `zatca_egs_units` | |
| `database.tables.documents` | | `zatca_documents` | |
| `models.unit`, `models.document` | | the package's models | your subclasses |

| Command | |
|---|---|
| `zatca:onboard {unit?}` | onboard a unit; continues an interrupted onboarding |
| `zatca:renew {unit?} --otp=` | renew the production CSID |
| `zatca:import {unit?}` | adopt a unit onboarded elsewhere and continue its chain |
| `zatca:units [--env=]` | list the units |

Every command accepts `--env`; `php artisan help <command>` lists all options.

## Testing your application

Bind an API client with a mock HTTP handler. No network is used:

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

Clients for other environments reuse these options, so the mock also answers for units in `simulation` or
`production`.

To assert the events without running your listeners, fake only the package's events. A bare `Event::fake()` also
silences Eloquent model events, including the guard that keeps signed documents unchanged:

```php
Event::fake([DocumentIssued::class, DocumentSubmitted::class]);

// ... issue and submit

Event::assertDispatched(DocumentSubmitted::class, fn ($event) => $event->document->status === DocumentStatus::Rejected);
```

Signing needs a private key and a certificate. Either onboard a unit in the sandbox once, or create one with a
self-signed certificate, as the package's own tests do in `tests/Fixtures/Credentials.php`.

The package's opt-in tests:

```bash
ZATCA_MYSQL=1 vendor/bin/pest --group=mysql      # parallel processes issuing for one unit, on MySQL (needs pcntl)
ZATCA_LIVE=1 vendor/bin/pest --group=sandbox     # onboarding, issuing and submitting in the ZATCA sandbox
```

Before pushing, `bin/ci` runs the GitHub Actions checks in Docker: the whole PHP × Laravel matrix with fresh
dependencies (prefer-lowest and prefer-stable), the MySQL test and PHPStan with Pint. It tests your working tree as
git sees it, uncommitted changes included and ignored files left out. Logs go to `build/ci/`. Only the Windows jobs
cannot run this way.

```bash
bin/ci                     # everything: about 2 minutes, the first run builds images for longer
bin/ci tests php8.5        # matrix jobs matching a regex; also: bin/ci mysql, bin/ci static
```

## Upgrading from 1.x

2.x is a rewrite on top of zatca-api v3 and php-zatca-xml v4. Credentials and the invoice chain now live in the
database, in EGS units.

1. Run `php artisan zatca:install` to publish the config and the migration.
2. Import your unit from its files and continue its chain, as described in
   [Adopting an existing unit](#adopting-an-existing-unit).
3. Replace the calls:

| 1.x | 2.x |
|---|---|
| CSR, key and credentials files (`Zatca::files()`) | an EGS unit in the database (`Zatca::unit()`), imported with `zatca:import` |
| ICV and PIH kept by the application | kept by the unit: `Zatca::unit()->issue($data)` |
| `api()->reporting(...)`, `api()->clearance(...)` | `$document->submit()` |
| `zatca:generate-csr`, `zatca:compliance-certificate`, `zatca:production-certificate` | `zatca:onboard` |
| `Zatca::api()` | `Zatca::client()` (`Sevaske\ZatcaApi\Client`) |
| `Http::zatca()` | removed |

The `storage.*` and `invoices_disk` config keys are gone. Rejections and "already reported" answers are now results
stored on the document, not exceptions.

## License

MIT. See [LICENSE](LICENSE).
