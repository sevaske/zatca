<?php

namespace Sevaske\Zatca\Files;

class ZatcaCsr extends ZatcaFile
{
    use HasCredentialsDiskName;

    public function path(): string
    {
        return config('zatca.storage.paths.csr');
    }
}
