<?php

namespace Sevaske\Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Saleh7\Zatca\Invoice;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Enums\Profile;
use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\Zatca\Invoice\DocumentXml;
use Sevaske\Zatca\Invoice\InvoiceChain;
use Sevaske\Zatca\Invoice\SigningCredentials;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;
use Sevaske\ZatcaApi\Invoice\InvoiceXml;

/**
 * An EGS unit: one device or software instance that issues documents for one seller (VAT number).
 *
 * It owns a private key, a CSID per onboarding stage and one invoice chain covering every document it generates.
 *
 * @property int $id
 * @property string $key
 * @property string $environment
 * @property string|null $name
 * @property string $vat_number
 * @property string $invoice_types 1000 standard, 0100 simplified, 1100 both
 * @property array<string, string>|null $csr_options
 * @property string|null $csr
 * @property string|null $private_key
 * @property array<string, string>|null $compliance_csid
 * @property array<string, string>|null $production_csid
 * @property int $last_icv
 * @property string|null $last_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EgsUnit extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['private_key', 'compliance_csid', 'production_csid'];

    protected $attributes = [
        'invoice_types' => '1100',
        'last_icv' => 0,
    ];

    protected $casts = [
        'csr_options' => 'array',
        'private_key' => 'encrypted',
        'compliance_csid' => 'encrypted:array',
        'production_csid' => 'encrypted:array',
        'last_icv' => 'integer',
    ];

    public function getTable(): string
    {
        return config('zatca.database.tables.units') ?: parent::getTable();
    }

    public function getConnectionName(): ?string
    {
        return config('zatca.database.connection') ?: parent::getConnectionName();
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Models::document(), 'egs_unit_id');
    }

    /**
     * Sign a document as the next link of this unit's chain and store it.
     *
     * You build the invoice data and the totals; the unit fills in the ICV and the PIH (replacing any given)
     * and a UUID when there is none. The counter, the hash and the document are written in one transaction
     * while the unit's row is locked, so concurrent requests and queue workers on any number of servers
     * get consecutive counters.
     *
     * Submit the returned document after the transaction commits: {@see Document::submit()}.
     *
     * @param  array<string, mixed>|Invoice  $invoice  data as accepted by php-zatca-xml's InvoiceMapper, or an Invoice
     * @param  Model|null  $owner  your model the document belongs to (an order, a note ...)
     *
     * @throws InvalidArgumentException when the data is invalid, the seller VAT number is not the unit's, or the
     *                                  unit may not issue this profile. Nothing is stored and no counter is used.
     */
    public function issue(array|Invoice $invoice, ?Model $owner = null): Document
    {
        if (is_array($invoice)) {
            $invoice['uuid'] ??= (string) Str::uuid();
        }

        $invoices = app(Zatca::class)->invoices($this->environment);
        $invoice = $invoices->invoice($invoice);
        $this->ensureAllows(Profile::from($invoice->getInvoiceType()?->getInvoice() ?? 'simplified'));

        return $this->getConnection()->transaction(function () use ($invoices, $invoice, $owner) {
            // other processes issuing for this unit wait here until the transaction commits
            /** @var static $unit */
            $unit = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $icv = $unit->last_icv + 1;
            $pih = $unit->previousHash();
            $signed = $invoices->sign(InvoiceChain::link($invoice, $icv, $pih), $unit->credentials());
            $unit->ensureSeller($signed->xml);

            /** @var Document $document */
            $document = $unit->documents()->make([
                'uuid' => $signed->uuid,
                'icv' => $icv,
                'pih' => $pih,
                'hash' => $signed->hash,
                'number' => $invoice->getId(),
                'profile' => $signed->profile,
                'type' => $signed->type,
                'billing_reference' => self::billingReference($invoice),
                'issued_at' => $invoice->getIssueDate(),
                'xml' => $signed->xml,
                'qr' => $signed->qrCode,
                'status' => DocumentStatus::Pending,
            ]);
            $document->owner()->associate($owner)->save();

            $unit->forceFill(['last_icv' => $icv, 'last_hash' => $signed->hash])->save();

            // keep the caller's model in step
            $this->forceFill($unit->only(['last_icv', 'last_hash', 'updated_at']))
                ->syncOriginalAttributes(['last_icv', 'last_hash', 'updated_at']);

            return $document->setRelation('unit', $this);
        });
    }

    /**
     * Continue a chain started elsewhere: the next document follows the given one.
     *
     * Pass the XML of the last document this unit generated (accepted or rejected), exactly as it was signed.
     */
    public function continueAfter(string $signedXml): static
    {
        $icv = (new DocumentXml($signedXml))->icv();

        if ($icv === null) {
            throw new InvalidArgumentException('The document has no invoice counter value (ICV).');
        }

        $this->forceFill(['last_icv' => $icv, 'last_hash' => InvoiceXml::hash($signedXml)]);

        return $this;
    }

    /**
     * PIH of the next document.
     */
    public function previousHash(): string
    {
        return $this->last_hash ?? InvoiceChain::INITIAL_HASH;
    }

    /**
     * Whether the CSR declared this profile: 1000 standard, 0100 simplified, 1100 both.
     */
    public function allows(Profile $profile): bool
    {
        return $this->invoice_types[$profile === Profile::Standard ? 0 : 1] === '1';
    }

    /**
     * The production CSID and the private key: signs real documents.
     */
    public function credentials(): SigningCredentials
    {
        return new SigningCredentials($this->productionCsid(), $this->privateKey());
    }

    /**
     * The compliance CSID and the private key: signs the samples of the compliance checks.
     */
    public function complianceCredentials(): SigningCredentials
    {
        return new SigningCredentials($this->complianceCsid(), $this->privateKey());
    }

    public function productionCsid(): ProductionCsid
    {
        return $this->production_csid
            ? ProductionCsid::fromArray($this->production_csid)
            : throw ZatcaConfigurationException::missing('production CSID', $this->key);
    }

    public function complianceCsid(): ComplianceCsid
    {
        return $this->compliance_csid
            ? ComplianceCsid::fromArray($this->compliance_csid)
            : throw ZatcaConfigurationException::missing('compliance CSID', $this->key);
    }

    public function isOnboarded(): bool
    {
        return ! empty($this->production_csid) && ! empty($this->private_key);
    }

    /**
     * When the production CSID expires; renew it before with `php artisan zatca:renew`.
     */
    public function certificateExpiresAt(): ?Carbon
    {
        if (empty($this->production_csid)) {
            return null;
        }

        $certificate = @openssl_x509_parse($this->productionCsid()->getCertificatePem());

        return isset($certificate['validTo_time_t']) ? Carbon::createFromTimestamp($certificate['validTo_time_t']) : null;
    }

    protected function ensureAllows(Profile $profile): void
    {
        if (! $this->allows($profile)) {
            throw new InvalidArgumentException(sprintf(
                'EGS unit "%s" may not issue %s documents (invoice types %s).',
                $this->key,
                $profile->value,
                $this->invoice_types,
            ));
        }
    }

    /**
     * ZATCA rejects a document whose seller is not the VAT number of the CSID: catch it before the chain moves.
     */
    protected function ensureSeller(string $xml): void
    {
        $seller = (new DocumentXml($xml))->sellerVatNumber();

        if ($seller !== $this->vat_number) {
            throw new InvalidArgumentException(sprintf(
                'The seller VAT number "%s" is not the VAT number "%s" of EGS unit "%s".',
                $seller,
                $this->vat_number,
                $this->key,
            ));
        }
    }

    /**
     * The invoice(s) a credit or debit note refers to.
     */
    protected static function billingReference(Invoice $invoice): ?string
    {
        $ids = array_map(fn ($reference) => $reference->getId(), $invoice->getBillingReferences() ?? []);

        return implode(', ', array_filter($ids)) ?: null;
    }

    protected function privateKey(): string
    {
        return $this->private_key ?: throw ZatcaConfigurationException::missing('private key', $this->key);
    }
}
