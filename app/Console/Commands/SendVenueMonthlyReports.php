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
use Illuminate\Support\Facades\DB;
use Throwable;

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
        $already = 0;

        Venue::query()->where('status', VenueStatus::Approved)->with('city')->orderBy('id')
            ->chunkById(100, function ($venues) use ($reports, $month, &$sent, &$skipped, &$already): void {
                foreach ($venues as $venue) {
                    $report = $reports->forMonth($venue, $month);

                    if ($report['empty']) {
                        $skipped++;

                        continue;
                    }

                    foreach ($venue->owners as $owner) {
                        if (! $owner->canReceiveNotifications()
                            || ! NotificationType::VenueMonthlyReport->isEnabledFor($owner)) {
                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $this->line(sprintf('%s → %s', $venue->name, $owner->email));
                            $sent++;

                            continue;
                        }

                        /* La presa in carico prima dell'invio, e atomica: la
                           riga la scrive uno solo, anche se due esecuzioni
                           partono insieme. Chi non riesce a scriverla ha già
                           mandato, o lo sta facendo un altro. */
                        if ($this->claim($venue, $owner, $month) === false) {
                            $already++;

                            continue;
                        }

                        $this->line(sprintf('%s → %s', $venue->name, $owner->email));

                        try {
                            $owner->notify(new VenueMonthlyReportNotification($venue, $report['label'], $report['totals'], $report['previous']));
                        } catch (Throwable $errore) {
                            /* La presa in carico si restituisce: un invio non
                               riuscito deve poter essere ritentato, altrimenti
                               il registro proteggerebbe dai doppioni
                               trasformando ogni guasto in un rapporto perso. */
                            $this->release($venue, $owner, $month);

                            throw $errore;
                        }

                        $sent++;
                    }
                }
            });

        $this->info(sprintf('Rapporti inviati: %d. Locali senza dati nel mese: %d. Gia inviati in precedenza: %d.', $sent, $skipped, $already));

        return self::SUCCESS;
    }

    /** Prende in carico l'invio, o dice che qualcuno l'ha gia preso. */
    private function claim(Venue $venue, User $owner, CarbonImmutable $month): bool
    {
        return DB::table('venue_monthly_reports')->insertOrIgnore([[
            'venue_id' => $venue->getKey(),
            'user_id' => $owner->getKey(),
            'month' => $month->format('Y-m-01'),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]) > 0;
    }

    private function release(Venue $venue, User $owner, CarbonImmutable $month): void
    {
        DB::table('venue_monthly_reports')
            ->where('venue_id', $venue->getKey())->where('user_id', $owner->getKey())
            ->where('month', $month->format('Y-m-01'))->delete();
    }
}
