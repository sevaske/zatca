<?php

namespace Sevaske\Zatca\Invoice;

use Closure;
use Saleh7\Zatca\GeneratorInvoice;
use Saleh7\Zatca\Helpers\Certificate;
use Saleh7\Zatca\Invoice;
use Saleh7\Zatca\InvoiceSigner;
use Saleh7\Zatca\Mappers\InvoiceMapper;
use Sevaske\Zatca\Contracts\ZatcaFilesContract;
use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\ZatcaApi\Api\Invoices;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;
use Sevaske\ZatcaApi\Response\ClearanceResult;
use Sevaske\ZatcaApi\Response\ComplianceCheckResult;
use Sevaske\ZatcaApi\Response\ReportingResult;

/**
 * Builds, signs and submits ZATCA documents using the stored credentials and private key.
 *
 * Totals and the invoice chain (ICV / PIH) are NOT calculated here: pass them in the invoice data.
 */
/**
 * @param  Closure(): Client  $client  resolves the API client on demand
 */
class InvoiceService
{
    public function __construct(
        protected Closure $client,
        protected ZatcaFilesContract $files,
    ) {}

    /**
     * Map the invoice data to an unsigned UBL XML document.
     *
     * @param  array<string, mixed>|string|Invoice  $invoice  array / JSON as accepted by InvoiceMapper, or a ready Invoice
     *
     * @throws \InvalidArgumentException when required fields are missing or the totals do not add up
     */
    public function build(array|string|Invoice $invoice, bool $validate = true): string
    {
        return GeneratorInvoice::invoice($this->toInvoice($invoice, $validate))->getXML();
    }

    /**
     * Build and sign a document.
     *
     * Signs with the production CSID by default. Pass a compliance CSID to sign the samples of the
     * compliance checks during onboarding.
     *
     * @param  array<string, mixed>|string|Invoice  $invoice
     */
    public function sign(
        array|string|Invoice $invoice,
        ComplianceCsid|ProductionCsid|null $csid = null,
        bool $validate = true,
    ): SignedDocument {
        $invoice = $this->toInvoice($invoice, $validate);
        $csid ??= $this->files->productionCredentials()->csid();

        $signed = InvoiceSigner::signInvoice(
            GeneratorInvoice::invoice($invoice)->getXML(),
            new Certificate($csid->getCertificate(), $this->privateKey(), $csid->getSecret()),
        );

        return new SignedDocument(
            $signed->getXML(),
            $signed->getHash(),
            (string) $invoice->getUUID(),
            $signed->getQRCode(),
            $invoice->getInvoiceType()?->getInvoice() === 'standard',
        );
    }

    /**
     * Compliance check of a document signed with the compliance CSID (onboarding).
     */
    public function check(SignedDocument $document, ?ComplianceCsid $csid = null): ComplianceCheckResult
    {
        return ($this->client)()
            ->compliance($csid ?? $this->files->complianceCredentials()->csid())
            ->checkInvoice($document->toSignedInvoice());
    }

    /**
     * Report a simplified (B2C) document within 24 hours of issuing.
     */
    public function report(SignedDocument $document, ?ProductionCsid $csid = null): ReportingResult
    {
        return $this->invoices($csid)->report($document->toSignedInvoice());
    }

    /**
     * Clear a standard (B2B) document. On success hand ClearanceResult::getClearedInvoice() to the buyer.
     */
    public function clear(SignedDocument $document, ?ProductionCsid $csid = null): ClearanceResult
    {
        return $this->invoices($csid)->clear($document->toSignedInvoice());
    }

    /**
     * Clear standard documents and report simplified ones.
     *
     * When ZATCA answers that clearance is deactivated (HTTP 303) the standard document is reported instead.
     */
    public function submit(SignedDocument $document, ?ProductionCsid $csid = null): ClearanceResult|ReportingResult
    {
        if (! $document->requiresClearance()) {
            return $this->report($document, $csid);
        }

        $result = $this->clear($document, $csid);

        if ($result->isClearanceDisabled()) {
            return $this->report($document, $csid);
        }

        return $result;
    }

    protected function invoices(?ProductionCsid $csid): Invoices
    {
        return ($this->client)()->invoices($csid ?? $this->files->productionCredentials()->csid());
    }

    protected function privateKey(): string
    {
        $key = $this->files->privateKey()->get();

        if ($key === null || trim($key) === '') {
            throw ZatcaConfigurationException::missingFile('private key', $this->files->privateKey()->path());
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>|string|Invoice  $invoice
     */
    protected function toInvoice(array|string|Invoice $invoice, bool $validate): Invoice
    {
        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        return (new InvoiceMapper)->mapToInvoice($invoice, validateTotals: $validate, validateLines: $validate);
    }
}
