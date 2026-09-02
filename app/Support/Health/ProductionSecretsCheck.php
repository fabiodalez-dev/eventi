<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Support\Turnstile;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Le chiavi che in produzione devono esserci, e che nessuno si accorge se
 * mancano.
 *
 * **Perche' e' un controllo e non una riga nel RUNBOOK.** Ognuna di queste,
 * quando manca, non rompe niente: il sito funziona, i test passano, la
 * pipeline e' verde. Si limita a fare qualcosa di meno — e quel meno lo si
 * scopre per caso, mesi dopo, andando a guardare.
 *
 * E' successo: `TURNSTILE_SITE_KEY` e `TURNSTILE_SECRET_KEY` sono rimaste
 * vuote in produzione per settimane. `Turnstile::rules()` restituisce un array
 * vuoto quando non e' configurato — scelta giusta, perche' un modulo che
 * rifiuta tutti perche' manca una chiave sarebbe peggio — ma il risultato e'
 * che «proponi evento» e «registra il tuo locale» erano aperti a qualunque
 * script, senza che niente lo segnalasse.
 *
 * **Solo in produzione.** In sviluppo e nei test quelle chiavi non servono, e
 * un controllo che protesta sulla macchina di chi sta scrivendo codice viene
 * spento il primo giorno.
 */
final class ProductionSecretsCheck extends Check
{
    public function run(): Result
    {
        if (! app()->environment('production')) {
            return Result::make()
                ->shortSummary('solo in produzione')
                ->ok('Fuori produzione queste chiavi non servono.');
        }

        $mancanti = [];

        /*
         * L'ordine e' quello del danno, non alfabetico: la prima e' l'unica
         * che espone a qualcosa che arriva da fuori invece che a un guasto
         * interno.
         */
        if (! Turnstile::enabled()) {
            $mancanti['TURNSTILE_SITE_KEY / SECRET_KEY'] = 'i moduli pubblici accettano invii da qualunque script';
        }

        if ($this->vuota('sentry.dsn')) {
            $mancanti['SENTRY_LARAVEL_DSN'] = 'gli errori in produzione non arrivano da nessuna parte';
        }

        if ($this->vuota('backup.backup.password')) {
            $mancanti['BACKUP_ARCHIVE_PASSWORD'] = 'gli archivi non sono cifrati';
        }

        if ($this->vuota('seo.organization.legal_name')) {
            $mancanti['SEO_ORGANIZATION'] = 'i dati strutturati escono incompleti per i motori';
        }

        $risultato = Result::make()
            ->shortSummary($mancanti === [] ? 'tutte impostate' : count($mancanti).' mancanti')
            ->meta(['mancanti' => array_keys($mancanti)]);

        if ($mancanti === []) {
            return $risultato->ok('Tutte le chiavi di produzione sono impostate.');
        }

        $righe = [];

        foreach ($mancanti as $chiave => $conseguenza) {
            $righe[] = $chiave.' — '.$conseguenza;
        }

        /*
         * Turnstile mancante e' un errore, il resto un avviso: senza le altre
         * si perde qualcosa, senza quella si e' esposti. Un controllo che
         * tratta le due cose allo stesso modo insegna a rimandarle entrambe.
         */
        return isset($mancanti['TURNSTILE_SITE_KEY / SECRET_KEY'])
            ? $risultato->failed(implode(' · ', $righe))
            : $risultato->warning(implode(' · ', $righe));
    }

    private function vuota(string $chiave): bool
    {
        $valore = config($chiave);

        return ! is_string($valore) || trim($valore) === '';
    }
}
