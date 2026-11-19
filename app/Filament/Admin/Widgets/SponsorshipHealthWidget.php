<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Support\Sponsorship\DashboardCity;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Le quattro cose da sapere sulle campagne senza aprire l'elenco.
 *
 * **Perche' «in scadenza» e' una voce a se'.** Una campagna che finisce fra
 * cinque giorni e' l'unica occasione di richiamare chi paga prima che scada,
 * e se la si scopre il giorno dopo la scadenza si e' persa. E' l'informazione
 * che vale di piu' di tutte le altre messe insieme, ed e' anche quella che
 * nessuno va a cercare da solo.
 *
 * **E perche' il rapporto di apertura sta qui.** Una campagna vista molto e
 * mai aperta occupa il posto migliore del sito senza portare niente: e' un
 * problema per chi paga — che non rinnovera' — e per chi legge, che si vede
 * proporre qualcosa che non gli interessa. Nessuno dei due si lamenta finche'
 * non e' tardi.
 *
 * **Solo quello che compare davvero, nella citta' della dashboard.** «In
 * corso» contava ogni campagna attiva nella finestra, anche su eventi gia'
 * passati o annullati, e di tutte le citta': ora usa `Sponsorship::visible()`,
 * la stessa regola delle pagine pubbliche, e i giorni della settimana sono
 * quelli della citta'.
 */
class SponsorshipHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 7;

    /* Niente aggiornamento automatico ogni cinque secondi: ogni giro era una
       richiesta completa con tutte le sue query, e i numeri cambiano a ore. */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return DashboardCity::resolve() instanceof City;
    }

    protected function getHeading(): ?string
    {
        $city = DashboardCity::resolve();

        return $city === null ? __('sponsorships.admin.title') : __('sponsorships.widgets.heading_city', ['city' => $city->name]);
    }

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $adesso = CarbonImmutable::now('UTC');
        $city = DashboardCity::resolve();
        $cityId = $city?->getKey() ?? 0;
        $visibili = fn () => Sponsorship::query()->visible($adesso)->where('sponsorships.city_id', $cityId);

        $inCorso = $visibili()->count();

        $inScadenza = $visibili()
            ->where('sponsorships.ends_at', '<=', $adesso->addDays(7))
            ->count();

        /* Sette giorni e non trenta: e' la finestra su cui si giudica «come sta
           andando adesso», e un totale da inizio campagna la annacquerebbe con
           settimane vecchie. I giorni sono quelli della citta', come li
           scrive chi conta. */
        $oggi = CarbonImmutable::now(DashboardCity::timezone($city));
        $settimana = SponsorshipDailyStat::query()
            ->whereIn('sponsorship_id', Sponsorship::withTrashed()->where('city_id', $cityId)->select('id'))
            ->where('day', '>=', $oggi->subDays(6)->toDateString())
            ->selectRaw('COALESCE(SUM(impressions), 0) as viste, COALESCE(SUM(clicks), 0) as aperture')
            ->first();

        $viste = (int) ($settimana?->getAttribute('viste') ?? 0);
        $aperture = (int) ($settimana?->getAttribute('aperture') ?? 0);

        /*
         * Il rapporto e' `null`, non zero, quando non c'e' stata nessuna
         * visualizzazione: un rapporto su zero non e' «zero per cento», e'
         * una domanda senza risposta — e scrivere 0% farebbe concludere che
         * le campagne vadano male quando semplicemente non sono partite.
         */
        $rapporto = $viste > 0 ? round($aperture / $viste * 100, 1) : null;

        return [
            Stat::make(__('sponsorships.widgets.running'), (string) $inCorso)
                ->description(__('sponsorships.widgets.running_lead'))
                ->color($inCorso > 0 ? 'success' : 'gray'),

            Stat::make(__('sponsorships.widgets.expiring'), (string) $inScadenza)
                ->description(__('sponsorships.widgets.expiring_lead'))
                ->color($inScadenza > 0 ? 'warning' : 'gray'),

            Stat::make(__('sponsorships.widgets.week_impressions'), number_format($viste, 0, ',', '.'))
                ->description(__('sponsorships.widgets.week_lead')),

            Stat::make(
                __('sponsorships.widgets.week_rate'),
                $rapporto === null ? '—' : number_format($rapporto, 1, ',', '.').'%',
            )
                ->description($rapporto === null
                    ? __('sponsorships.widgets.week_rate_none')
                    : __('sponsorships.widgets.week_rate_lead', ['aperture' => $aperture]))
                ->color(match (true) {
                    $rapporto === null => 'gray',
                    $rapporto < 0.5 => 'danger',
                    $rapporto < 1.5 => 'warning',
                    default => 'success',
                }),
        ];
    }
}
