<?php

namespace Sevaske\Zatca\Commands;

use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\CertificateBuilderException;
use Sevaske\ZatcaApi\Enums\ZatcaEnvironmentEnum;

class ZatcaGenerateCsr extends ZatcaOnboardingCommand
{
    public $signature = 'zatca:generate-csr';

    public $description = 'Generate a CSR and private key for ZATCA e-invoicing integration.';

    public function handle(): int
    {
        $env = $this->choice(
            __('zatca::zatca.select_environment'),
            ['sandbox', 'simulation', 'production'],
            config('zatca.env')
        );

        $organizationId = $this->ask(__('zatca::zatca.organization_identifier'));
        $organizationName = $this->ask(__('zatca::zatca.organization_name'));
        $organizationCommonName = $this->ask(__('zatca::zatca.organization_common_name'));
        $organizationalUnitName = $this->ask(__('zatca::zatca.tax_identification_number'));
        $businessCategory = $this->ask(__('zatca::zatca.business_category'));
        $organizationCountry = $this->ask(__('zatca::zatca.organization_country'), 'SA');
        $organizationAddress = $this->ask(__('zatca::zatca.organization_address'));
        $invoiceType = $this->ask(__('zatca::zatca.invoice_type'), '1100');

        $deviceSolutionName = $this->ask(__('zatca::zatca.device_solution_name'));
        $deviceModel = $this->ask(__('zatca::zatca.device_model'));
        $deviceSerialNumber = $this->ask(__('zatca::zatca.device_serial'));

        try {
            $builder = (new CertificateBuilder)
                ->setOrganizationIdentifier($organizationId)
                ->setSerialNumber($deviceSolutionName, $deviceModel, $deviceSerialNumber)
                ->setCommonName($organizationCommonName)
                ->setCountryName($organizationCountry)
                ->setOrganizationName($organizationName)
                ->setOrganizationalUnitName($organizationalUnitName)
                ->setAddress($organizationAddress)
                ->setInvoiceType($invoiceType)
                ->setBusinessCategory($businessCategory)
                ->setProduction($env === ZatcaEnvironmentEnum::Production->value);

            $builder->generate();
        } catch (CertificateBuilderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $csr = $builder->getCsr();
            $privateKey = $builder->getPrivateKey();
        } catch (CertificateBuilderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('zatca::zatca.csr_label', ['label' => $csr]));
        $this->info(__('zatca::zatca.pem_label', ['label' => $privateKey]));

        if ($this->confirm(__('zatca::zatca.confirm_saving_on_disk'))) {
            $this->saveOnDisk($csr, $privateKey);
        }

        $this->info(__('zatca::zatca.done'));

        return self::SUCCESS;
    }

    protected function saveOnDisk(string $csr, string $privateKey): void
    {
        $disk = $this->chooseDisk();

        // ask path to the .csr file
        $csrPath = $this->askFilePathToPut(
            $disk,
            __('zatca::zatca.enter_path_for_csr'),
            config('zatca.storage.paths.csr')
        );

        // save .csr file
        if ($csrPath) {
            if ($disk->put($csrPath, $csr)) {
                $this->info(__('zatca::zatca.csr_label', ['label' => $disk->path($csrPath)]));
            } else {
                $this->error(__('zatca::zatca.file_save_failed', ['path' => $disk->path($csrPath)]));
            }
        }

        // ask path to the .pem file
        $privateKeyPath = $this->askFilePathToPut(
            $disk,
            __('zatca::zatca.enter_path_for_pem'),
            config('zatca.storage.paths.private_key')
        );

        // save .pem file
        if ($privateKeyPath) {
            if ($disk->put($privateKeyPath, $privateKey)) {
                $this->info(__('zatca::zatca.pem_label', ['label' => $disk->path($privateKeyPath)]));
            } else {
                $this->error(__('zatca::zatca.file_save_failed', ['path' => $disk->path($privateKeyPath)]));
            }
        }
    }
}
