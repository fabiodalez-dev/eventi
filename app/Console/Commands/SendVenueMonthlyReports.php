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
use Illuminate\Database\Query\Builder;
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
        $failed = 0;

        Venue::query()->where('status', VenueStatus::Approved)->with('city')->orderBy('id')
            ->chunkById(100, function ($venues) use ($reports, $month, &$sent, &$skipped, &$already, &$failed): void {
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
                            /* Niente e' partito: la presa in carico si
                               restituisce, altrimenti il registro proteggerebbe
                               dai doppioni trasformando ogni guasto in un
                               rapporto perso.

                               E il ciclo continua. Un indirizzo che rimbalza
                               fermava tutti i rapporti dopo di lui, e il comando
                               gira una volta al mese: quei locali avrebbero
                               aspettato trenta giorni per colpa di un altro. */
                            $this->release($venue, $owner, $month);
                            $failed++;
                            $this->warn(sprintf('  non spedito a %s: %s', $owner->email, $errore->getMessage()));
                            report($errore);

                            continue;
                        }

                        /* Da qui in poi l'email e' partita davvero, e un guasto
                           nella registrazione e' un'altra cosa da un guasto
                           nella consegna: restituire la presa in carico qui
                           manderebbe il rapporto una seconda volta. La riga
                           resta presa, e chi sorveglia il comando legge che va
                           riconciliata a mano. */
                        if (! $this->markSent($venue, $owner, $month)) {
                            $failed++;
                            $this->warn(sprintf('  consegnato a %s ma non registrato: la riga del mese va chiusa a mano', $owner->email));

                            continue;
                        }

                        $sent++;
                    }
                }
            });

        $this->info(sprintf(
            'Rapporti inviati: %d. Locali senza dati nel mese: %d. Gia inviati in precedenza: %d. Non riusciti: %d.',
            $sent, $skipped, $already, $failed,
        ));

        // Chi pianifica deve accorgersi dei rapporti non partiti: un comando
        // che dice sempre «fatto» non e' un comando che si puo' sorvegliare.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Prende in carico l'invio, o dice che qualcuno l'ha gia preso.
     *
     * Due modi di riuscire, ed entrambi atomici. La riga nuova la scrive uno
     * solo, per il vincolo unico. Quella rimasta a meta' — il processo morto
     * fra la presa in carico e l'invio — la riprende chi arriva per primo:
     * decide il numero di righe toccate dall'aggiornamento, non una lettura
     * seguita da una scrittura. Un'ora e' abbondante per un invio sincrono;
     * oltre, quella riga appartiene a un processo che non esiste piu'.
     *
     * Il recupero sta qui e non in un passaggio a parte proprio perche' cosi'
     * non tocca niente quando non si spedisce: in prova generale `claim()` non
     * viene nemmeno chiamata, e il registro resta come l'ha lasciato l'ultimo
     * invio vero.
     */
    private function claim(Venue $venue, User $owner, CarbonImmutable $month): bool
    {
        $nuova = DB::table('venue_monthly_reports')->insertOrIgnore([[
            'venue_id' => $venue->getKey(),
            'user_id' => $owner->getKey(),
            'month' => $month->format('Y-m-01'),
            'claimed_at' => now(),
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]]) > 0;

        if ($nuova) {
            return true;
        }

        $ripresa = $this->rowFor($venue, $owner, $month)->whereNull('sent_at')
            ->where('claimed_at', '<', now()->subHour())
            ->update(['claimed_at' => now(), 'updated_at' => now()]) > 0;

        if ($ripresa) {
            $this->warn(sprintf('  presa in carico rimasta a meta e ripresa: %s', $owner->email));
        }

        return $ripresa;
    }

    /**
     * L'invio avvenuto: da qui in poi quella riga non si tocca piu'.
     *
     * Dice se ci e' riuscita, perche' chi chiama ha gia' spedito e deve
     * distinguere «non consegnato» da «consegnato e non registrato».
     */
    private function markSent(Venue $venue, User $owner, CarbonImmutable $month): bool
    {
        try {
            return $this->rowFor($venue, $owner, $month)->update(['sent_at' => now(), 'updated_at' => now()]) > 0;
        } catch (Throwable $errore) {
            report($errore);

            return false;
        }
    }

    private function release(Venue $venue, User $owner, CarbonImmutable $month): void
    {
        $this->rowFor($venue, $owner, $month)->delete();
    }

    private function rowFor(Venue $venue, User $owner, CarbonImmutable $month): Builder
    {
        return DB::table('venue_monthly_reports')
            ->where('venue_id', $venue->getKey())->where('user_id', $owner->getKey())
            ->where('month', $month->format('Y-m-01'));
    }
}
