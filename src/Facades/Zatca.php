<?php

namespace Sevaske\Zatca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Sevaske\Zatca\Models\EgsUnit unit(?string $key = null)
 * @method static \Sevaske\Zatca\Invoice\InvoiceService invoices(?string $environment = null)
 * @method static \Sevaske\Zatca\Onboarding\Onboarding onboarding()
 * @method static \Sevaske\ZatcaApi\Client client(?string $environment = null)
 *
 * @see \Sevaske\Zatca\Zatca
 */
class Zatca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sevaske\Zatca\Zatca::class;
    }
}
