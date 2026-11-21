<?php

namespace Sevaske\Zatca\Commands;

use Saleh7\Zatca\Exceptions\CertificateBuilderException;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Environment;
use Sevaske\ZatcaApi\Exception\ZatcaException;

class OnboardCommand extends ZatcaCommand
{
    protected $signature = 'zatca:onboard
        {unit? : key of the unit (default: config zatca.unit)}
        {--env= : sandbox, simulation or production (default: config zatca.env)}
        {--otp= : one-time password from the Fatoora portal, valid for one hour (sandbox: 123345)}
        {--name= : a label for the unit}
        {--vat= : seller VAT number}
        {--invoice-types= : 1000 standard, 0100 simplified, 1100 both}
        {--organization-name=}
        {--organizational-unit= : branch name (for VAT groups: the TIN of the member)}
        {--common-name=}
        {--solution= : solution name}
        {--model= : model or version}
        {--serial= : unique serial number of the unit}
        {--address=}
        {--category= : business category}
        {--country= : two-letter country code (default: SA)}
        {--fresh : start over with a new private key and CSR (only before the unit is onboarded)}';

    protected $description = 'Onboard an EGS unit: private key and CSR, compliance CSID, compliance checks, production CSID.';

    public function handle(Zatca $zatca): int
    {
        $unit = $this->findOrNewUnit();
        $onboarding = $zatca->onboarding();

        if ($unit->isOnboarded()) {
            $this->components->info("EGS unit \"{$unit->key}\" ({$unit->environment}) is onboarded already. Use zatca:renew to renew its CSID.");

            return self::SUCCESS;
        }

        try {
            if ($unit->csr === null || $this->option('fresh')) {
                $unit->name = $this->option('name') ?: $unit->name;
                $unit->vat_number = $this->askVatNumber($unit->vat_number);
                $unit->invoice_types = $this->askInvoiceTypes($unit->exists ? $unit->invoice_types : null);

                $onboarding->generateCsr($unit, $this->askCsrOptions($unit->csr_options ?? []));
                $this->components->info('Private key and CSR generated.');
            }

            if ($unit->compliance_csid === null) {
                $otp = $this->optionOrAsk('otp', 'One-time password from the Fatoora portal', $unit->environment === Environment::SANDBOX ? '123345' : null);
                $onboarding->requestComplianceCsid($unit, $otp);
                $this->components->info('Compliance CSID received.');
            }

            $failed = false;

            foreach ($onboarding->runComplianceChecks($unit) as $label => $result) {
                $failed = $failed || ! $result->isPassed();
                $this->components->twoColumnDetail("Compliance check: {$label}", $result->isPassed() ? '<fg=green>PASS</>' : '<fg=red>FAIL</>');

                foreach ($result->getValidationResults()->getErrorMessages() as $error) {
                    $this->line("    <fg=red>{$error}</>");
                }
            }

            if ($failed) {
                $this->components->error('Compliance checks failed. Fix the problems and run the command again; it continues from here.');

                return self::FAILURE;
            }

            $onboarding->requestProductionCsid($unit);
        } catch (ZatcaException|CertificateBuilderException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'EGS unit "%s" (%s) is onboarded. Its CSID is valid until %s.',
            $unit->key,
            $unit->environment,
            $unit->certificateExpiresAt()?->toDateString() ?? 'unknown',
        ));

        return self::SUCCESS;
    }
}
