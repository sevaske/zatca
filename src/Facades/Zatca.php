<?php

namespace Sevaske\Zatca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Sevaske\ZatcaApi\Client client()
 * @method static \Sevaske\Zatca\Contracts\ZatcaFilesContract files()
 * @method static \Sevaske\Zatca\Invoice\InvoiceService invoices()
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
