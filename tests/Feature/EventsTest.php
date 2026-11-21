<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Events\DocumentIssued;
use Sevaske\Zatca\Events\DocumentSubmitted;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;
use Sevaske\ZatcaApi\Exception\TransportException;

beforeEach(function () {
    $this->events = [];

    Event::listen([DocumentIssued::class, DocumentSubmitted::class], function (object $event) {
        $this->events[] = $event;
    });
});

it('announces an issued document once the transaction commits', function () {
    $unit = $this->unit();

    $document = DB::transaction(function () use ($unit) {
        $document = $unit->issue(InvoiceData::make());

        expect($this->events)->toBeEmpty();

        return $document;
    });

    expect($this->events)->toHaveCount(1)
        ->and($this->events[0])->toBeInstanceOf(DocumentIssued::class)
        ->and($this->events[0]->document->is($document))->toBeTrue()
        ->and($this->events[0]->document->status)->toBe(DocumentStatus::Pending);
});

it('announces nothing when the surrounding transaction rolls back', function () {
    $unit = $this->unit();

    try {
        DB::transaction(function () use ($unit) {
            $unit->issue(InvoiceData::make());

            throw new RuntimeException('order failed');
        });
    } catch (RuntimeException) {
    }

    expect($this->events)->toBeEmpty()
        ->and($unit->documents()->count())->toBe(0);
});

it('announces ZATCA\'s decision once', function () {
    $this->fakeZatca(zatcaJson(200, ['reportingStatus' => 'REPORTED']));
    $document = $this->unit()->issue(InvoiceData::make());
    $this->events = [];

    $document->submit();
    $document->submit();

    expect($this->events)->toHaveCount(1)
        ->and($this->events[0])->toBeInstanceOf(DocumentSubmitted::class)
        ->and($this->events[0]->document->status)->toBe(DocumentStatus::Reported);
});

it('announces a rejection with its errors', function () {
    $this->fakeZatca(rejection('reportingStatus'));
    $document = $this->unit()->issue(InvoiceData::make());
    $this->events = [];

    $document->submit();

    expect($this->events[0]->document->status)->toBe(DocumentStatus::Rejected)
        ->and($this->events[0]->document->errors[0]['code'])->toBe('BR-KSA-37');
});

it('announces nothing while the document stays pending', function () {
    $this->fakeZatca(new ConnectException('timeout', new Request('POST', '/')));
    $document = $this->unit()->issue(InvoiceData::make());
    $this->events = [];

    expect(fn () => $document->submit())->toThrow(TransportException::class)
        ->and($this->events)->toBeEmpty();
});
