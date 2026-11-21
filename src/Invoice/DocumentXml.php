<?php

namespace Sevaske\Zatca\Invoice;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

/**
 * Reads values from a UBL invoice, credit note or debit note.
 *
 * @internal
 */
final class DocumentXml
{
    private DOMXPath $xpath;

    public function __construct(string $xml)
    {
        $dom = new DOMDocument;

        if (trim($xml) === '' || ! @$dom->loadXML($xml)) {
            throw new InvalidArgumentException('The document is not valid XML.');
        }

        $this->xpath = new DOMXPath($dom);
        $this->xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $this->xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    }

    public function icv(): ?int
    {
        $value = $this->value('/*/cac:AdditionalDocumentReference[cbc:ID="ICV"]/cbc:UUID');

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    public function qrCode(): ?string
    {
        return $this->value('/*/cac:AdditionalDocumentReference[cbc:ID="QR"]/cac:Attachment/cbc:EmbeddedDocumentBinaryObject');
    }

    public function sellerVatNumber(): ?string
    {
        return $this->value('/*/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
    }

    private function value(string $query): ?string
    {
        $value = trim((string) $this->xpath->evaluate("string({$query})"));

        return $value === '' ? null : $value;
    }
}
