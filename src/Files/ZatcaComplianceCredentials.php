<?php

namespace Sevaske\Zatca\Files;

class ZatcaComplianceCredentials extends ZatcaCredentialsFile
{
    use HasCredentialsDiskName;

    public function path(): string
    {
        return config('zatca.storage.paths.compliance_credentials');
    }
}
