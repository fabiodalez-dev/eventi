<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use App\Support\IpBucket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le misure di una campagna: quante volte è stata vista, quante aperta.
 *
 * **Perché si contano dal browser e non dal server.** Le pagine pubbliche
 * stanno in cache (`page_cache.ttl_minutes`): il server disegna
 * la card una volta e poi serve la stessa pagina a tutti fino alla scadenza.
 * Un contatore incrementato mentre si disegna conterebbe una visualizzazione
 * una volta invece che una per visitatore — un numero che sembra una misura e
 * non lo è.
 *
 * **Ogni misura porta il suo gettone.** La card arriva con un gettone usa e
 * getta (`data-sponsorship-token`, firmato da `ImpressionToken`), e il
 * browser lo rimanda qui nell'intestazione `X-Impression-Token`. Senza
 * gettone, o con un gettone falso, scaduto o di un'altra campagna, la
 * risposta è la stessa di sempre e non si conta niente: prima bastava un
 * ciclo `for` su questo indirizzo per scrivere in fattura.
 *
 * **La risposta è sempre 204**, anche quando non conta: al browser non serve
 * saperlo, e un segnale diverso direbbe a chi gonfia quale controllo ha
 * fermato la richiesta. Solo una misura che non esiste (né visualizzazione né
 * clic) è un indirizzo che non esiste, e la rotta risponde 404.
 *
 * **Il prezzo di questa scelta va detto a chi compra.** Contando dal browser,
 * chi blocca gli script non viene contato: le cifre sono una stima al ribasso,
 * non un registro contabile.
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
    public function __invoke(Request $request, int $sponsorship, string $metric, ImpressionToken $tokens, RecordSponsorshipMetric $recorder): JsonResponse
    {
        abort_unless(in_array($metric, ['impressions', 'clicks'], strict: true), 404);

        $now = CarbonImmutable::now('UTC');
        $token = $request->header('X-Impression-Token') ?? $request->input('impression_token');
        $claims = is_string($token) ? $tokens->verify($token, $now) : null;

        if ($claims === null || ! $claims->isFor($sponsorship)) {
            return response()->json(status: 204);
        }

        /*
         * La campagna deve poter comparire (o averlo potuto fino a una casella
         * fa, per l'apertura in cache): una misura su una campagna in pausa,
         * nel cestino o arrivata all'obiettivo non la paga nessuno.
         */
        $campagna = $recorder->countableCampaign($sponsorship, now: $now, metric: $metric, issuedAt: $claims->issuedAt);

        if ($campagna === null) {
            return response()->json(status: 204);
        }

        /*
         * Un tetto per chi chiama, non per campagna: ogni pagina caricata porta
         * un gettone nuovo, quindi chi ricarica la home in un ciclo ne ha
         * quanti ne vuole. Trenta all'ora per indirizzo sono larghi per una
         * persona che naviga e stretti per uno script. Con IPv6 l'indirizzo è
         * il /64: dentro il prefisso lo si cambia a costo zero.
         */
        $chiave = 'sponsorship-metric:'.IpBucket::key($request->ip()).':'.$campagna->getKey().':'.$metric;

        if (RateLimiter::tooManyAttempts($chiave, maxAttempts: 30)) {
            return response()->json(status: 204);
        }

        RateLimiter::hit($chiave, decaySeconds: 3600);

        if ($recorder->shouldCount($request, $campagna, $request->user())) {
            $recorder->recordWithToken($campagna, $claims, $metric, $request, 'web', $now);
        }

        return response()->json(status: 204);
    }
}
