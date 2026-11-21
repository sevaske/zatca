<?php

use Illuminate\Database\QueryException;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Enums\DocumentType;
use Sevaske\Zatca\Enums\Profile;
use Sevaske\Zatca\Exceptions\ZatcaConfigurationException;
use Sevaske\Zatca\Facades\Zatca;
use Sevaske\Zatca\Invoice\InvoiceChain;
use Sevaske\Zatca\Models\Document;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;
use Sevaske\ZatcaApi\Invoice\InvoiceXml;

it('starts a new unit with ICV 1 and the initial hash', function () {
    $document = $this->unit()->issue(InvoiceData::make());

    expect($document->icv)->toBe(1)
        ->and($document->pih)->toBe(InvoiceChain::INITIAL_HASH)
        ->and($document->pih)->toBe(base64_encode(hash('sha256', '0')))
        ->and($document->xml)->toContain('<cbc:ID>ICV</cbc:ID>')
        ->and($document->xml)->toContain(InvoiceChain::INITIAL_HASH)
        ->and($document->status)->toBe(DocumentStatus::Pending);
});

it('links every document to the one generated before, across profiles and types', function () {
    $unit = $this->unit();

    $documents = [
        $unit->issue(InvoiceData::make('simplified')),
        $unit->issue(InvoiceData::make('standard')),
        $unit->issue(InvoiceData::make('simplified', 'credit')),
        $unit->issue(InvoiceData::make('standard', 'debit')),
    ];

    foreach ($documents as $i => $document) {
        expect($document->icv)->toBe($i + 1);

        if ($i > 0) {
            expect($document->pih)->toBe($documents[$i - 1]->hash);
        }
    }

    expect($unit->last_icv)->toBe(4)
        ->and($unit->last_hash)->toBe($documents[3]->hash)
        ->and($unit->fresh()->last_hash)->toBe($documents[3]->hash)
        ->and($documents[1]->profile)->toBe(Profile::Standard)
        ->and($documents[2]->type)->toBe(DocumentType::Credit)
        ->and($documents[2]->billing_reference)->toBe('INV-ORIGINAL');
});

it('keeps a rejected document in the chain: the corrected one follows it', function () {
    $unit = $this->unit();
    $rejected = $unit->issue(InvoiceData::make());
    $rejected->forceFill(['status' => DocumentStatus::Rejected])->save();

    $corrected = $unit->issue(InvoiceData::make());

    expect($corrected->icv)->toBe($rejected->icv + 1)
        ->and($corrected->pih)->toBe($rejected->hash)
        ->and($corrected->uuid)->not->toBe($rejected->uuid)
        ->and(Document::query()->count())->toBe(2);
});

it('replaces any ICV and PIH given in the data', function () {
    $document = $this->unit()->issue(InvoiceData::make(icv: 99, pih: base64_encode(str_repeat('x', 32))));

    expect($document->icv)->toBe(1)
        ->and($document->xml)->not->toContain('<cbc:UUID>99</cbc:UUID>')
        ->and(substr_count($document->xml, '<cbc:ID>ICV</cbc:ID>'))->toBe(1)
        ->and(substr_count($document->xml, '<cbc:ID>PIH</cbc:ID>'))->toBe(1);
});

it('fills in a UUID when the data has none', function () {
    $data = InvoiceData::make();
    unset($data['uuid']);

    expect($this->unit()->issue($data)->uuid)->toMatch('/^[0-9a-f-]{36}$/');
});

it('stores the XML byte for byte', function () {
    $document = $this->unit()->issue(InvoiceData::make());
    $stored = Document::query()->findOrFail($document->id);

    expect($stored->xml)->toBe($document->xml)
        ->and(InvoiceXml::hash($stored->xml))->toBe($stored->hash)
        ->and(InvoiceXml::uuid($stored->xml))->toBe($stored->uuid);
});

it('links the document to its owner', function () {
    $unit = $this->unit();
    $owner = $this->unit('owner-stand-in');

    $document = $unit->issue(InvoiceData::make(), $owner);

    expect($document->owner->is($owner))->toBeTrue()
        ->and($document->unit->is($unit))->toBeTrue();
});

it('uses no counter when the data is invalid', function () {
    $unit = $this->unit();
    $data = InvoiceData::make();
    $data['legalMonetaryTotal']['taxInclusiveAmount'] = 1.00;

    expect(fn () => $unit->issue($data))->toThrow(InvalidArgumentException::class)
        ->and($unit->fresh()->last_icv)->toBe(0)
        ->and(Document::query()->count())->toBe(0);
});

it('refuses a seller VAT number that is not the unit\'s', function () {
    $unit = $this->unit(attributes: ['vat_number' => '311111111100003']);

    expect(fn () => $unit->issue(InvoiceData::make()))->toThrow(InvalidArgumentException::class, 'is not the VAT number')
        ->and($unit->fresh()->last_icv)->toBe(0)
        ->and(Document::query()->count())->toBe(0);
});

it('refuses a profile the unit did not declare', function () {
    $unit = $this->unit(attributes: ['invoice_types' => '0100']);

    expect(fn () => $unit->issue(InvoiceData::make('standard')))->toThrow(InvalidArgumentException::class, 'may not issue standard documents');
});

it('rolls back the counter when storing the document fails', function () {
    $unit = $this->unit();
    $data = InvoiceData::make();
    $unit->issue($data);

    // the same UUID twice violates the unique index after signing
    expect(fn () => $unit->issue($data))->toThrow(QueryException::class)
        ->and($unit->fresh()->last_icv)->toBe(1)
        ->and(Document::query()->count())->toBe(1);
});

it('reads the chain position from the database, not from a stale model', function () {
    $unit = $this->unit();
    $stale = $unit->fresh();

    $first = $unit->issue(InvoiceData::make());
    $second = $stale->issue(InvoiceData::make());

    expect($second->icv)->toBe(2)
        ->and($second->pih)->toBe($first->hash)
        ->and($stale->last_icv)->toBe(2);
});

it('continues a chain started elsewhere', function () {
    $previous = $this->unit('old')->issue(InvoiceData::make());
    $unit = $this->unit('adopted')->continueAfter($previous->xml);
    $unit->save();

    $document = $unit->issue(InvoiceData::make());

    expect($document->icv)->toBe(2)
        ->and($document->pih)->toBe($previous->hash);
});

it('finds units by key in the configured environment', function () {
    $this->unit();
    $this->unit('store-15');
    $this->unit('store-15', ['environment' => 'simulation']);

    expect(Zatca::unit()->key)->toBe('default')
        ->and(Zatca::unit('store-15')->environment)->toBe('sandbox')
        ->and(fn () => Zatca::unit('missing'))->toThrow(ZatcaConfigurationException::class, 'zatca:onboard missing');
});
