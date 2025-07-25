<?php

namespace Sevaske\Zatca\Commands;

use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\CertificateBuilderException;
use Sevaske\ZatcaApi\Enums\ZatcaEnvironmentEnum;

class ZatcaGenerateCsr extends ZatcaFileGenerating
{
    public $signature = 'zatca:generate-csr';

    public $description = 'Generate a Certificate Signing Request (CSR) and private key for ZATCA integration.';

    public function handle(): int
    {
        $env = ZatcaEnvironmentEnum::from($this->choice(
            __('zatca::zatca.mode'),
            ['sandbox', 'simulation', 'production'],
            config('zatca.env'))
        );

        // organization
        $organizationId = $this->ask(__('zatca::zatca.organization_identifier'));
        $organizationName = $this->ask(__('zatca::zatca.organization_name'));
        $organizationCommonName = $this->ask(__('zatca::zatca.organization_common_name'));
        $organizationalUnitName = $this->ask(__('zatca::zatca.tax_identification_number'));
        $businessCategory = $this->ask(__('zatca::zatca.business_category'));
        $organizationCountry = $this->ask(__('zatca::zatca.organization_country'), 'SA');
        $organizationAddress = $this->ask(__('zatca::zatca.organization_address'));

        // 1100 - to be able to generate both types: simplified and standard
        $invoiceType = $this->ask(__('zatca::zatca.invoice_type'), '1100');

        // device
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
                ->setProduction($env->value === ZatcaEnvironmentEnum::Production->value);

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

        $this->info(__('zatca::zatca.csr_path', ['path' => $csr]));
        $this->info(__('zatca::zatca.key_path', ['path' => $privateKey]));

        if ($this->confirm(__('zatca::zatca.confirm_saving_on_disk'))) {
            $disk = $this->chooseDisk();

            // csr
            $csrPath = $this->askFilePathToPut(
                $disk,
                __('zatca::zatca.path_csr'),
                config('zatca.storage.paths.csr')
            );
            $csrFullPath = $disk->path($csrPath);

            if ($disk->put($csrPath, $csr)) {
                $this->info(__('zatca::zatca.csr_path', ['path' => $csrFullPath]));
            } else { // failed to save
                $this->error('Failed to save the CSR file. Path: '.$csrFullPath);
            }

            // pem
            $privateKeyPath = $this->askFilePathToPut(
                $disk,
                'Path to save the private key (.pem) file?',
                config('zatca.storage.paths.private_key')
            );
            $privateKeyFullPath = $disk->path($privateKeyPath);
            $disk->put($privateKeyPath, $privateKey);

            if ($disk->put($privateKeyPath, $csr)) {
                $this->info(__('zatca::zatca.csr_path', ['path' => $privateKeyFullPath]));
            } else { // failed to save
                $this->error('Failed to save the PEM file. Path: '.$privateKeyFullPath);
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
