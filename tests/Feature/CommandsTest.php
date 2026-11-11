<?php

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\ClientOptions;

beforeEach(function () {
    Storage::fake('local');
    config()->set('zatca.storage.credentials_disk', 'local');
});

function fakeZatca(Response ...$responses): void
{
    app()->instance(Client::class, Client::sandbox(ClientOptions::create()->withHttpClient(
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))])
    )));
}

it('generates a CSR and a private key without prompts', function () {
    $this->artisan('zatca:generate-csr', [
        '--env' => 'sandbox',
        '--disk' => 'local',
        '--organization-id' => '399999999900003',
        '--organization-name' => 'Maximum Speed Tech Supply LTD',
        '--common-name' => 'TST-886431145-399999999900003',
        '--unit-name' => 'Riyadh Branch',
        '--category' => 'Supply activities',
        '--country' => 'SA',
        '--address' => 'RRRD2929',
        '--invoice-type' => '1100',
        '--solution' => 'PHPZATCA',
        '--model' => 'V1',
        '--serial' => 'POS1',
        '--csr-output' => 'zatca/certificate.csr',
        '--key-output' => 'zatca/private_key.pem',
    ])->assertSuccessful();

    expect(Storage::disk('local')->get('zatca/certificate.csr'))->toContain('BEGIN CERTIFICATE REQUEST')
        ->and(Storage::disk('local')->get('zatca/private_key.pem'))->toContain('PRIVATE KEY');
});

it('requests the compliance CSID and saves it without printing the secret', function () {
    Storage::disk('local')->put('zatca/certificate.csr', "-----BEGIN CERTIFICATE REQUEST-----\nabc\n-----END CERTIFICATE REQUEST-----");
    fakeZatca(new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'requestID' => '1234567890',
        'dispositionMessage' => 'ISSUED',
        'binarySecurityToken' => base64_encode('certificate'),
        'secret' => 'top-secret-value',
    ])));

    $this->artisan('zatca:compliance-certificate', ['--env' => 'sandbox', '--disk' => 'local', '--csr' => 'zatca/certificate.csr', '--otp' => '123345', '--output' => 'zatca/compliance_credentials.json'])
        ->doesntExpectOutputToContain('top-secret-value')
        ->assertSuccessful();

    expect(app(Zatca::class)->files()->complianceCredentials()->csid()->getRequestId())->toBe('1234567890');
});

it('fails when the csr is missing', function () {
    $this->artisan('zatca:compliance-certificate', ['--env' => 'sandbox', '--disk' => 'local', '--csr' => 'zatca/missing.csr', '--otp' => '123345'])
        ->assertFailed();
});
