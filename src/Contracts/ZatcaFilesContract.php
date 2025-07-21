<?php

namespace Sevaske\Zatca\Contracts;

use Sevaske\Zatca\Files\ZatcaComplianceCredentials;
use Sevaske\Zatca\Files\ZatcaCsr;
use Sevaske\Zatca\Files\ZatcaPrivateKey;
use Sevaske\Zatca\Files\ZatcaProductionCredentials;

interface ZatcaFilesContract
{
    public function csr(): ZatcaCsr;

    public function privateKey(): ZatcaPrivateKey;

    public function complianceCredentials(): ZatcaComplianceCredentials;

    public function productionCredentials(): ZatcaProductionCredentials;
}
