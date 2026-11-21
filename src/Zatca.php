<?php

namespace Sevaske\Zatca;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Traits\Macroable;
use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\Zatca\Invoice\InvoiceService;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Models\Models;
use Sevaske\Zatca\Onboarding\Onboarding;
use Sevaske\ZatcaApi\Client;

class Zatca
{
    use Macroable;

    public function __construct(protected Container $container) {}

    /**
     * An EGS unit of the configured environment. Without a key: the default unit (`zatca.unit`).
     *
     * @throws ZatcaConfigurationException when there is no such unit
     */
    public function unit(?string $key = null): EgsUnit
    {
        $key ??= (string) config('zatca.unit');
        $environment = (string) config('zatca.env');

        return Models::unit()::query()->where('key', $key)->where('environment', $environment)->first()
            ?? throw ZatcaConfigurationException::unitNotFound($key, $environment);
    }

    /**
     * Build, sign and submit single documents with explicit credentials (no database).
     */
    public function invoices(?string $environment = null): InvoiceService
    {
        return new InvoiceService($this->client($environment));
    }

    /**
     * Onboard an EGS unit, run the compliance checks, renew its CSID.
     */
    public function onboarding(): Onboarding
    {
        return new Onboarding($this);
    }

    /**
     * The ZATCA API client of an environment (the configured one by default).
     *
     * Clients of other environments share the options (HTTP client, language) of the bound one.
     */
    public function client(?string $environment = null): Client
    {
        $client = $this->container->make(Client::class);

        if ($environment === null || $environment === $client->getEnvironment()) {
            return $client;
        }

        return new Client($environment, $client->getOptions());
    }
}
