<?php

namespace Sevaske\Zatca\Files;

use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Exception\InvalidArgumentException;

class ZatcaComplianceCredentials extends ZatcaCredentialsFile
{
    public function path(): string
    {
        return (string) config('zatca.storage.paths.compliance_credentials');
    }

    /**
     * @throws ZatcaConfigurationException when the file is missing or invalid
     */
    public function csid(): ComplianceCsid
    {
        if (! $this->exists()) {
            throw ZatcaConfigurationException::missingFile('compliance credentials', $this->path());
        }

        try {
            return ComplianceCsid::fromArray($this->toArray());
        } catch (InvalidArgumentException $e) {
            throw ZatcaConfigurationException::invalidFile('compliance credentials', $this->path(), $e);
        }
    }
}
