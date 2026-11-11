<?php

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Sevaske\Zatca\Invoice\SignedDocument;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;
use Sevaske\ZatcaApi\Credentials\ProductionCsid;
use Sevaske\ZatcaApi\Response\ClearanceResult;
use Sevaske\ZatcaApi\Response\ReportingResult;

function document(bool $standard): SignedDocument
{
    return new SignedDocument('<Invoice/>', base64_encode(str_repeat('a', 32)), '3cf5ee18-ee25-44ea-a444-2c37ba7f28be', 'qr', $standard);
}

function mockApi(Response ...$responses): MockHandler
{
    $mock = new MockHandler($responses);
    app()->instance(Client::class, Client::sandbox(ClientOptions::create()->withHttpClient(
        new GuzzleClient(['handler' => HandlerStack::create($mock), 'allow_redirects' => false, 'http_errors' => false])
    )));

    return $mock;
}

function json(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('zatca.storage.credentials_disk', 'local');
    app(Zatca::class)->files()->productionCredentials()->put(new ProductionCsid(base64_encode('cert'), 'secret'));
});

it('reports simplified documents', function () {
    $mock = mockApi(json(200, ['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']]));

    $result = app(Zatca::class)->invoices()->submit(document(false));

    expect($result)->toBeInstanceOf(ReportingResult::class)->and($result->isReported())->toBeTrue()
        ->and((string) $mock->getLastRequest()->getUri())->toContain('/invoices/reporting/single');
});

it('clears standard documents', function () {
    $mock = mockApi(json(200, ['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode('<cleared/>')]));

    $result = app(Zatca::class)->invoices()->submit(document(true));

    expect($result)->toBeInstanceOf(ClearanceResult::class)->and($result->getClearedInvoice())->toBe('<cleared/>')
        ->and((string) $mock->getLastRequest()->getUri())->toContain('/invoices/clearance/single');
});

it('reports a standard document when clearance is deactivated', function () {
    mockApi(
        json(303, ['message' => 'Clearance is deactivated, use reporting']),
        json(200, ['reportingStatus' => 'REPORTED']),
    );

    expect(app(Zatca::class)->invoices()->submit(document(true)))->toBeInstanceOf(ReportingResult::class);
});

it('treats a rejection as a result, not an exception', function () {
    mockApi(json(400, ['reportingStatus' => 'NOT_REPORTED', 'validationResults' => [
        'status' => 'ERROR',
        'errorMessages' => [['type' => 'ERROR', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'bad address', 'status' => 'ERROR']],
    ]]));

    $result = app(Zatca::class)->invoices()->report(document(false));

    expect($result->isReported())->toBeFalse()
        ->and($result->getValidationResults()->getErrorCodes())->toBe(['BR-KSA-37']);
});
