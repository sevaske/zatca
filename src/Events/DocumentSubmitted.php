<?php

namespace Sevaske\Zatca\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Sevaske\Zatca\Models\Document;

/**
 * ZATCA decided on a document: it is cleared, reported or rejected (see $document->status, errors, warnings).
 *
 * Dispatched once per document, when its status becomes final. Not dispatched on network or API errors, the
 * document then stays pending and the submission is retried.
 */
final class DocumentSubmitted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Document $document) {}
}
