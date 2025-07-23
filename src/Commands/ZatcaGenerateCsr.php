<?php

namespace Sevaske\Zatca\Commands;

use Illuminate\Support\Facades\Storage;
use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\CertificateBuilderException;

class ZatcaGenerateCsr extends ZatcaFileGenerating
{
    public $signature = 'zatca:generate-csr';

    public $description = 'Generate a Certificate Signing Request (CSR) and private key for ZATCA integration.';

    public function handle(): int
    {
        // env
        $environment = $this->choice('Mode', ['sandbox', 'simulation', 'production'], config('zatca.env'));

        // organization
        $organizationId = $this->ask('Organization Identifier (3*************3)');
        $organizationName = $this->ask('Organization Name');
        $organizationCommonName = $this->ask('Organization Common Name');
        $organizationalUnitName = $this->ask('Organizational Unit Name');
        $businessCategory = $this->ask('Business Category');
        $organizationCountry = $this->ask('Organization Country', 'SA');
        $organizationAddress = $this->ask('Organization Address');

        // 1100 - to be able to generate both types: simplified and standard
        $invoiceType = $this->ask('Invoice Type', '1100');

        // device
        $deviceSolutionName = $this->ask('Device Solution Name');
        $deviceModel = $this->ask('Device Model');
        $deviceSerialNumber = $this->ask('Device Serial Number');

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
                ->setProduction($environment === 'production');

            $builder->generate();
        } catch (CertificateBuilderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $diskName = $this->ask('Choose disk (only local driver)', config('zatca.storage.credentials_disk'));
        $disk = Storage::disk($diskName);

        // csr
        $csrPath = $this->askFilePathToPut(
            $disk,
            'Path to save the CSR file?',
            config('zatca.storage.paths.csr')
        );
        $csrFullPath = $disk->path($csrPath);
        $disk->put($csrPath, $builder->getCsr());

        // failed to save
        if (! $disk->exists($csrPath)) {
            $this->error('Failed to save the CSR file. Path: '.$csrFullPath);
        }

        // pem
        $privateKeyPath = $this->askFilePathToPut(
            $disk,
            'Path to save the private key (.pem) file?',
            config('zatca.storage.paths.private_key')
        );
        $privateKeyFullPath = $disk->path($privateKeyPath);
        $disk->put($privateKeyPath, $builder->getPrivateKey());

        // failed to save
        if (! $disk->exists($privateKeyPath)) {
            $this->error('Failed to save the CSR file. Path: '.$privateKeyPath);
        }

        $this->info('Done.');
        $this->info('CSR: '.$csrFullPath);
        $this->info('Private Key: '.$privateKeyFullPath);

        return self::SUCCESS;
    }
}
