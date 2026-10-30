<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parola cercata che non ha trovato niente, con quante volte e quando.
 * Niente di chi l'ha cercata: è una misura della domanda, non delle persone.
 */
class SearchMiss extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    /**
     * Un termine cercato una volta o due, e poi più per tre mesi, non è una
     * domanda che il catalogo deve inseguire: senza questa potatura la tabella
     * cresce di una riga per ogni parola mai scritta.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('count', '<', 3)->where('last_searched_at', '<', now()->subDays(90));
    }

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
