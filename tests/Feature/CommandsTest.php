<?php

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Sevaske\Zatca\Invoice\InvoiceChain;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Tests\Fixtures\Credentials;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;
use Symfony\Component\Console\Exception\InvalidOptionException;

function passed(): Response
{
    return zatcaJson(200, ['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED', 'clearanceStatus' => 'CLEARED']);
}

function onboardOptions(array $overrides = []): array
{
    return $overrides + [
        'unit' => 'riyadh',
        '--no-interaction' => true,
        '--otp' => '123345',
        '--vat' => '399999999900003',
        '--invoice-types' => '0100',
        '--organization-name' => 'Maximum Speed Tech Supply LTD',
        '--organizational-unit' => 'Riyadh Branch',
        '--common-name' => 'POS-0001',
        '--solution' => 'MyErp',
        '--model' => 'v1',
        '--serial' => 'POS-0001',
        '--address' => 'King Fahd Road, Riyadh',
        '--category' => 'Supply activities',
    ];
}

it('onboards a unit in one command', function () {
    $this->fakeZatca(
        zatcaJson(200, Credentials::response('compliance')),
        passed(), passed(), passed(),                           // simplified invoice, credit and debit note
        zatcaJson(200, Credentials::response('production')),
    );

    $this->artisan('zatca:onboard', onboardOptions())
        ->expectsOutputToContain('is onboarded')
        ->doesntExpectOutputToContain('secret-production')
        ->assertSuccessful();

    $unit = EgsUnit::query()->where('key', 'riyadh')->firstOrFail();

    expect($unit->environment)->toBe('sandbox')
        ->and($unit->isOnboarded())->toBeTrue()
        ->and($unit->csr)->toContain('CERTIFICATE REQUEST')
        ->and($unit->csr_options['serial'])->toBe('POS-0001')
        ->and($unit->productionCsid()->getSecret())->toBe('secret-production')
        ->and($unit->last_icv)->toBe(0)
        ->and($this->requests)->toHaveCount(5)
        ->and((string) $this->requests[1]['request']->getUri())->toContain('/compliance/invoices');
});

it('continues an interrupted onboarding where it stopped', function () {
    $this->fakeZatca(
        zatcaJson(200, Credentials::response('compliance')),
        passed(), passed(), zatcaJson(400, ['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-37', 'message' => 'bad address']]]]),
    );

    $this->artisan('zatca:onboard', onboardOptions())
        ->expectsOutputToContain('[BR-KSA-37] bad address')
        ->assertFailed();

    $this->fakeZatca(passed(), passed(), passed(), zatcaJson(200, Credentials::response('production')));

    $this->artisan('zatca:onboard', ['unit' => 'riyadh', '-n' => true])->assertSuccessful();

    expect(EgsUnit::query()->where('key', 'riyadh')->firstOrFail()->isOnboarded())->toBeTrue()
        ->and($this->requests)->toHaveCount(4 + 4); // no second CSR or compliance CSID
});

it('rejects an invalid VAT number before anything is sent', function () {
    $this->fakeZatca();

    expect(fn () => $this->artisan('zatca:onboard', onboardOptions(['--vat' => '12345']))->run())
        ->toThrow(InvalidOptionException::class, 'Invalid VAT number')
        ->and(EgsUnit::query()->count())->toBe(0);
});

it('does nothing for a unit that is onboarded already', function () {
    $this->unit('riyadh');

    $this->artisan('zatca:onboard', ['unit' => 'riyadh'])->expectsOutputToContain('onboarded already')->assertSuccessful();
});

it('renews the CSID with a new key and keeps the chain', function () {
    $unit = $this->unit('riyadh', ['csr_options' => [
        'organization_name' => 'Maximum Speed Tech Supply LTD', 'organizational_unit' => 'Riyadh Branch',
        'common_name' => 'POS-0001', 'solution' => 'MyErp', 'model' => 'v1', 'serial' => 'POS-0001',
        'address' => 'Riyadh', 'business_category' => 'Supply',
    ]]);
    $unit->issue(InvoiceData::make());
    $oldKey = $unit->credentials()->privateKey();
    $this->fakeZatca(zatcaJson(200, Credentials::response('renewed')));

    $this->artisan('zatca:renew', ['unit' => 'riyadh', '--otp' => '123456'])->assertSuccessful();

    $unit->refresh();
    expect($unit->productionCsid()->getSecret())->toBe('secret-renewed')
        ->and($unit->credentials()->privateKey())->not->toBe($oldKey)
        ->and($unit->last_icv)->toBe(1)
        ->and($this->requests[0]['request']->getMethod())->toBe('PATCH');
});

it('runs the compliance checks when the renewal asks for them', function () {
    $unit = $this->unit('riyadh', ['invoice_types' => '0100', 'csr_options' => [
        'organization_name' => 'Maximum Speed Tech Supply LTD', 'organizational_unit' => 'Riyadh Branch',
        'common_name' => 'POS-0001', 'solution' => 'MyErp', 'model' => 'v1', 'serial' => 'POS-0001',
        'address' => 'Riyadh', 'business_category' => 'Supply',
    ]]);
    $this->fakeZatca(
        zatcaJson(428, ['dispositionMessage' => 'NOT_COMPLIANT'] + Credentials::response('renewal-compliance')),
        passed(), passed(), passed(),
        zatcaJson(200, Credentials::response('renewed')),
    );

    $this->artisan('zatca:renew', ['unit' => 'riyadh', '--otp' => '123456'])->assertSuccessful();

    expect($unit->refresh()->productionCsid()->getSecret())->toBe('secret-renewed')
        ->and((string) $this->requests[1]['request']->getUri())->toContain('/compliance/invoices')
        ->and($this->requests[1]['request']->getHeaderLine('Authorization'))->toBe(Credentials::compliance('renewal-compliance')->csid->getAuthorizationHeader());
});

it('imports a unit from files and continues its chain', function () {
    Storage::fake('local');
    $credentials = Credentials::production('legacy');
    Storage::disk('local')->put('zatca/private_key.pem', $credentials->privateKey());
    Storage::disk('local')->put('zatca/production_credentials.json', json_encode($credentials->csid->toArray()));
    $lastHash = base64_encode(hash('sha256', 'document 1532', true));

    $this->artisan('zatca:import', ['unit' => 'bayt', '--env' => 'production', '--vat' => '399999999900003', '--icv' => 1532, '--hash' => $lastHash, '-n' => true])
        ->expectsOutputToContain('takes ICV 1533')
        ->assertSuccessful();

    $unit = EgsUnit::query()->where('key', 'bayt')->firstOrFail();

    expect($unit->environment)->toBe('production')
        ->and($unit->invoice_types)->toBe('1100')
        ->and($unit->last_icv)->toBe(1532)
        ->and($unit->previousHash())->toBe($lastHash)
        ->and($unit->credentials()->keyMatchesCertificate())->toBeTrue();
});

it('imports credentials written by 1.x', function () {
    Storage::fake('local');
    $credentials = Credentials::production('legacy');
    Storage::disk('local')->put('zatca/private_key.pem', $credentials->privateKey());
    Storage::disk('local')->put('zatca/production_credentials.json', json_encode([
        'certificate' => $credentials->csid->getCertificate(), 'secret' => 'secret-legacy', 'requestId' => '1',
    ]));

    $this->artisan('zatca:import', ['--vat' => '399999999900003', '-n' => true])->assertSuccessful();

    expect(EgsUnit::query()->firstOrFail()->previousHash())->toBe(InvoiceChain::INITIAL_HASH);
});

it('refuses to import a key that does not belong to the certificate', function () {
    Storage::fake('local');
    Storage::disk('local')->put('zatca/private_key.pem', Credentials::production('b')->privateKey());
    Storage::disk('local')->put('zatca/production_credentials.json', json_encode(Credentials::production('a')->csid->toArray()));

    $this->artisan('zatca:import', ['--vat' => '399999999900003', '-n' => true])
        ->expectsOutputToContain('does not belong to the certificate')
        ->assertFailed();

    expect(EgsUnit::query()->count())->toBe(0);
});

it('keeps the chain position when credentials are imported again', function () {
    Storage::fake('local');
    Storage::disk('local')->put('zatca/private_key.pem', Credentials::production()->privateKey());
    Storage::disk('local')->put('zatca/production_credentials.json', json_encode(Credentials::production()->csid->toArray()));
    $hash = base64_encode(hash('sha256', 'last', true));

    $this->artisan('zatca:import', ['--vat' => '399999999900003', '--icv' => 7, '--hash' => $hash, '-n' => true])->assertSuccessful();
    $this->artisan('zatca:import', ['--vat' => '399999999900003', '--force' => true, '-n' => true])->assertSuccessful();

    expect(EgsUnit::query()->firstOrFail()->last_icv)->toBe(7);

    EgsUnit::query()->firstOrFail()->issue(InvoiceData::make());

    $this->artisan('zatca:import', ['--vat' => '399999999900003', '--force' => true, '--icv' => 1, '--hash' => $hash, '-n' => true])
        ->expectsOutputToContain('cannot be changed')
        ->assertFailed();
});

it('refuses an ICV without the hash', function () {
    Storage::fake('local');
    Storage::disk('local')->put('zatca/private_key.pem', Credentials::production()->privateKey());
    Storage::disk('local')->put('zatca/production_credentials.json', json_encode(Credentials::production()->csid->toArray()));

    $this->artisan('zatca:import', ['--vat' => '399999999900003', '--icv' => 10, '-n' => true])->assertFailed();
});

it('lists the units', function () {
    $this->unit('riyadh')->issue(InvoiceData::make());

    expect(Artisan::call('zatca:units'))->toBe(0)
        ->and(Artisan::output())->toMatch('/riyadh\s+\|\s+sandbox\s+\|.*\|\s+onboarded\s+\|\s+1\s+\|\s+1\s+\|/');
});

it('publishes the migration and the config', function () {
    $this->artisan('vendor:publish', ['--tag' => ['zatca-migrations', 'zatca-config']])->assertSuccessful();

    $published = glob(database_path('migrations/*_create_zatca_tables.php'));

    expect($published)->toHaveCount(1)
        ->and(file_get_contents($published[0]))->toContain('zatca_egs_units')
        ->and(config_path('zatca.php'))->toBeFile();

    array_map('unlink', [...$published, config_path('zatca.php')]);
});
