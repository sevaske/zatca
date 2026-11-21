<?php

namespace Sevaske\Zatca\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Sevaske\Zatca\Models\Document;

/**
 * A document was signed, took its place in the unit's chain and was stored as pending.
 *
 * Dispatched after the transaction commits, including your own transaction around issue(): listeners never see
 * a document that is rolled back. A typical listener queues the submission.
 */
final class DocumentIssued implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Document $document) {}
}
