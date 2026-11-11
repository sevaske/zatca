<?php

namespace Sevaske\Zatca\Files;

class ZatcaCsr extends ZatcaFile
{
    public function path(): string
    {
        return (string) config('zatca.storage.paths.csr');
    }
}
