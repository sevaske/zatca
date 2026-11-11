<?php

namespace Sevaske\Zatca;

use Closure;
use Illuminate\Support\Traits\Macroable;
use Sevaske\Zatca\Contracts\ZatcaFilesContract;
use Sevaske\Zatca\Invoice\InvoiceService;
use Sevaske\ZatcaApi\Client;

/**
 * @param  Closure(): Client  $client  resolves the API client on demand
 */
class Zatca
{
    use Macroable;

    public function __construct(
        protected Closure $client,
        protected ZatcaFilesContract $files,
        protected InvoiceService $invoices,
    ) {}

    /**
     * Low-level ZATCA API client (onboarding, compliance, invoices).
     */
    public function client(): Client
    {
        return ($this->client)();
    }

    /**
     * Stored CSR, private key and CSIDs.
     */
    public function files(): ZatcaFilesContract
    {
        return $this->files;
    }

    /**
     * Build, sign and submit documents.
     */
    public function invoices(): InvoiceService
    {
        return $this->invoices;
    }
}
