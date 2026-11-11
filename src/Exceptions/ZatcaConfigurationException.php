<?php

namespace Sevaske\Zatca\Exceptions;

use RuntimeException;
use Throwable;

class ZatcaConfigurationException extends RuntimeException
{
    public static function missingFile(string $what, string $path): self
    {
        return new self(sprintf('ZATCA %s not found at "%s". Run the zatca:* onboarding commands first.', $what, $path));
    }

    public static function invalidFile(string $what, string $path, ?Throwable $previous = null): self
    {
        return new self(sprintf('ZATCA %s at "%s" is invalid: %s', $what, $path, $previous?->getMessage() ?? 'unreadable'), 0, $previous);
    }
}
