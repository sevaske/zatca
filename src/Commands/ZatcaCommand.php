<?php

namespace Sevaske\Zatca\Commands;

use Illuminate\Console\Command;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Models\Models;
use Sevaske\ZatcaApi\Environment;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * Shared options of the zatca:* commands: `{unit?}` and `--env`.
 */
abstract class ZatcaCommand extends Command
{
    protected function environment(): string
    {
        $env = (string) ($this->option('env') ?: config('zatca.env'));

        if (! Environment::isValid($env)) {
            $this->abort("Unknown environment \"{$env}\". Expected one of: ".implode(', ', Environment::all()).'.');
        }

        return $env;
    }

    /**
     * Stop with an error (Command::fail() does not exist in Laravel 10).
     */
    protected function abort(string $message): never
    {
        throw new InvalidOptionException($message);
    }

    protected function unitKey(): string
    {
        return (string) ($this->argument('unit') ?: config('zatca.unit'));
    }

    /**
     * The unit with the given key in the chosen environment, or a new unsaved one.
     */
    protected function findOrNewUnit(): EgsUnit
    {
        return Models::unit()::query()->firstOrNew(['key' => $this->unitKey(), 'environment' => $this->environment()]);
    }

    /**
     * The option's value, or the answer to a question. Without interaction (`-n`, no terminal): the default.
     */
    protected function optionOrAsk(string $option, string $question, ?string $default = null): string
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            $value = $this->input->isInteractive() ? (string) $this->ask($question, $default) : (string) $default;
        }

        if (trim($value) === '') {
            $this->abort("Missing --{$option}.");
        }

        return trim($value);
    }

    protected function askVatNumber(?string $default = null): string
    {
        $vat = $this->optionOrAsk('vat', 'Seller VAT number (15 digits, starts and ends with 3)', $default);

        if (preg_match('/^3\d{13}3$/', $vat) !== 1) {
            $this->abort("Invalid VAT number \"{$vat}\": 15 digits, starting and ending with 3.");
        }

        return $vat;
    }

    protected function askInvoiceTypes(?string $default = null): string
    {
        $types = $this->optionOrAsk('invoice-types', 'Document types: 1000 standard, 0100 simplified, 1100 both', $default ?? '1100');

        if (! in_array($types, ['1000', '0100', '1100'], true)) {
            $this->abort("Invalid invoice types \"{$types}\". Expected 1000, 0100 or 1100.");
        }

        return $types;
    }

    /**
     * Subject of the CSR, from the options or prompts.
     *
     * @param  array<string, string>  $defaults
     * @return array{common_name: string, organization_name: string, organizational_unit: string, solution: string, model: string, serial: string, address: string, business_category: string, country: string}
     */
    protected function askCsrOptions(array $defaults = []): array
    {
        return [
            'organization_name' => $this->optionOrAsk('organization-name', 'Organization name (as registered)', $defaults['organization_name'] ?? null),
            'organizational_unit' => $this->optionOrAsk('organizational-unit', 'Branch name (for VAT groups: the TIN of the member)', $defaults['organizational_unit'] ?? null),
            'common_name' => $this->optionOrAsk('common-name', 'Common name of the unit', $defaults['common_name'] ?? null),
            'solution' => $this->optionOrAsk('solution', 'Solution name', $defaults['solution'] ?? null),
            'model' => $this->optionOrAsk('model', 'Model or version', $defaults['model'] ?? null),
            'serial' => $this->optionOrAsk('serial', 'Unique serial number of the unit', $defaults['serial'] ?? null),
            'address' => $this->optionOrAsk('address', 'Address of the branch', $defaults['address'] ?? null),
            'business_category' => $this->optionOrAsk('category', 'Business category', $defaults['business_category'] ?? null),
            'country' => $this->optionOrAsk('country', 'Country code', $defaults['country'] ?? 'SA'),
        ];
    }
}
