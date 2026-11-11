<?php

namespace Sevaske\Zatca\Commands;

use Sevaske\ZatcaApi\Credentials\ComplianceCsid;
use Sevaske\ZatcaApi\Exception\ZatcaException;

class ZatcaProductionCertificate extends ZatcaOnboardingCommand
{
    public $signature = 'zatca:production-certificate
        {--env= : sandbox, simulation or production}
        {--disk= : filesystem disk of the files}
        {--input= : path to the compliance credentials on the disk}
        {--output= : path to save the production credentials to}
        {--force : overwrite an existing file}';

    public $description = 'Exchange the compliance CSID for a production CSID (after the compliance checks are passed).';

    public function handle(): int
    {
        $env = $this->chooseEnvironment();
        $disk = $this->chooseDisk();
        $inputPath = $this->optionOrAsk(
            'input',
            __('zatca::zatca.enter_certificate_path'),
            config('zatca.storage.paths.compliance_credentials'),
        );

        $data = $disk->exists($inputPath) ? $disk->json($inputPath) : null;

        if (! is_array($data)) {
            $this->error(__('zatca::zatca.file_open_failed', ['path' => $inputPath]));

            return self::FAILURE;
        }

        try {
            $csid = $this->apiClient($env)->onboarding()
                ->requestProductionCsid(ComplianceCsid::fromArray($data))
                ->toProductionCsid();
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $saved = $this->saveOrPrint(
            $disk,
            'output',
            __('zatca::zatca.enter_credentials_path_to_save'),
            config('zatca.storage.paths.production_credentials'),
            json_encode($csid->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            __('zatca::zatca.credentials_name'),
        );

        return $saved ? self::SUCCESS : self::FAILURE;
    }
}
