<?php

use Illuminate\Support\Facades\Storage;
use Saleh7\Zatca\CertificateBuilder;
use Sevaske\Zatca\Invoice\SignedDocument;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;
use Sevaske\Zatca\Zatca;

/*
 * Live run against the ZATCA developer-portal sandbox (public test OTP).
 * Skipped by default: ZATCA_LIVE=1 vendor/bin/pest --group=sandbox
 */
it('onboards, signs and checks invoices in the sandbox', function () {
    Storage::fake('local');
    config()->set('zatca.storage.credentials_disk', 'local');
    $zatca = app(Zatca::class);

    $builder = (new CertificateBuilder)
        ->setOrganizationIdentifier('399999999900003')
        ->setSerialNumber('PHPZATCA', 'V3', 'LARAVEL1')
        ->setCommonName('TST-886431145-399999999900003')
        ->setCountryName('SA')
        ->setOrganizationName('Maximum Speed Tech Supply LTD')
        ->setOrganizationalUnitName('Riyadh Branch')
        ->setAddress('RRRD2929')
        ->setInvoiceType(1100)
        ->setProduction(false)
        ->setBusinessCategory('Supply activities')
        ->generate();

    $zatca->files()->privateKey()->put($builder->getPrivateKey());
    $csid = $zatca->client()->onboarding()->requestComplianceCsid($builder->getCsr(), '123345')->toComplianceCsid();
    $zatca->files()->complianceCredentials()->put($csid);

    foreach (['simplified', 'standard'] as $i => $kind) {
        $document = $zatca->invoices()->sign(InvoiceData::make($kind, $i + 1), $csid);

        expect($document)->toBeInstanceOf(SignedDocument::class)
            ->and($document->requiresClearance())->toBe($kind === 'standard');

        $result = $zatca->invoices()->check($document);

        expect($result->isPassed())->toBeTrue(json_encode($result->getData()));
    }
})->group('sandbox')->skip(fn () => ! getenv('ZATCA_LIVE'), 'set ZATCA_LIVE=1 to run against the ZATCA sandbox');
