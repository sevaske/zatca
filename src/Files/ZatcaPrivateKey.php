<?php

namespace Sevaske\Zatca\Files;

class ZatcaPrivateKey extends ZatcaFile
{
    use HasCredentialsDiskName;

    public function path(): string
    {
        return config('zatca.storage.paths.private_key');
    }
}
