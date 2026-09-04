<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un indirizzo che non esiste più e dove va a finire.
 *
 * @property int $id
 * @property int|null $city_id
 * @property string $from_path
 * @property string $to_path
 * @property bool $is_wildcard
 * @property int $status
 * @property int $hits
 */
final class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'from_path',
        'to_path',
        'is_wildcard',
        'status',
    ];

    /**
     * La riga che risponde a un percorso, o `null`.
     *
     * **Prima l'uguale, poi il jolly.** Una riga scritta per un indirizzo
     * preciso è sempre più giusta di un ramo intero: se un evento è stato
     * rinominato dentro una città che a sua volta è stata rinominata, chi
     * arriva deve finire sull'evento, non sulla sua vecchia lista.
     *
     * **E prima la città, poi il globale.** `city_id` nullo vuol dire «vale
     * per tutte»: è il caso dei locali, dei tag e delle categorie. Una riga di
     * quella città vince su una che vale ovunque, per la stessa ragione.
     */
    public static function perPercorso(?int $cityId, string $percorso): ?self
    {
        $riga = self::query()
            ->where('is_wildcard', false)
            ->where('from_path', $percorso)
            ->perCitta($cityId)
            ->first();

        if ($riga !== null) {
            return $riga;
        }

        /*
         * Le righe jolly si confrontano una per una perché il confronto è
         * `Str::is`, non un uguale che il database sappia fare. Sono poche per
         * costruzione — una per città rinominata — ma se un giorno non lo
         * fossero più il posto da cambiare è questo, non chi chiama.
         */
        return self::query()
            ->where('is_wildcard', true)
            ->perCitta($cityId)
            ->get()
            ->first(fn (self $riga): bool => Str::is($riga->from_path, $percorso));
    }

    /**
     * Le righe che valgono per una città: le sue e quelle che valgono ovunque,
     * con le sue per prime.
     *
     * @param  Builder<self>  $query
     */
    public function scopePerCitta(Builder $query, ?int $cityId): void
    {
        $query
            ->where(function (Builder $query) use ($cityId): void {
                $query->whereNull('city_id');

                if ($cityId !== null) {
                    $query->orWhere('city_id', $cityId);
                }
            })
            ->orderByRaw('city_id is null');
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_wildcard' => 'boolean',
            'status' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }
}
