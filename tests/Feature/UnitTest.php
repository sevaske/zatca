<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Sevaske\Zatca\Concerns\HasZatcaDocuments;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\Zatca\Invoice\SigningCredentials;
use Sevaske\Zatca\Models\EgsUnit;
use Sevaske\Zatca\Tests\Fixtures\Credentials;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;

it('signs with each unit\'s own certificate and key', function () {
    $a = $this->unit('a')->issue(InvoiceData::make());
    $b = $this->unit('b')->issue(InvoiceData::make());

    expect($a->xml)->toContain(Credentials::production('a')->csid->getCertificate())
        ->and($b->xml)->toContain(Credentials::production('b')->csid->getCertificate())
        ->and($b->xml)->not->toContain(Credentials::production('a')->csid->getCertificate());
});

it('keeps an independent chain per unit', function () {
    $a = $this->unit('a');
    $b = $this->unit('b');

    $a->issue(InvoiceData::make());
    $a->issue(InvoiceData::make());

    expect($b->issue(InvoiceData::make())->icv)->toBe(1)
        ->and($a->issue(InvoiceData::make())->icv)->toBe(3);
});

it('encrypts the private key and the CSIDs at rest and hides them from arrays', function () {
    $unit = $this->unit();
    $row = DB::table('zatca_egs_units')->first();

    expect($row->private_key)->not->toContain('PRIVATE KEY')
        ->and($row->production_csid)->not->toContain('secret')
        ->and($unit->fresh()->credentials()->privateKey())->toContain('PRIVATE KEY')
        ->and($unit->toArray())->not->toHaveKeys(['private_key', 'production_csid', 'compliance_csid'])
        ->and(print_r($unit->credentials(), true))->not->toContain('PRIVATE KEY');
});

it('explains what a unit is missing', function () {
    $unit = EgsUnit::query()->create(['key' => 'new', 'environment' => 'sandbox', 'vat_number' => '399999999900003']);

    expect($unit->isOnboarded())->toBeFalse()
        ->and(fn () => $unit->credentials())->toThrow(ZatcaConfigurationException::class, 'has no production CSID');
});

it('detects a private key that does not belong to the certificate', function () {
    $mismatched = new SigningCredentials(Credentials::production('a')->csid, Credentials::production('b')->privateKey());

    expect(Credentials::production('a')->keyMatchesCertificate())->toBeTrue()
        ->and($mismatched->keyMatchesCertificate())->toBeFalse();
});

it('reads the expiry of the production CSID', function () {
    expect($this->unit()->certificateExpiresAt()?->isFuture())->toBeTrue();
});

it('never changes the signed part of a document', function () {
    $document = $this->unit()->issue(InvoiceData::make());

    expect(fn () => $document->update(['xml' => '<Invoice/>']))->toThrow(LogicException::class)
        ->and(fn () => $document->fresh()->update(['icv' => 7]))->toThrow(LogicException::class);

    $document->fresh()->update(['status' => DocumentStatus::Rejected]);

    expect($document->fresh()->status)->toBe(DocumentStatus::Rejected);
});

it('gives owners their documents', function () {
    $owner = new class extends Model
    {
        use HasZatcaDocuments;

        protected $table = 'zatca_egs_units';
    };
    $unit = $this->unit();
    $owner = $owner->newQuery()->findOrFail($unit->id);

    $first = $unit->issue(InvoiceData::make(), $owner);
    $second = $unit->issue(InvoiceData::make(), $owner);

    expect($owner->zatcaDocuments()->count())->toBe(2)
        ->and($owner->latestZatcaDocument->id)->toBe($second->id)
        ->and($first->owner_type)->toBe($owner::class);
});

it('uses the configured table names', function () {
    config()->set('zatca.database.tables.units', 'custom_units');

    expect((new EgsUnit)->getTable())->toBe('custom_units');
});
