<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Un cookie dichiarato nell'informativa.
 *
 * @property ConsentCategory $category
 * @property string $name
 * @property string $purpose
 */
final class CookieDeclaration extends Model
{
    protected $fillable = ['category', 'name', 'provider', 'purpose', 'duration', 'sort_order'];

    /**
     * Il registro raggruppato per finalità, nell'ordine in cui va letto.
     *
     * **Le finalità ci sono tutte, anche quelle vuote.** Una categoria senza
     * cookie dichiarati non è un'informazione mancante: è l'informazione che
     * in quella finalità non si deposita niente, ed è più utile della sua
     * assenza. Chi legge una policy vuole sapere anche cosa il sito *non* fa.
     *
     * @return array<string, Collection<int, self>>
     */
    public static function perCategoria(): array
    {
        $righe = self::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (self $riga): string => $riga->category->value);

        $risultato = [];

        foreach (ConsentCategory::cases() as $categoria) {
            /* `new Collection` e non `collect()`: la seconda restituisce una
               collezione senza tipo, e l'analisi statica non puo' piu' dire
               cosa contiene questo elenco — che e' proprio la cosa che serve
               sapere a chi lo disegna. */
            $risultato[$categoria->value] = $righe->get($categoria->value, new Collection);
        }

        return $risultato;
    }

    /** @param  Builder<self>  $query */
    public function scopeOfCategory(Builder $query, ConsentCategory $category): void
    {
        $query->where('category', $category);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['category' => ConsentCategory::class];
    }
}
