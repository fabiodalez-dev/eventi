<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Il KPI del piano, finalmente misurato: **aprendo il sito si trova qualcosa
 * da fare?**
 *
 * §1 lo dichiara come la domanda a cui il prodotto risponde, e §2.2 lo chiama
 * «zero pagina vuota»: una piattaforma di eventi che mostra «non ci sono
 * eventi» e' morta. Il piano lo scrive due volte e nessuno lo misurava:
 * il pannello contava eventi incompleti, duplicati e locandine mancanti —
 * cioe' se un evento e' scritto male — mai se **giovedi' sera la citta' e'
 * vuota**.
 *
 * Il rovescio esatto delle priorita' dichiarate: la velocita' di una pagina
 * aveva un controllo bloccante nell'integrazione continua, il fatto che quella
 * pagina avesse qualcosa da mostrare non aveva nemmeno un numero.
 *
 * **La misura e' la copertura, non il totale.** Duecento eventi tutti nello
 * stesso weekend sono un totale ottimo e un calendario pessimo: chi apre il
 * sito di martedi' non trova niente. Si contano quindi i giorni dei prossimi
 * quattordici che hanno almeno tre date — la soglia del piano — perche' e' la
 * domanda che si fa chi visita: «stasera cosa c'e'?», non «quanti eventi avete
 * in archivio».
 */
final class CalendarCoverageCheck extends Check
{
    /** Quante date deve avere un giorno per contare come coperto (§2.2). */
    private int $minimoPerGiorno = 3;

    /** Su quanti giorni si guarda avanti. */
    private int $giorni = 14;

    /** Sotto questa frazione di giorni coperti si avvisa. */
    private float $sogliaAvviso = 0.7;

    /** E sotto questa il calendario e' vuoto abbastanza da essere un guasto. */
    private float $sogliaErrore = 0.4;

    public function run(): Result
    {
        $oggi = CarbonImmutable::now()->startOfDay();
        $fine = $oggi->addDays($this->giorni - 1)->endOfDay();

        /*
         * Si raggruppa per `business_date` e non per `starts_at`: e' la data a
         * cui un evento «appartiene» secondo §8, e su un concerto che comincia
         * all'una di notte le due differiscono di un giorno. Contare per
         * `starts_at` direbbe che mercoledi' c'e' qualcosa quando per chi
         * legge quella serata e' martedi'.
         */
        $perGiorno = DB::table('event_occurrences')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->whereNull('events.deleted_at')
            ->where('events.status', EventStatus::Published->value)
            /* `status = scheduled` e non «non annullata»: e' il filtro che usa
               `EventOccurrenceQuery`, e due modi diversi di dire la stessa cosa
               sono due modi di dare due numeri diversi. */
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled->value)
            ->whereBetween('event_occurrences.business_date', [$oggi->toDateString(), $fine->toDateString()])
            ->groupBy('event_occurrences.business_date')
            ->selectRaw('event_occurrences.business_date as giorno, COUNT(*) as quante')
            ->pluck('quante', 'giorno');

        $coperti = $perGiorno->filter(fn (int $quante): bool => $quante >= $this->minimoPerGiorno)->count();
        $frazione = $this->giorni > 0 ? $coperti / $this->giorni : 0.0;

        /*
         * Le occorrenze future in tutto, con gli stessi filtri di sopra.
         *
         * Non passa da `EventOccurrenceQuery` perche' quello lavora **per
         * citta'** (§8) e questo controllo guarda l'installazione intera:
         * chiedergli un totale globale significherebbe interrogarlo una volta
         * per citta' e sommare. Il target di §1 — «≥ 100 eventi futuri» — e'
         * dichiarato sul prodotto, non sulla singola citta'.
         */
        $futuri = DB::table('event_occurrences')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->whereNull('events.deleted_at')
            ->where('events.status', EventStatus::Published->value)
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled->value)
            ->where('event_occurrences.business_date', '>=', $oggi->toDateString())
            ->count();

        $risultato = Result::make()
            ->shortSummary($coperti.'/'.$this->giorni.' giorni')
            ->meta([
                'giorni_coperti' => $coperti,
                'giorni_guardati' => $this->giorni,
                'minimo_per_giorno' => $this->minimoPerGiorno,
                'occorrenze_future' => $futuri,
            ]);

        $messaggio = sprintf(
            '%d dei prossimi %d giorni hanno almeno %d date (%d occorrenze future in tutto).',
            $coperti,
            $this->giorni,
            $this->minimoPerGiorno,
            $futuri,
        );

        if ($frazione < $this->sogliaErrore) {
            return $risultato->failed($messaggio.' Il calendario e troppo vuoto: chi apre il sito in molti giorni non trova niente.');
        }

        if ($frazione < $this->sogliaAvviso) {
            return $risultato->warning($messaggio.' Ci sono buchi: vale la pena guardare quali giorni e chiamare qualche locale.');
        }

        return $risultato->ok($messaggio);
    }
}
