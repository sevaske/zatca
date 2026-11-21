<?php

namespace Sevaske\Zatca\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Sevaske\Zatca\Models\Document;
use Sevaske\Zatca\Models\Models;

/**
 * For your models that own ZATCA documents: orders, commissions, notes ...
 *
 * @mixin Model
 */
trait HasZatcaDocuments
{
    /**
     * Every document issued for this model, including rejected ones.
     *
     * @return MorphMany<Document, $this>
     */
    public function zatcaDocuments(): MorphMany
    {
        return $this->morphMany(Models::document(), 'owner');
    }

    /**
     * The most recently issued document (usually the one that counts after a rejection).
     *
     * @return MorphOne<Document, $this>
     */
    public function latestZatcaDocument(): MorphOne
    {
        return $this->morphOne(Models::document(), 'owner')->latestOfMany();
    }
}
