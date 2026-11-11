<?php

use Illuminate\Support\Facades\Storage;
use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\Zatca\Invoice\InvoiceService;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;

beforeEach(function () {
    Storage::fake('local');
    config()->set('zatca.storage.credentials_disk', 'local');
});

it('resolves the main class and the api client from the container', function () {
    expect(app(Zatca::class)->client())->toBeInstanceOf(Client::class)
        ->and(app(Client::class)->getEnvironment())->toBe('sandbox');
});

it('reads and writes production credentials in the 3.x format', function () {
    $csid = new ProductionCsid(base64_encode('certificate'), 'secret');
    $file = app(Zatca::class)->files()->productionCredentials();

    $file->put($csid);

    expect($file->csid()->toArray())->toBe($csid->toArray())
        ->and($file->certificate())->toBe('certificate');
});

it('still reads credentials stored by 2.x', function () {
    $file = app(Zatca::class)->files()->productionCredentials();
    $file->put(['certificate' => 'certificate', 'secret' => 'secret', 'requestId' => '1']);

    expect($file->csid()->getCertificate())->toBe('certificate')
        ->and($file->csid()->getSecret())->toBe('secret');
});

it('explains what is missing', function () {
    app(Zatca::class)->files()->productionCredentials()->csid();
})->throws(ZatcaConfigurationException::class, 'production credentials not found');

it('is available through the facade', function () {
    expect(Sevaske\Zatca\Facades\Zatca::invoices())->toBeInstanceOf(InvoiceService::class)
        ->and(Sevaske\Zatca\Facades\Zatca::client())->toBeInstanceOf(Client::class);
});
