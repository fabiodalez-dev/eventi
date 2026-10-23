<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCollectionItem extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<EventCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(EventCollection::class, 'collection_id');
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }
}
