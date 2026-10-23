<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\EventFilters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una ricerca da rifare in automatico: i filtri come li scrive l'URL di
 * `/eventi`, più un nome che la persona riconosce. La finestra temporale non
 * si conserva: «questo weekend» salvato a settembre non vuol dire settembre,
 * vuol dire «le date nuove che corrispondono», e quelle le trova il comando.
 */
class SavedSearch extends Model
{
    /** Quante ricerche può tenere una persona: oltre, non sono più ricerche ma rumore. */
    public const MAX_PER_USER = 10;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filters' => 'array', 'last_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** I filtri senza finestra temporale: ciò che il comando applica alle date future. */
    public function filters(): EventFilters
    {
        $stored = $this->filters ?? [];
        unset($stored['date'], $stored['from'], $stored['to'], $stored['discovery'], $stored['sort']);

        return EventFilters::fromArray($stored);
    }

    /** @return array<string, string> */
    public function queryString(): array
    {
        return $this->filters()->toQueryString();
    }

    /**
     * Toglie ciò che non ha senso conservare e dice se resta qualcosa da
     * cercare: una ricerca senza nessun criterio corrisponderebbe a tutto.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalise(array $input): array
    {
        $filters = EventFilters::fromArray($input)->toQueryString();
        unset($filters['date'], $filters['from'], $filters['to'], $filters['discovery'], $filters['sort'], $filters['lat'], $filters['lng'], $filters['radius']);

        return $filters;
    }
}
