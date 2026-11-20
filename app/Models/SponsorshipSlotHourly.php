<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il traffico di uno spazio in un'ora: occasioni viste, occasioni vendute,
 * clic.
 *
 * Una riga per città, collocazione, canale, giorno e ora. **Giorno e ora sono
 * nel fuso della città**, come in `sponsorship_daily_stats`: è l'ora che conta
 * per la previsione («il sabato sera»), non quella del server.
 *
 * Solo contatori, nessun dato sulla persona. Chi scrive usa un `upsert` con
 * incremento sulla chiave unica, mai lettura e poi scrittura.
 *
 * @property int $city_id
 * @property string $placement
 * @property string $channel
 * @property CarbonImmutable $day
 * @property int $hour
 * @property int $views
 * @property int $sponsored_views
 * @property int $clicks
 */
class SponsorshipSlotHourly extends Model
{
    protected $table = 'sponsorship_slot_hourly';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'placement',
        'channel',
        'day',
        'hour',
        'views',
        'sponsored_views',
        'clicks',
    ];

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'hour' => 'integer',
            'views' => 'integer',
            'sponsored_views' => 'integer',
            'clicks' => 'integer',
        ];
    }
}
