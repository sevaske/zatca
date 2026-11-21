<?php

namespace Sevaske\Zatca\Invoice;

use Saleh7\Zatca\AdditionalDocumentReference;
use Saleh7\Zatca\Attachment;
use Saleh7\Zatca\Invoice;

/**
 * The invoice chain of an EGS unit.
 *
 * Every document a unit generates (standard or simplified; invoice, credit or debit note) takes the next
 * invoice counter value (ICV) and, as previous invoice hash (PIH), the hash of the document generated
 * immediately before it, even if that one was rejected. Counter values are never reused.
 *
 * Source: ZATCA, "E-invoicing Detailed Technical Guideline", FAQ (pages 71-78).
 */
final class InvoiceChain
{
    /**
     * PIH of the first document of a unit: base64 of the hex SHA-256 of "0".
     *
     * @see https://github.com/sevaske/php-zatca-xml#the-invoice-chain
     */
    public const INITIAL_HASH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    /**
     * A copy of the invoice placed at the given position of the chain. ICV and PIH already present are replaced.
     */
    public static function link(Invoice $invoice, int $icv, string $pih): Invoice
    {
        $others = array_filter(
            $invoice->getAdditionalDocumentReferences() ?? [],
            fn (AdditionalDocumentReference $reference) => ! in_array($reference->getId(), ['ICV', 'PIH'], true),
        );

        return (clone $invoice)->setAdditionalDocumentReferences([
            (new AdditionalDocumentReference)->setId('ICV')->setUUID((string) $icv),
            (new AdditionalDocumentReference)->setId('PIH')->setAttachment(
                (new Attachment)->setBase64Content($pih, 'base64', 'text/plain')
            ),
            ...array_values($others),
        ]);
    }
}
