<?php

namespace Sevaske\Zatca\Commands;

use RuntimeException;
use Saleh7\Zatca\Exceptions\CertificateBuilderException;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Exception\ZatcaException;

class RenewCommand extends ZatcaCommand
{
    protected $signature = 'zatca:renew
        {unit? : key of the unit (default: config zatca.unit)}
        {--env= : sandbox, simulation or production (default: config zatca.env)}
        {--otp= : renewal one-time password from the Fatoora portal}
        {--organization-name=} {--organizational-unit=} {--common-name=} {--solution=} {--model=} {--serial=}
        {--address=} {--category=} {--country=}';

    protected $description = 'Renew the production CSID of an EGS unit with a new private key. The invoice chain continues.';

    public function handle(Zatca $zatca): int
    {
        $unit = $this->findOrNewUnit();

        if (! $unit->isOnboarded()) {
            $this->components->error("EGS unit \"{$unit->key}\" ({$unit->environment}) is not onboarded.");

            return self::FAILURE;
        }

        // units imported from files have no stored CSR options: ask for them once
        $options = $unit->csr_options ?? $this->askCsrOptions();
        $otp = $this->optionOrAsk('otp', 'Renewal one-time password from the Fatoora portal');

        try {
            $zatca->onboarding()->renew($unit, $otp, $options);
        } catch (ZatcaException|CertificateBuilderException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'The CSID of EGS unit "%s" is renewed and valid until %s.',
            $unit->key,
            $unit->certificateExpiresAt()?->toDateString() ?? 'unknown',
        ));

        return self::SUCCESS;
    }
}
