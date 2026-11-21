<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Facades\Zatca;
use Sevaske\Zatca\Tests\Fixtures\Credentials;
use Sevaske\Zatca\Tests\Fixtures\InvoiceData;
use Sevaske\ZatcaApi\Client;
use Sevaske\ZatcaApi\Exception\TransportException;
use Sevaske\ZatcaApi\Response\ReportingResult;

function zatcaJson(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
}

function rejection(string $statusKey): Response
{
    return zatcaJson(400, [$statusKey => str_starts_with($statusKey, 'clear') ? 'NOT_CLEARED' : 'NOT_REPORTED', 'validationResults' => [
        'status' => 'ERROR',
        'warningMessages' => [],
        'errorMessages' => [['type' => 'ERROR', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'bad address', 'status' => 'ERROR']],
    ]]);
}

it('reports a simplified document and stores the outcome', function () {
    $this->fakeZatca(zatcaJson(202, ['reportingStatus' => 'REPORTED', 'validationResults' => [
        'status' => 'WARNING',
        'warningMessages' => [['type' => 'WARNING', 'code' => 'BR-KSA-98', 'category' => 'KSA', 'message' => 'late', 'status' => 'WARNING']],
    ]]));

    $document = $this->unit()->issue(InvoiceData::make())->submit();

    expect($document->status)->toBe(DocumentStatus::Reported)
        ->and($document->hasWarnings())->toBeTrue()
        ->and($document->warnings[0]['code'])->toBe('BR-KSA-98')
        ->and($document->attempts)->toBe(1)
        ->and($document->submitted_at)->not->toBeNull()
        ->and($document->fresh()->status)->toBe(DocumentStatus::Reported)
        ->and((string) $this->requests[0]['request']->getUri())->toContain('/invoices/reporting/single');
});

it('clears a standard document and keeps ZATCA\'s cleared XML and QR code', function () {
    $issued = $this->unit()->issue(InvoiceData::make('standard'));
    $cleared = str_replace('<cbc:ID>QR</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.$issued->qr, '<cbc:ID>QR</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">ZATCA-QR', $issued->xml);
    $this->fakeZatca(zatcaJson(200, ['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode($cleared)]));

    $document = $issued->submit();

    expect($document->status)->toBe(DocumentStatus::Cleared)
        ->and($document->cleared_xml)->toBe($cleared)
        ->and($document->buyerXml())->toBe($cleared)
        ->and($document->qr)->toBe('ZATCA-QR')
        ->and($document->xml)->toBe($issued->xml)
        ->and((string) $this->requests[0]['request']->getUri())->toContain('/invoices/clearance/single');
});

it('marks a rejected document and keeps it in the chain', function () {
    $this->fakeZatca(rejection('reportingStatus'));
    $unit = $this->unit();

    $rejected = $unit->issue(InvoiceData::make())->submit();
    $next = $unit->issue(InvoiceData::make());

    expect($rejected->status)->toBe(DocumentStatus::Rejected)
        ->and($rejected->errors)->toBe([['code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'bad address']])
        ->and($next->pih)->toBe($rejected->hash);
});

it('does not send a document ZATCA has decided on again', function () {
    $mock = $this->fakeZatca(zatcaJson(200, ['reportingStatus' => 'REPORTED']));
    $document = $this->unit()->issue(InvoiceData::make())->submit();

    $document->submit();
    $document->fresh()->submit();

    expect($mock->count())->toBe(0)
        ->and($this->requests)->toHaveCount(1)
        ->and($document->fresh()->attempts)->toBe(1);
});

it('treats "already reported" as reported', function () {
    $this->fakeZatca(zatcaJson(409, ['reportingStatus' => 'REPORTED']));

    expect($this->unit()->issue(InvoiceData::make())->submit()->status)->toBe(DocumentStatus::Reported);
});

it('keeps the document pending on a network error and lets the caller retry', function () {
    $this->fakeZatca(
        new ConnectException('timeout', new Request('POST', '/')),
        zatcaJson(200, ['reportingStatus' => 'REPORTED']),
    );
    $document = $this->unit()->issue(InvoiceData::make());

    expect(fn () => $document->submit())->toThrow(TransportException::class)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Pending)
        ->and($document->fresh()->attempts)->toBe(1)
        ->and($document->submit()->status)->toBe(DocumentStatus::Reported)
        ->and($document->attempts)->toBe(2);
});

it('reports a standard document with Clearance-Status 0 when clearance is deactivated', function () {
    $this->fakeZatca(
        zatcaJson(303, ['message' => 'Clearance is deactivated, use reporting']),
        zatcaJson(200, ['reportingStatus' => 'REPORTED']),
    );

    $document = $this->unit()->issue(InvoiceData::make('standard'))->submit();

    expect($document->status)->toBe(DocumentStatus::Reported)
        ->and($document->cleared_xml)->toBeNull()
        ->and((string) $this->requests[1]['request']->getUri())->toContain('/invoices/reporting/single')
        ->and($this->requests[1]['request']->getHeaderLine('Clearance-Status'))->toBe('0');
});

it('reports standard documents directly while clearance is disabled', function () {
    $this->fakeZatca(zatcaJson(200, ['reportingStatus' => 'REPORTED']));
    $client = app(Client::class);
    $this->app->instance(Client::class, new Client('sandbox', $client->getOptions()->withClearanceEnabled(false)));

    $document = $this->unit()->issue(InvoiceData::make('standard'))->submit();

    expect($document->status)->toBe(DocumentStatus::Reported)
        ->and($this->requests)->toHaveCount(1)
        ->and((string) $this->requests[0]['request']->getUri())->toContain('/invoices/reporting/single')
        ->and($this->requests[0]['request']->getHeaderLine('Clearance-Status'))->toBe('0');
});

it('submits through the endpoint of the unit\'s environment', function () {
    $this->fakeZatca(zatcaJson(200, ['reportingStatus' => 'REPORTED']));

    $this->unit(attributes: ['environment' => 'simulation'])->issue(InvoiceData::make())->submit();

    expect((string) $this->requests[0]['request']->getUri())->toContain('/simulation/');
});

it('authenticates with the unit\'s own CSID', function () {
    $this->fakeZatca(zatcaJson(200, ['reportingStatus' => 'REPORTED']), zatcaJson(200, ['reportingStatus' => 'REPORTED']));

    $this->unit('a')->issue(InvoiceData::make())->submit();
    $this->unit('b')->issue(InvoiceData::make())->submit();

    expect($this->requests[0]['request']->getHeaderLine('Authorization'))->toBe(Credentials::production('a')->csid->getAuthorizationHeader())
        ->and($this->requests[1]['request']->getHeaderLine('Authorization'))->toBe(Credentials::production('b')->csid->getAuthorizationHeader());
});

it('signs and submits without the database, with explicit credentials', function () {
    $this->fakeZatca(zatcaJson(200, ['reportingStatus' => 'REPORTED']));
    $credentials = Credentials::production();

    $document = Zatca::invoices()->sign(InvoiceData::make(icv: 1), $credentials);
    $result = Zatca::invoices()->submit($document, $credentials->csid);

    expect($result)->toBeInstanceOf(ReportingResult::class)
        ->and($result->isReported())->toBeTrue()
        ->and($document->requiresClearance())->toBeFalse();
});
