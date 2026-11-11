<?php

namespace Sevaske\Zatca\Files;

use Illuminate\Contracts\Support\Arrayable;
use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;
use Sevaske\ZatcaApi\Response\CsidResponse;

/**
 * JSON file holding a CSID: `binarySecurityToken`, `secret` and (compliance only) `requestId`.
 *
 * Files written by 2.x (`certificate`, `secret`, `requestId`) are still readable.
 */
abstract class ZatcaCredentialsFile extends ZatcaFile implements Arrayable
{
    public function put(string|array|CsidResponse|ComplianceCsid|ProductionCsid $contents): bool
    {
        if ($contents instanceof CsidResponse) {
            $contents = [
                'binarySecurityToken' => $contents->getBinarySecurityToken(),
                'secret' => $contents->getSecret(),
                'requestId' => $contents->getRequestId(),
            ];
        } elseif ($contents instanceof ComplianceCsid || $contents instanceof ProductionCsid) {
            $contents = $contents->toArray();
        }

        if (is_array($contents)) {
            $contents = json_encode($contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        return parent::put($contents);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = json_decode((string) $this->get(), true);

        if (! is_array($data)) {
            return [];
        }

        // 2.x format: "certificate" is the decoded binary security token
        if (! isset($data['binarySecurityToken']) && isset($data['certificate'])) {
            $data['binarySecurityToken'] = base64_encode((string) $data['certificate']);
        }

        unset($data['certificate']);

        return $data;
    }

    public function binarySecurityToken(): ?string
    {
        return $this->toArray()['binarySecurityToken'] ?? null;
    }

    public function secret(): ?string
    {
        return $this->toArray()['secret'] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->toArray()['requestId'] ?? null;
    }

    /**
     * Signing certificate (decoded binary security token), or null if the file is missing.
     */
    public function certificate(): ?string
    {
        $token = $this->binarySecurityToken();

        return $token === null ? null : (string) base64_decode($token, true);
    }
}
