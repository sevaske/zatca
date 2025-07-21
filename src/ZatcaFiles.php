<?php

namespace Sevaske\Zatca;

use Sevaske\Zatca\Contracts\ZatcaFilesContract;
use Sevaske\Zatca\Files\ZatcaComplianceCredentials;
use Sevaske\Zatca\Files\ZatcaCsr;
use Sevaske\Zatca\Files\ZatcaPrivateKey;
use Sevaske\Zatca\Files\ZatcaProductionCredentials;

class ZatcaFiles implements ZatcaFilesContract
{
    public function __construct(
        protected ZatcaCsr $csr,
        protected ZatcaPrivateKey $privateKey,
        protected ZatcaComplianceCredentials $compliance,
        protected ZatcaProductionCredentials $production,
    ) {}

    public function csr(): ZatcaCsr
    {
        return $this->csr;
    }

    public function privateKey(): ZatcaPrivateKey
    {
        return $this->privateKey;
    }

    public function complianceCredentials(): ZatcaComplianceCredentials
    {
        return $this->compliance;
    }

    public function productionCredentials(): ZatcaProductionCredentials
    {
        return $this->production;
    }
}
