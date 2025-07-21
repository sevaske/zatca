<?php

namespace Sevaske\Zatca;

use Illuminate\Support\Traits\Macroable;
use Sevaske\Zatca\Contracts\ZatcaFilesContract;
use Sevaske\ZatcaApi\Api;

class Zatca
{
    use Macroable;

    public function __construct(protected Api $api, protected ZatcaFilesContract $files) {}

    public function api(): Api
    {
        return $this->api;
    }

    public function files(): ZatcaFilesContract
    {
        return $this->files;
    }
}
