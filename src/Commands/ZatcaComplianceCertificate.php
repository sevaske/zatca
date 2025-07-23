<?php

namespace Sevaske\Zatca\Commands;

use GuzzleHttp\Client;
use Sevaske\ZatcaApi\Api;
use Sevaske\ZatcaApi\Enums\ZatcaEnvironmentEnum;
use Sevaske\ZatcaApi\Exceptions\ZatcaException;

class ZatcaComplianceCertificate extends ZatcaFileGenerating
{
    public $signature = 'zatca:compliance-certificate';

    public $description = '';

    public function handle(): int
    {
        $env = ZatcaEnvironmentEnum::from($this->choice(
            __('zatca::zatca.mode'),
            ['sandbox', 'simulation', 'production'],
            config('zatca.env'))
        );

        // api client
        $api = new Api($env->value, new Client([
            'base_uri' => $env->url(),
            'timeout' => 60,
            'verify' => true,
        ]));

        $disk = $this->chooseDisk();
        $path = $this->ask(__('zatca::zatca.path_csr'), config('zatca.storage.paths.csr'));

        if (! $csr = $disk->get($path)) {
            $this->error(__('zatca::zatca.csr_load_failed', ['path' => $disk->path($path)]));

            return self::FAILURE;
        }

        $otp = $this->ask(__('zatca::zatca.otp'));

        try {
            $response = $api->complianceCertificate($csr, $otp);
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('zatca::zatca.certificate_label', ['certificate' => $response->certificate()]));
        $this->info(__('zatca::zatca.secret_label', ['secret' => $response->secret()]));
        $this->info(__('zatca::zatca.request_id_label', ['request_id' => $response->requestId()]));

        if ($this->confirm(__('zatca::zatca.confirm_saving_on_disk'))) {
            $credentials = [
                'certificate' => $response->certificate(),
                'secret' => $response->secret(),
                'requestId' => $response->requestId(),
            ];

            $outputPath = $this->askFilePathToPut($disk, 'The path to the credentials file?', config('zatca.storage.paths.compliance_credentials'));

            // failed to save
            if (! $disk->put($outputPath, json_encode($credentials))) {
                $this->error(__('zatca::zatca.failed_to_save_file', ['path' => $disk->path($outputPath)]));
            }
        }

        $this->info(__('zatca::zatca.done'));

        return self::SUCCESS;
    }
}
