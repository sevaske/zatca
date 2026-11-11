<?php

namespace Sevaske\Zatca\Commands;

use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\CertificateBuilderException;
use Sevaske\ZatcaApi\Environment;

class ZatcaGenerateCsr extends ZatcaOnboardingCommand
{
    public $signature = 'zatca:generate-csr
        {--env= : sandbox, simulation or production}
        {--disk= : filesystem disk to save the files to}
        {--organization-id= : VAT number}
        {--organization-name=}
        {--common-name=}
        {--unit-name= : organizational unit (branch name)}
        {--category= : business category}
        {--country= : two-letter country code}
        {--address=}
        {--invoice-type= : 1000 standard, 0100 simplified, 1100 both}
        {--solution= : solution name}
        {--model=}
        {--serial= : unique serial number of the EGS unit}
        {--csr-output= : path to save the CSR to}
        {--key-output= : path to save the private key to}
        {--force : overwrite existing files}';

    public $description = 'Generate a private key and a CSR for the ZATCA e-invoicing integration.';

    public function handle(): int
    {
        $env = $this->chooseEnvironment();

        try {
            $builder = (new CertificateBuilder)
                ->setOrganizationIdentifier($this->optionOrAsk('organization-id', __('zatca::zatca.organization_identifier')))
                ->setSerialNumber(
                    $this->optionOrAsk('solution', __('zatca::zatca.device_solution_name')),
                    $this->optionOrAsk('model', __('zatca::zatca.device_model')),
                    $this->optionOrAsk('serial', __('zatca::zatca.device_serial')),
                )
                ->setCommonName($this->optionOrAsk('common-name', __('zatca::zatca.organization_common_name')))
                ->setCountryName($this->optionOrAsk('country', __('zatca::zatca.organization_country'), 'SA'))
                ->setOrganizationName($this->optionOrAsk('organization-name', __('zatca::zatca.organization_name')))
                ->setOrganizationalUnitName($this->optionOrAsk('unit-name', __('zatca::zatca.organizational_unit_name')))
                ->setAddress($this->optionOrAsk('address', __('zatca::zatca.organization_address')))
                ->setInvoiceType((int) $this->optionOrAsk('invoice-type', __('zatca::zatca.invoice_type'), '1100'))
                ->setBusinessCategory($this->optionOrAsk('category', __('zatca::zatca.business_category')))
                ->setProduction($env === Environment::PRODUCTION)
                ->generate();

            $csr = $builder->getCsr();
            $privateKey = $builder->getPrivateKey();
        } catch (CertificateBuilderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $disk = $this->chooseDisk();

        $csrSaved = $this->saveOrPrint(
            $disk,
            'csr-output',
            __('zatca::zatca.enter_path_for_csr'),
            config('zatca.storage.paths.csr'),
            $csr,
            __('zatca::zatca.csr_name'),
        );

        $keySaved = $this->saveOrPrint(
            $disk,
            'key-output',
            __('zatca::zatca.enter_path_for_pem'),
            config('zatca.storage.paths.private_key'),
            $privateKey,
            __('zatca::zatca.pem_name'),
        );

        return $csrSaved && $keySaved ? self::SUCCESS : self::FAILURE;
    }
}
