<?php

namespace Sevaske\Zatca\Enums;

/**
 * Where a stored document stands with ZATCA.
 */
enum DocumentStatus: string
{
    /** Signed and stored, not accepted yet: never submitted, or the last attempt did not reach ZATCA. */
    case Pending = 'pending';

    /** A simplified document (or a standard one while clearance is deactivated) was accepted. */
    case Reported = 'reported';

    /** A standard document was accepted and stamped; hand the cleared XML to the buyer. */
    case Cleared = 'cleared';

    /** ZATCA found errors. The document stays in the chain; issue a corrected one as a new document. */
    case Rejected = 'rejected';

    /**
     * Whether ZATCA has decided. A final document is never submitted again.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isAccepted(): bool
    {
        return $this === self::Reported || $this === self::Cleared;
    }
}
