<?php

namespace Sevaske\Zatca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sevaske\Zatca\Zatca
 */
class Zatca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sevaske\Zatca\Zatca::class;
    }
}
