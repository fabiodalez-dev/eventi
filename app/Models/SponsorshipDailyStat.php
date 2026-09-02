<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Le misure di una campagna in un giorno.
 *
 * Non ha factory ne' si crea a mano: nasce solo da `registra()`, che e' un
 * `upsert`. Due richieste simultanee sullo stesso giorno non producono due
 * righe, e non serve leggere prima per sapere se scrivere o aggiornare.
 *
 * @property int $sponsorship_id
 * @property CarbonImmutable $day
 * @property int $impressions
 * @property int $clicks
 */
class SponsorshipDailyStat extends Model
{
    /** @var list<string> */
    protected $fillable = ['sponsorship_id', 'day', 'impressions', 'clicks'];

    /**
     * @return BelongsTo<Sponsorship, $this>
     */
    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class);
    }

    /**
     * Aggiunge una misura al giorno di oggi.
     *
     * **Un `upsert` e non un `firstOrCreate` seguito da `increment`.** Quello
     * sarebbe due query e una corsa: fra la lettura e la scrittura un'altra
     * richiesta puo' creare la stessa riga, e il vincolo di unicita' farebbe
     * fallire la seconda — perdendo una misura per un dettaglio di ordine.
     * Qui il database fa entrambe le cose in un colpo solo, e chi arriva dopo
     * somma invece di scontrarsi.
     */
    public static function registra(int $sponsorshipId, string $colonna, ?CarbonImmutable $quando = null): void
    {
        $giorno = ($quando ?? CarbonImmutable::now())->toDateString();
        $adesso = now();

        DB::table('sponsorship_daily_stats')->upsert(
            [[
                'sponsorship_id' => $sponsorshipId,
                'day' => $giorno,
                'impressions' => $colonna === 'impressions' ? 1 : 0,
                'clicks' => $colonna === 'clicks' ? 1 : 0,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ]],
            ['sponsorship_id', 'day'],
            [
                $colonna => DB::raw('`sponsorship_daily_stats`.`'.$colonna.'` + 1'),
                'updated_at' => $adesso,
            ],
        );
    }

    /**
     * @param  Builder<SponsorshipDailyStat>  $query
     */
    public function scopeBetween(Builder $query, CarbonImmutable $da, CarbonImmutable $a): void
    {
        $query->whereBetween('day', [$da->toDateString(), $a->toDateString()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'impressions' => 'integer',
            'clicks' => 'integer',
        ];
    }
}
