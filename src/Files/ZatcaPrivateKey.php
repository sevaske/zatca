<?php

namespace Sevaske\Zatca\Files;

class ZatcaPrivateKey extends ZatcaFile
{
    public function path(): string
    {
        return (string) config('zatca.storage.paths.private_key');
    }
}
