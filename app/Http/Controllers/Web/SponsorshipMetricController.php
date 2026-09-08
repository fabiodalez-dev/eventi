<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le misure di una campagna: quante volte è stata vista, quante aperta.
 *
 * **Perché si contano dal browser e non dal server.** Le pagine pubbliche
 * stanno in cache per un minuto (`page_cache.ttl_minutes`): il server disegna
 * la card una volta e poi serve la stessa pagina a tutti fino alla scadenza.
 * Un contatore incrementato mentre si disegna conterebbe una visualizzazione
 * al minuto invece che una per visitatore — un numero che sembra una misura e
 * non lo è.
 *
 * **Il prezzo di questa scelta va detto a chi compra.** Contando dal browser,
 * chi blocca gli script non viene contato: le cifre sono una stima al ribasso,
 * non un registro contabile. È il limite di qualunque misura pubblicitaria
 * fatta così, e il posto giusto per dichiararlo è il contratto — qui si può
 * solo evitare di far finta di niente.
 *
 * Il visitatore, in ogni caso, non dipende da queste chiamate: il collegamento
 * alla scheda è un `href` normale, e senza JavaScript funziona lo stesso.
 * Sono le misure a perdersi, mai la navigazione.
 */
final class SponsorshipMetricController extends Controller
{
    /**
     * Le due misure, distinte dal parametro perché sono la stessa operazione
     * su due colonne: due metodi identici sarebbero due posti dove correggere
     * lo stesso errore.
     */
    public function __invoke(Request $request, Sponsorship $sponsorship, string $metric): JsonResponse
    {
        abort_unless(in_array($metric, ['impressions', 'clicks'], strict: true), 404);

        /*
         * Un tetto per chi chiama, non per campagna: senza, una sola persona
         * con un ciclo `for` gonfia le cifre di una campagna a piacere, e
         * quelle cifre finiscono in fattura. Trenta all'ora per indirizzo sono
         * larghi per una persona che naviga e stretti per uno script.
         */
        $chiave = 'sponsorship-metric:'.$request->ip().':'.$sponsorship->getKey().':'.$metric;

        if (RateLimiter::tooManyAttempts($chiave, maxAttempts: 30)) {
            /* 204 e non 429: al browser non interessa, e un errore in console
               su una misura pubblicitaria è rumore per chi apre gli strumenti
               di sviluppo per tutt'altro. */
            return response()->json(status: 204);
        }

        RateLimiter::hit($chiave, decaySeconds: 3600);

        // Total, daily aggregate and click ledger are one atomic operation.
        app(RecordSponsorshipMetric::class)->record($sponsorship, $metric, $request, 'web');

        return response()->json(status: 204);
    }
}
