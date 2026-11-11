<?php

namespace Sevaske\Zatca\Invoice;

use Sevaske\ZatcaApi\Invoice\SignedInvoice;

/**
 * A signed invoice, credit note or debit note, ready to be stored and submitted.
 *
 * Never modify {@see self::$xml}: any change breaks the hash and the signature.
 */
final class SignedDocument
{
    public function __construct(
        public readonly string $xml,
        public readonly string $hash,
        public readonly string $uuid,
        public readonly string $qrCode,
        public readonly bool $standard,
    ) {}

    /**
     * Standard (B2B) documents are cleared, simplified (B2C) documents are reported.
     */
    public function requiresClearance(): bool
    {
        return $this->standard;
    }

    public function toSignedInvoice(): SignedInvoice
    {
        return new SignedInvoice($this->xml, $this->hash, $this->uuid);
    }
}
