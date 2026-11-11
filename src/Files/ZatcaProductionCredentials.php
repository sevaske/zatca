<?php

namespace Sevaske\Zatca\Files;

use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;
use Sevaske\ZatcaApi\Exception\InvalidArgumentException;

class ZatcaProductionCredentials extends ZatcaCredentialsFile
{
    public function path(): string
    {
        return (string) config('zatca.storage.paths.production_credentials');
    }

    /**
     * @throws ZatcaConfigurationException when the file is missing or invalid
     */
    public function csid(): ProductionCsid
    {
        if (! $this->exists()) {
            throw ZatcaConfigurationException::missingFile('production credentials', $this->path());
        }

        try {
            return ProductionCsid::fromArray($this->toArray());
        } catch (InvalidArgumentException $e) {
            throw ZatcaConfigurationException::invalidFile('production credentials', $this->path(), $e);
        }
    }
}
