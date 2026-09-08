<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipClick extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['clicked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Sponsorship, $this> */
    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class)->withTrashed();
    }
}
