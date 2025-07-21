<?php

namespace Sevaske\Zatca\Files;

class ZatcaProductionCredentials extends ZatcaCredentialsFile
{
    use HasCredentialsDiskName;

    public function path(): string
    {
        return config('zatca.storage.paths.production_credentials');
    }
}
