<?php

namespace Sevaske\Zatca\Exceptions;

use RuntimeException;

/**
 * An EGS unit is missing or cannot do what was asked: not onboarded, wrong environment, unknown key.
 */
class ZatcaConfigurationException extends RuntimeException
{
    public static function unitNotFound(string $key, string $environment): self
    {
        return new self(sprintf(
            'ZATCA EGS unit "%1$s" not found in the %2$s environment. Onboard it with `php artisan zatca:onboard %1$s` '
            .'or import an existing one with `php artisan zatca:import %1$s`.',
            $key,
            $environment,
        ));
    }

    public static function missing(string $what, string $unit): self
    {
        return new self(sprintf(
            'ZATCA EGS unit "%1$s" has no %2$s. Finish onboarding with `php artisan zatca:onboard %1$s`.',
            $unit,
            $what,
        ));
    }
}
