<?php

use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Facades\Zatca;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;

/*
 * End to end against the ZATCA developer-portal sandbox (public test OTP): onboarding with the generated compliance
 * samples, then issuing and submitting real documents. Skipped by default:
 *
 *     ZATCA_LIVE=1 vendor/bin/pest --group=sandbox
 */
it('onboards a unit, issues and submits documents in the sandbox', function () {
    $unit = EgsUnit::query()->create(['key' => 'live', 'environment' => 'sandbox', 'vat_number' => '399999999900003', 'invoice_types' => '1100']);
    $onboarding = Zatca::onboarding();

    $onboarding->generateCsr($unit, [
        'organization_name' => 'Maximum Speed Tech Supply LTD',
        'organizational_unit' => 'Riyadh Branch',
        'common_name' => 'TST-886431145-399999999900003',
        'solution' => 'PHPZATCA',
        'model' => 'V3',
        'serial' => 'LARAVEL1',
        'address' => 'RRRD2929',
        'business_category' => 'Supply activities',
    ]);
    $onboarding->requestComplianceCsid($unit, '123345');

    foreach ($onboarding->runComplianceChecks($unit) as $label => $result) {
        expect($result->isPassed())->toBeTrue($label.': '.json_encode($result->getData()));
    }

    $onboarding->requestProductionCsid($unit);

    foreach (['simplified', 'standard'] as $profile) {
        $document = $unit->issue(InvoiceData::make($profile))->submit();

        expect($document->status->isAccepted())->toBeTrue(json_encode([$document->status, $document->errors]));
    }

    expect($unit->fresh()->last_icv)->toBe(2)
        ->and($unit->documents()->where('status', DocumentStatus::Cleared)->exists())->toBeTrue();
})->group('sandbox')->skip(! getenv('ZATCA_LIVE'), 'set ZATCA_LIVE=1 to run against the ZATCA sandbox');
