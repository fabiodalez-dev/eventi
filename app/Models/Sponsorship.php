<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\SponsorshipPhase;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SponsorshipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;

/**
 * Una campagna sponsorizzata: un evento, una collocazione, una finestra.
 *
 * **Una campagna è visibile solo se lo dicono TRE cose insieme**: lo stato è
 * `Active`, la finestra è aperta adesso, e l'evento sotto è ancora pubblicato.
 * Il terzo è quello che si dimentica — un evento annullato o ritirato dalla
 * redazione non deve continuare a comparire in cima alla lista solo perché
 * qualcuno l'aveva pagato, e nessun processo notturno può essere l'unica cosa
 * che lo impedisce.
 *
 * @property-read Event|null $event
 */
class Sponsorship extends Model
{
    /** @use HasFactory<SponsorshipFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'event_id',
        'created_by',
        'placement',
        'status',
        'starts_at',
        'ends_at',
        'priority',
        'weight',
        'impressions_cap',
        'clicks_cap',
        'advertiser_name',
        'advertiser_email',
        'advertiser_url',
        'amount_cents',
        'currency',
        'invoice_reference',
        'notes',
    ];

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Le campagne che possono comparire adesso.
     *
     * I tre requisiti stanno in un punto solo perché ogni pagina che mostra
     * una sponsorizzazione deve applicarli tutti e tre: sparpagliarli
     * significa che prima o poi una pagina ne applica due.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisible(Builder $query, ?CarbonImmutable $now = null): void
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');

        $query->where('status', SponsorshipStatus::Active)
            ->where('starts_at', '<=', $adesso)
            ->where('ends_at', '>=', $adesso)
            /* Il terzo requisito: l'evento sotto deve reggere ancora. Una
               campagna pagata non tiene in vetrina un evento annullato. */
            ->whereHas('event', function (Builder $event): void {
                $event->where('status', EventStatus::Published)
                    ->whereNull('deleted_at');
            })
            /*
             * E i tetti di consegna, se dichiarati.
             *
             * **Il confronto sta qui e non in PHP** perche' una campagna
             * esaurita non deve nemmeno essere caricata: filtrarla dopo
             * significherebbe portarsi dietro le sue relazioni per poi
             * scartarla, e nel caso peggiore — tutte esaurite tranne l'ultima
             * — leggerle tutte per usarne una.
             *
             * `whereColumn` e non un valore: il tetto e' una colonna, e
             * confrontarne due nel database evita di leggere il contatore per
             * poi rileggerlo alla richiesta successiva.
             */
            ->where(function (Builder $tetti): void {
                $tetti->whereNull('impressions_cap')
                    ->orWhereColumn('impressions', '<', 'impressions_cap');
            })
            ->where(function (Builder $tetti): void {
                $tetti->whereNull('clicks_cap')
                    ->orWhereColumn('clicks', '<', 'clicks_cap');
            });
    }

    /**
     * Se la campagna sia in corso in questo istante, a prescindere dall'evento
     * sotto — serve al pannello, che deve poter dire «attiva ma l'evento non
     * è più pubblicato» invece di mostrarla semplicemente come spenta.
     */
    public function isRunning(?CarbonImmutable $now = null): bool
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');

        /* Le due date sono obbligatorie a schema: non c'e' niente da
           controllare prima di confrontarle. */
        return $this->status === SponsorshipStatus::Active
            && $this->starts_at->lessThanOrEqualTo($adesso)
            && $this->ends_at->greaterThanOrEqualTo($adesso);
    }

    /**
     * A che punto è la campagna adesso: bozza, programmata, in corso, finita,
     * sospesa.
     *
     * **Si ricava, non si conserva.** Ed è per questo che dice sempre il vero:
     * non c'è nessun processo notturno che debba girare perché resti
     * aggiornata — vedi il commento in testa a `SponsorshipStatus`, che è la
     * ragione per cui `Completed` non è uno stato.
     *
     * Serve nell'elenco del pannello: prima c'era solo lo stato, e una
     * campagna finita da tre settimane si leggeva «attiva». Vero alla lettera,
     * e inutile a chi sta cercando di capire cosa stia girando adesso.
     */
    public function phase(?CarbonImmutable $now = null): SponsorshipPhase
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');

        return match (true) {
            $this->status === SponsorshipStatus::Draft => SponsorshipPhase::Draft,
            $this->status === SponsorshipStatus::Paused => SponsorshipPhase::Paused,
            $this->starts_at->greaterThan($adesso) => SponsorshipPhase::Scheduled,
            $this->ends_at->lessThan($adesso) => SponsorshipPhase::Ended,
            default => SponsorshipPhase::Running,
        };
    }

    /**
     * Le misure giorno per giorno.
     *
     * @return HasMany<SponsorshipDailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(SponsorshipDailyStat::class);
    }

    /**
     * Quante volte è stata mostrata per ogni volta che è stata aperta.
     *
     * Restituisce `null` e non zero quando non è mai stata mostrata: un
     * rapporto su zero visualizzazioni non è «zero per cento», è una domanda
     * senza risposta, e scriverlo come 0% invita a concludere che la campagna
     * vada male quando invece non è ancora partita.
     */
    public function clickRate(): ?float
    {
        /*
         * `(int)` e non un confronto con `0`: subito dopo una `create()` che
         * non li nomina, i due contatori non sono in memoria — il valore
         * predefinito lo mette il database — e valgono `null`. Un confronto
         * stretto con zero li lascia passare, e la divisione seguente esplode.
         * Un contatore che non c'e' vale zero.
         */
        $viste = (int) $this->impressions;

        if ($viste === 0) {
            return null;
        }

        return (int) $this->clicks / $viste;
    }

    public function formattedAmount(): ?string
    {
        if ($this->amount_cents === null) {
            return null;
        }

        return (string) Number::currency(
            $this->amount_cents / 100,
            in: $this->currency ?: 'EUR',
            locale: app()->getLocale(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement' => SponsorshipPlacement::class,
            'status' => SponsorshipStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'priority' => 'integer',
            'weight' => 'integer',
            'impressions_cap' => 'integer',
            'clicks_cap' => 'integer',
            'amount_cents' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
        ];
    }
}
