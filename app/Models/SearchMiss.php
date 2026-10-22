<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parola cercata che non ha trovato niente, con quante volte e quando.
 * Niente di chi l'ha cercata: è una misura della domanda, non delle persone.
 */
class SearchMiss extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_searched_at' => 'datetime', 'last_searched_at' => 'datetime', 'count' => 'integer'];
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
