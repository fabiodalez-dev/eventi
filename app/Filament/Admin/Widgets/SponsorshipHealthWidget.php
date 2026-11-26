<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\DeskLinks;
use App\Models\City;
use App\Services\Editorial\DeskSummary;
use App\Support\Sponsorship\DashboardCity;
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
     * Sette giorni e non trenta: e' la finestra su cui si giudica «come sta
     * andando adesso». Il rapporto e' «—», non zero, quando non c'e' stata
     * nessuna visualizzazione. I numeri li calcola `DeskSummary`, lo stesso
     * che risponde all'app.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return DeskLinks::stats(app(DeskSummary::class)->sponsorships(DashboardCity::resolve()));
    }
}
