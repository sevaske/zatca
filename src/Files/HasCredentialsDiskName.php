<?php

namespace Sevaske\Zatca\Files;

trait HasCredentialsDiskName
{
    public function diskName(): string
    {
        return (string) config('zatca.storage.credentials_disk');
    }
}
