<?php

namespace Sevaske\Zatca\Commands;

use JsonException;
use Sevaske\ZatcaApi\Exceptions\ZatcaException;

class ZatcaProductionCertificate extends ZatcaOnboardingCommand
{
    public $signature = 'zatca:production-certificate';

    public $description = 'Issues an X509 Production Cryptographic Stamp Identifier (PCSID) based on submitted CSR.';

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $env = $this->choice(
            __('zatca::zatca.select_environment'),
            ['sandbox', 'simulation', 'production'],
            config('zatca.env')
        );
        $disk = $this->chooseDisk();
        $complianceCertificatePath = (string) $this->ask(
            __('zatca::zatca.enter_certificate_path'),
            config('zatca.storage.paths.compliance_credentials')
        );

        if (! $credentials = $disk->json($complianceCertificatePath)) {
            $this->error(__('zatca::zatca.file_open_failed', ['path' => $disk->path($complianceCertificatePath)]));

            return self::FAILURE;
        }

        $complianceRequestId = $this->ask('Compliance Request ID', $credentials['requestId'] ?? null);

        try {
            $api = $this->apiClient($env, $credentials['certificate'], $credentials['secret']);
            $response = $api->productionCertificate($complianceRequestId);
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $productionCredentials = json_encode([
            'certificate' => $response->certificate(),
            'secret' => $response->secret(),
            'requestId' => $response->requestId(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $this->info(__('zatca::zatca.credentials_label', ['label' => $productionCredentials]));

        if ($this->confirm(__('zatca::zatca.confirm_saving_on_disk'), true)) {
            $outputPath = $this->askFilePathToPut(
                $disk,
                __('zatca::zatca.enter_credentials_path_to_save'),
                config('zatca.storage.paths.production_credentials')
            );

            if ($outputPath && ! $disk->put($outputPath, $productionCredentials)) {
                $this->error(__('zatca::zatca.file_save_failed', ['path' => $disk->path($outputPath)]));
            }
        }

        $this->info(__('zatca::zatca.done'));

        return self::SUCCESS;
    }
}
