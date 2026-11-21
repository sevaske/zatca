<?php

namespace Sevaske\Zatca\Invoice;

use InvalidArgumentException;
use Saleh7\Zatca\Helpers\Certificate;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;

/**
 * What an EGS unit signs with: its CSID (certificate and secret) and the private key of its CSR.
 *
 * Sign compliance samples with the compliance CSID and real documents with the production CSID.
 */
final class SigningCredentials
{
    public function __construct(
        public readonly ComplianceCsid|ProductionCsid $csid,
        private readonly string $privateKey,
    ) {
        if (trim($privateKey) === '') {
            throw new InvalidArgumentException('The private key must not be empty.');
        }
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * Whether the private key belongs to the certificate. A wrong pair produces documents that ZATCA rejects
     * with signature errors, so check it when credentials come from outside (import, vault).
     */
    public function keyMatchesCertificate(): bool
    {
        return @openssl_x509_check_private_key($this->csid->getCertificatePem(), $this->privateKey) === true;
    }

    /**
     * @internal the signer of php-zatca-xml
     */
    public function toCertificate(): Certificate
    {
        return new Certificate($this->csid->getCertificate(), $this->privateKey, $this->csid->getSecret());
    }

    /**
     * Keeps the private key out of dumps and logs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['csid' => $this->csid, 'privateKey' => '[REDACTED]'];
    }
}
