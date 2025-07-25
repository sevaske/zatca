<?php

namespace Sevaske\Zatca\Commands;

use JsonException;
use Sevaske\ZatcaApi\Exceptions\ZatcaException;

class ZatcaComplianceCertificate extends ZatcaOnboardingCommand
{
    public $signature = 'zatca:compliance-certificate';

    public $description = 'Request a compliance certificate from ZATCA using a CSR and OTP.';

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
        $csrPath = (string) $this->ask(__('zatca::zatca.enter_csr_path'), config('zatca.storage.paths.csr'));

        if (! $csr = $disk->get($csrPath)) {
            $this->error(__('zatca::zatca.file_open_failed', ['path' => $disk->path($csrPath)]));

            return self::FAILURE;
        }

        $otp = (string) $this->ask(__('zatca::zatca.enter_otp'), '123345');

        try {
            $api = $this->apiClient($env);
            $response = $api->complianceCertificate($csr, $otp);
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $credentials = json_encode([
            'certificate' => $response->certificate(),
            'secret' => $response->secret(),
            'requestId' => $response->requestId(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $this->info(__('zatca::zatca.credentials_label', ['label' => $credentials]));

        if ($this->confirm(__('zatca::zatca.confirm_saving_on_disk'), true)) {
            $outputPath = $this->askFilePathToPut(
                $disk,
                __('zatca::zatca.enter_credentials_path_to_save'),
                config('zatca.storage.paths.compliance_credentials')
            );

            if ($outputPath && ! $disk->put($outputPath, $credentials)) {
                $this->error(__('zatca::zatca.file_save_failed', ['path' => $disk->path($outputPath)]));
            }
        }

        $this->info(__('zatca::zatca.done'));

        return self::SUCCESS;
    }
}
