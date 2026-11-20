<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Toglie i dati di servizio delle misure quando non servono più.
 *
 * - **I numeri casuali dei gettoni** dopo 6 ore (`sponsorship.retention.nonce_hours`):
 *   un gettone vale 6 ore, poi la deduplica non ha più niente da fermare. A
 *   regime ne resta qualche centinaio, non uno storico di chi ha guardato.
 * - **Le esposizioni per utente** dopo 8 giorni (`exposure_days`), quando la
 *   tabella c'è (arriva con il tetto di frequenza, secondo rilascio).
 * - **L'aggregato orario degli spazi** dopo 70 giorni (`hourly_days`): la
 *   previsione usa le ultime quattro settimane, il resto è margine.
 *
 * Si cancella a blocchi: un `DELETE` solo su molte righe terrebbe il lucchetto
 * della tabella mentre le misure provano a scriverci, sull'hosting condiviso.
 * Gira ogni ora; `--dry-run` dice quante righe toglierebbe senza toccarle.
 */
final class SponsorshipsPrune extends Command
{
    protected $signature = 'sponsorships:prune {--dry-run : Conta le righe da togliere senza toglierle}';

    protected $description = 'Toglie gettoni contati, esposizioni e aggregati orari delle sponsorizzazioni oltre la loro durata';

    /** Righe tolte per istruzione. */
    private const CHUNK = 5000;

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $dryRun = (bool) $this->option('dry-run');

        /* Mai prima che il gettone scada: un numero tolto mentre il suo
           gettone vale ancora lascerebbe contare due volte la stessa
           visualizzazione. Cinque minuti di margine coprono il minuto di
           emissione e gli orologi appena diversi. */
        $nonceMinutes = max(
            config()->integer('sponsorship.retention.nonce_hours', 6),
            config()->integer('sponsorship.token_ttl_hours', 6),
            1,
        ) * 60 + 5;
        /* Mai meno delle quattro settimane che la previsione legge: un valore
           sbagliato in configurazione non deve svuotare la previsione. */
        $hourlyDays = max(29, config()->integer('sponsorship.retention.hourly_days', 70));
        $exposureDays = max(1, config()->integer('sponsorship.retention.exposure_days', 8));

        $tables = [
            'sponsorship_impression_nonces' => fn (): Builder => DB::table('sponsorship_impression_nonces')
                ->where('seen_at', '<', $now->subMinutes($nonceMinutes)->toDateTimeString()),
            /* Giorno e ora sono nel fuso della città: un giorno di margine
               copre qualunque fuso senza leggere le città. */
            'sponsorship_slot_hourly' => fn (): Builder => DB::table('sponsorship_slot_hourly')
                ->where('day', '<', $now->subDays($hourlyDays + 1)->toDateString()),
            'sponsorship_exposures' => fn (): Builder => DB::table('sponsorship_exposures')
                ->where('day', '<', $now->subDays($exposureDays + 1)->toDateString()),
        ];

        foreach ($tables as $table => $query) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $removed = $dryRun ? $query()->count() : $this->deleteInChunks($query);

            $this->line(($dryRun ? 'Da togliere' : 'Tolte').' in '.$table.': '.$removed);
        }

        return self::SUCCESS;
    }

    /** @param  callable(): Builder  $query */
    private function deleteInChunks(callable $query): int
    {
        $total = 0;

        do {
            $removed = $query()->limit(self::CHUNK)->delete();
            $total += $removed;
        } while ($removed === self::CHUNK);

        return $total;
    }
}
