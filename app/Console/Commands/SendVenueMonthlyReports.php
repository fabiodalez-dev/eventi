<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueMonthlyReport as VenueMonthlyReportNotification;
use App\Services\Analytics\VenueMonthlyReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Il rapporto del mese appena chiuso a chi gestisce un locale.
 *
 * **Solo ai referenti**, non ai collaboratori: sono gli stessi che possono
 * cambiare i dati del locale, ed è la stessa separazione che vale nel
 * pannello. Un collaboratore che riceve numeri su cui non può intervenire
 * riceve rumore.
 *
 * **Niente rapporto quando non c'è niente da rapportare.** Un locale al primo
 * mese, o rimasto fermo, riceverebbe una pagina di zeri: non è un'informazione,
 * è un modo per far cestinare anche i rapporti successivi.
 *
 * **E niente rapporto a chi lo ha spento**: l'interruttore esiste e viene
 * rispettato qui, prima dell'invio, non dopo.
 */
class SendVenueMonthlyReports extends Command
{
    protected $signature = 'venues:monthly-report
        {--month= : Mese da riportare (YYYY-MM), altrimenti quello appena chiuso}
        {--dry-run : Elenca i destinatari senza spedire}';

    protected $description = 'Manda ai referenti dei locali il rapporto del mese appena chiuso';

    public function handle(VenueMonthlyReport $reports): int
    {
        $month = $this->option('month') === null
            ? CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m-d', $this->option('month').'-01')->startOfMonth();

        $sent = 0;
        $skipped = 0;

        Venue::query()->where('status', VenueStatus::Approved)->with('city')->orderBy('id')
            ->chunkById(100, function ($venues) use ($reports, $month, &$sent, &$skipped): void {
                foreach ($venues as $venue) {
                    $report = $reports->forMonth($venue, $month);

                    if ($report['empty']) {
                        $skipped++;

                        continue;
                    }

                    foreach ($venue->owners as $owner) {
                        if (! $owner instanceof User || ! $owner->canReceiveNotifications()
                            || ! NotificationType::VenueMonthlyReport->isEnabledFor($owner)) {
                            continue;
                        }

                        $this->line(sprintf('%s → %s', $venue->name, $owner->email));

                        if (! $this->option('dry-run')) {
                            $owner->notify(new VenueMonthlyReportNotification($venue, $report['label'], $report['totals'], $report['previous']));
                        }

                        $sent++;
                    }
                }
            });

        $this->info(sprintf('Rapporti inviati: %d. Locali senza dati nel mese: %d.', $sent, $skipped));

        return self::SUCCESS;
    }
}
