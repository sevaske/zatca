<?php

namespace Sevaske\Zatca\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;
use Sevaske\Zatca\Enums\DocumentStatus;
use Sevaske\Zatca\Enums\DocumentType;
use Sevaske\Zatca\Enums\Profile;
use Sevaske\Zatca\Events\DocumentSubmitted;
use Sevaske\Zatca\Invoice\DocumentXml;
use Sevaske\Zatca\Invoice\SignedDocument;
use Sevaske\Zatca\Zatca;
use Sevaske\ZatcaApi\Exception\ZatcaException;
use Sevaske\ZatcaApi\Response\ClearanceResult;
use Sevaske\ZatcaApi\Response\ReportingResult;
use Sevaske\ZatcaApi\Response\ValidationMessage;
use UnexpectedValueException;

/**
 * A signed invoice, credit note or debit note of an EGS unit, with its ZATCA outcome.
 *
 * The signed part (chain position, hash, XML) never changes. A rejected document stays as it is; the corrected
 * one is a new document.
 *
 * @property int $id
 * @property int $egs_unit_id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string $uuid
 * @property int $icv
 * @property string $pih
 * @property string $hash
 * @property string|null $number
 * @property Profile $profile
 * @property DocumentType $type
 * @property string|null $billing_reference
 * @property Carbon|null $issued_at
 * @property string $xml
 * @property string|null $cleared_xml
 * @property string $qr
 * @property DocumentStatus $status
 * @property list<array{code: string|null, category: string|null, message: string}>|null $errors
 * @property list<array{code: string|null, category: string|null, message: string}>|null $warnings
 * @property int $attempts
 * @property Carbon|null $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EgsUnit $unit
 * @property-read Model|null $owner
 */
class Document extends Model
{
    /** Columns fixed at signing. */
    protected const SIGNED = ['egs_unit_id', 'uuid', 'icv', 'pih', 'hash', 'profile', 'type', 'xml', 'qr'];

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $casts = [
        'icv' => 'integer',
        'profile' => Profile::class,
        'type' => DocumentType::class,
        'issued_at' => 'datetime',
        'status' => DocumentStatus::class,
        'errors' => 'array',
        'warnings' => 'array',
        'attempts' => 'integer',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            $signed = static::SIGNED;

            // the cleared invoice carries ZATCA's own QR code
            if ($document->isDirty('cleared_xml')) {
                $signed = array_diff($signed, ['qr']);
            }

            if ($document->isDirty($signed)) {
                throw new LogicException('A signed ZATCA document cannot be changed. Issue a new document instead.');
            }
        });
    }

    public function getTable(): string
    {
        return config('zatca.database.tables.documents') ?: parent::getTable();
    }

    public function getConnectionName(): ?string
    {
        return config('zatca.database.connection') ?: parent::getConnectionName();
    }

    /**
     * @return BelongsTo<EgsUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Models::unit(), 'egs_unit_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Clear (standard) or report (simplified) the document and store the outcome.
     *
     * Idempotent: a document ZATCA has already decided on is not sent again. Sending the same document twice is
     * safe anyway, ZATCA identifies it by its UUID and hash. Network and API errors are thrown after the attempt
     * is counted; retry them, typically from your queued job. Do not call it inside the transaction that issued
     * the document. When ZATCA has decided, a {@see DocumentSubmitted} event is dispatched.
     *
     * @throws ZatcaException on transport and API errors (the document stays pending)
     */
    public function submit(): static
    {
        if ($this->status->isFinal()) {
            return $this;
        }

        $this->forceFill(['attempts' => $this->attempts + 1, 'submitted_at' => now()])->save();

        $unit = $this->unit;
        $result = app(Zatca::class)
            ->invoices($unit->environment)
            ->submit($this->toSignedDocument(), $unit->productionCsid());

        $this->record($result)->save();

        event(new DocumentSubmitted($this));

        return $this;
    }

    /**
     * The XML for the buyer: ZATCA's cleared invoice for standard documents, the signed one otherwise.
     */
    public function buyerXml(): string
    {
        return $this->cleared_xml ?? $this->xml;
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    public function toSignedDocument(): SignedDocument
    {
        return new SignedDocument($this->xml, $this->hash, $this->uuid, $this->qr, $this->profile, $this->type);
    }

    /**
     * Documents still waiting for ZATCA. Simplified documents must be reported within 24 hours of issuing.
     *
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', DocumentStatus::Pending);
    }

    /**
     * Store ZATCA's answer.
     */
    protected function record(ClearanceResult|ReportingResult $result): static
    {
        $this->status = match (true) {
            $result instanceof ClearanceResult && $result->isCleared() => DocumentStatus::Cleared,
            $result instanceof ReportingResult && ($result->isReported() || $result->isAlreadyReported()) => DocumentStatus::Reported,
            $result->getHttpStatusCode() === 400 => DocumentStatus::Rejected,
            default => throw new UnexpectedValueException(sprintf(
                'Unexpected ZATCA answer for document %s: HTTP %d %s',
                $this->uuid,
                $result->getHttpStatusCode(),
                $result->getMessage(),
            )),
        };

        // "already reported" carries no validation results: keep those of the first submission
        if (! $result instanceof ReportingResult || ! $result->isAlreadyReported()) {
            $this->errors = self::messages($result->getValidationResults()->getErrorMessages());
            $this->warnings = self::messages($result->getValidationResults()->getWarningMessages());
        }

        if ($result instanceof ClearanceResult && $result->getClearedInvoice() !== null) {
            $this->cleared_xml = $result->getClearedInvoice();
            $this->qr = (new DocumentXml($this->cleared_xml))->qrCode() ?? $this->qr;
        }

        return $this;
    }

    /**
     * @param  list<ValidationMessage>  $messages
     * @return list<array{code: string|null, category: string|null, message: string}>|null
     */
    protected static function messages(array $messages): ?array
    {
        return array_map(fn (ValidationMessage $message) => [
            'code' => $message->getCode(),
            'category' => $message->getCategory(),
            'message' => $message->getMessage(),
        ], $messages) ?: null;
    }
}
