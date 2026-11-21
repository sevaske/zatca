<?php

namespace Sevaske\Zatca\Invoice;

use Saleh7\Zatca\GeneratorInvoice;
use Saleh7\Zatca\Invoice;
use Saleh7\Zatca\InvoiceSigner;
use Saleh7\Zatca\Mappers\InvoiceMapper;
use Sevaske\Zatca\Enums\DocumentType;
use Sevaske\Zatca\Enums\Profile;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;
use Sevaske\ZatcaApi\Response\ClearanceResult;
use Sevaske\ZatcaApi\Response\ComplianceCheckResult;
use Sevaske\ZatcaApi\Response\ReportingResult;

/**
 * Builds, signs and submits single documents with the credentials you pass. Keeps no state.
 *
 * Most applications use an EGS unit instead ({@see EgsUnit::issue()}), which also keeps
 * the invoice chain and stores the documents.
 */
class InvoiceService
{
    public function __construct(protected Client $client) {}

    /**
     * Map invoice data to a php-zatca-xml Invoice.
     *
     * @param  array<string, mixed>|string|Invoice  $invoice  array or JSON as accepted by InvoiceMapper, or a ready Invoice
     *
     * @throws \InvalidArgumentException when required fields are missing or the totals do not add up
     */
    public function invoice(array|string|Invoice $invoice, bool $validate = true): Invoice
    {
        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        return (new InvoiceMapper)->mapToInvoice($invoice, validateTotals: $validate, validateLines: $validate);
    }

    /**
     * The unsigned UBL XML.
     *
     * @param  array<string, mixed>|string|Invoice  $invoice
     */
    public function build(array|string|Invoice $invoice, bool $validate = true): string
    {
        return GeneratorInvoice::invoice($this->invoice($invoice, $validate))->getXML();
    }

    /**
     * Build and sign a document. The data must already contain its ICV and PIH.
     *
     * @param  array<string, mixed>|string|Invoice  $invoice
     */
    public function sign(array|string|Invoice $invoice, SigningCredentials $credentials, bool $validate = true): SignedDocument
    {
        $invoice = $this->invoice($invoice, $validate);
        $signed = InvoiceSigner::signInvoice(GeneratorInvoice::invoice($invoice)->getXML(), $credentials->toCertificate());

        return new SignedDocument(
            $signed->getXML(),
            $signed->getHash(),
            (string) $invoice->getUUID(),
            $signed->getQRCode(),
            Profile::from($invoice->getInvoiceType()?->getInvoice() ?? 'simplified'),
            DocumentType::from($invoice->getInvoiceType()?->getInvoiceType() ?? 'invoice'),
        );
    }

    /**
     * Compliance check of a sample signed with the compliance CSID (onboarding).
     */
    public function check(SignedDocument $document, ComplianceCsid $csid): ComplianceCheckResult
    {
        return $this->client->compliance($csid)->checkInvoice($document->toSignedInvoice());
    }

    /**
     * Report a simplified (B2C) document within 24 hours of issuing it.
     */
    public function report(SignedDocument $document, ProductionCsid $csid): ReportingResult
    {
        return $this->client->invoices($csid)->report($document->toSignedInvoice());
    }

    /**
     * Clear a standard (B2B) document. On success hand ClearanceResult::getClearedInvoice() to the buyer.
     */
    public function clear(SignedDocument $document, ProductionCsid $csid): ClearanceResult
    {
        return $this->client->invoices($csid)->clear($document->toSignedInvoice());
    }

    /**
     * Clear standard documents, report simplified ones.
     *
     * While clearance is deactivated (config, or HTTP 303 from ZATCA) standard documents are reported, with the
     * Clearance-Status header set to 0 as ZATCA expects.
     */
    public function submit(SignedDocument $document, ProductionCsid $csid): ClearanceResult|ReportingResult
    {
        if (! $document->requiresClearance()) {
            return $this->report($document, $csid);
        }

        if ($this->client->getOptions()->isClearanceEnabled()) {
            $result = $this->clear($document, $csid);

            if (! $result->isClearanceDisabled()) {
                return $result;
            }
        }

        $client = new Client($this->client->getEnvironment(), $this->client->getOptions()->withClearanceEnabled(false));

        return $client->invoices($csid)->report($document->toSignedInvoice());
    }
}
