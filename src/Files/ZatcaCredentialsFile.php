<?php

namespace Sevaske\Zatca\Files;

use Illuminate\Contracts\Support\Arrayable;
use Sevaske\ZatcaApi\Responses\CertificateResponse;

abstract class ZatcaCredentialsFile extends ZatcaFile implements Arrayable
{
    use HasCredentialsDiskName;

    public function put(string|array|CertificateResponse $contents): bool
    {
        if ($contents instanceof CertificateResponse) {
            $contents = [
                'certificate' => $contents->certificate(),
                'secret' => $contents->secret(),
                'requestId' => $contents->requestId(),
            ];
        }

        if (is_array($contents)) {
            $contents = (string) json_encode($contents);
        }

        return parent::put($contents);
    }

    public function toArray(): array
    {
        return (array) json_decode((string) $this->get(), true);
    }

    public function certificate(): ?string
    {
        return $this->toArray()['certificate'] ?? null;
    }

    public function secret(): ?string
    {
        return $this->toArray()['secret'] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->toArray()['requestId'] ?? null;
    }
}
