<?php

namespace Sevaske\Zatca\Commands;

use Sevaske\ZatcaApi\Exception\ZatcaException;

class ZatcaComplianceCertificate extends ZatcaOnboardingCommand
{
    public $signature = 'zatca:compliance-certificate
        {--env= : sandbox, simulation or production}
        {--disk= : filesystem disk of the files}
        {--csr= : path to the CSR on the disk}
        {--otp= : one-time password from the Fatoora portal}
        {--output= : path to save the credentials to}
        {--force : overwrite an existing file}';

    public $description = 'Request a compliance CSID from ZATCA using a CSR and an OTP.';

    public function handle(): int
    {
        $env = $this->chooseEnvironment();
        $disk = $this->chooseDisk();
        $csrPath = $this->optionOrAsk('csr', __('zatca::zatca.enter_csr_path'), config('zatca.storage.paths.csr'));

        $csr = $disk->exists($csrPath) ? $disk->get($csrPath) : null;

        if ($csr === null || trim($csr) === '') {
            $this->error(__('zatca::zatca.file_open_failed', ['path' => $csrPath]));

            return self::FAILURE;
        }

        $otp = $this->optionOrAsk('otp', __('zatca::zatca.enter_otp'), $env === 'sandbox' ? '123345' : null);

        try {
            $csid = $this->apiClient($env)->onboarding()->requestComplianceCsid($csr, $otp)->toComplianceCsid();
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $saved = $this->saveOrPrint(
            $disk,
            'output',
            __('zatca::zatca.enter_credentials_path_to_save'),
            config('zatca.storage.paths.compliance_credentials'),
            json_encode($csid->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            __('zatca::zatca.credentials_name'),
        );

        return $saved ? self::SUCCESS : self::FAILURE;
    }
}
