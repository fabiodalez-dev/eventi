<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use App\Support\IpBucket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le misure delle sponsorizzazioni viste nell'app e nel banner del sito.
 *
 * **Due gettoni, per non lasciare indietro nessuno.** Le app aggiornate e il
 * banner del sito mandano il gettone usa e getta (`impression_token`) in
 * `X-Impression-Token`: lo stesso controllo del sito, con la deduplica sul
 * numero casuale e la risposta sempre 204. Le app già installate mandano
 * ancora il gettone giornaliero in `X-Metric-Token`, e continuano a contare
 * come prima, con la deduplica di 15 minuti e gli errori di prima: l'API
 * cambia solo per aggiunte.
 */
final class SponsorshipMetricController extends Controller
{
    use InteractsWithApi;

    public function __invoke(Request $request, int $sponsorship, string $metric, ImpressionToken $tokens, RecordSponsorshipMetric $recorder): JsonResponse
    {
        if (! in_array($metric, ['impressions', 'clicks'], true)) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        if ($request->hasHeader('X-Impression-Token')) {
            return $this->withImpressionToken($request, $sponsorship, $metric, $tokens, $recorder);
        }

        /* La strada del gettone giornaliero ha una data di chiusura in
           configurazione: dopo, risponde come la strada nuova senza gettone. */
        if (self::legacyClosed()) {
            return response()->json(status: 204);
        }

        $campaign = Sponsorship::query()
            ->visible()
            ->where('city_id', $this->city()->getKey())
            ->whereKey($sponsorship)
            ->first();

        if (! $campaign instanceof Sponsorship || ! $this->validToken($request, $campaign)) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        /*
         * IL TETTO PER CAMPAGNA, contato sull'INDIRIZZO.
         *
         * Prima qui non c'era, e il gemello web ce l'aveva: erano due porte
         * sulla stessa stanza, una chiusa e una aperta. Senza tetto la
         * sequenza era questa, tutta pubblica e senza account:
         *
         * 1. `GET /api/v1/sponsorships` restituisce, per ogni campagna, il suo
         *    `metric_token` (SponsorshipResource). Il token e' un HMAC per
         *    giorno: serve a impedire che si scriva su una campagna a caso, non
         *    a tenere segreto niente — qualunque client lo ottiene chiedendolo,
         *    ed e' giusto che sia cosi', perche' e' l'applicazione a doverlo
         *    usare. E' un dosso, non una serratura.
         * 2. La deduplica qui sotto si salta in due modi: ruotando
         *    `X-Installation-ID`, da cui `actor()` ricava la chiave, oppure —
         *    piu' semplice — mandando `clicks` con un `click_id` nuovo, che la
         *    disattiva per costruzione.
         * 3. Anche il limite globale `throttle:api` si conta, per gli anonimi,
         *    su quella stessa intestazione scelta dal client.
         *
         * Tre freni, e tutti e tre governati da chi attacca. Il risultato era
         * che i numeri di una campagna — cioe' quelli che finiscono in
         * fattura, e quelli che un inserzionista guarda per decidere se
         * ricomprare — si scrivevano a piacere da riga di comando.
         *
         * Il tetto sta **prima** della deduplica di proposito: la scorciatoia
         * del `click_id` deve poter saltare quella, mai questo. E si conta
         * sull'indirizzo perche' e' l'unico dato di questa richiesta che chi
         * chiama non puo' cambiare a costo zero. Trenta all'ora per campagna e
         * per indirizzo, gli stessi del web; con IPv6 l'indirizzo è il /64,
         * come sul web.
         */
        $tetto = 'sponsorship-metric-api:'.IpBucket::key($request->ip()).':'.$campaign->getKey().':'.$metric;

        if (RateLimiter::tooManyAttempts($tetto, maxAttempts: 30)) {
            return response()->json(status: 204);
        }

        RateLimiter::hit($tetto, decaySeconds: 3600);

        $actor = $this->actor($request);
        $dedupe = 'api-sponsor:'.hash('sha256', $actor.'|'.$campaign->getKey().'|'.$metric);

        $request->validate(['click_id' => 'nullable|uuid']);

        /*
         * La deduplica vale SEMPRE, anche con un `click_id`.
         *
         * Prima il primo termine della condizione la disattivava: bastava
         * mandare `clicks` con un uuid nuovo e `Cache::add()` non veniva
         * nemmeno chiamato. L'intenzione era giusta — `click_id` serve a non
         * contare due volte lo stesso clic reinviato dopo una rete che cade —
         * ma il modo la trasformava nel suo contrario: da chiave di
         * idempotenza a interruttore per spegnere il controllo, scelto da chi
         * chiama.
         *
         * L'uuid resta utile e rientra nella **chiave**: due invii dello
         * stesso clic condividono l'identificativo e collassano, due clic
         * distinti hanno uuid diversi e passano entrambi — che è esattamente
         * ciò che serve. Quello che non fa più è saltare la fila.
         */
        $chiaveClic = $metric === 'clicks' && $request->filled('click_id')
            ? $dedupe.':'.$request->string('click_id')->value()
            : $dedupe;

        if (! Cache::add($chiaveClic, true, now()->addMinutes(15))) {
            return response()->json(status: 204);
        }

        if ($recorder->shouldCount($request, $campaign, $this->currentUser($request))) {
            $recorder->record($campaign, $metric, $request, $this->channel($request));
        }

        return response()->json(status: 204);
    }

    /**
     * La strada del gettone usa e getta: la stessa del sito, con la città
     * dell'API. Qualunque cosa non torni — gettone falso, scaduto, di
     * un'altra campagna, campagna che non può più comparire — risponde 204 e
     * non conta, senza dire quale controllo ha fermato la richiesta.
     */
    private function withImpressionToken(Request $request, int $sponsorship, string $metric, ImpressionToken $tokens, RecordSponsorshipMetric $recorder): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $token = $request->header('X-Impression-Token');
        $claims = is_string($token) ? $tokens->verify($token, $now) : null;

        if ($claims === null || ! $claims->isFor($sponsorship)) {
            return response()->json(status: 204);
        }

        $campaign = $recorder->countableCampaign($sponsorship, (int) $this->city()->getKey(), $now, $metric, $claims->issuedAt);

        if ($campaign === null) {
            return response()->json(status: 204);
        }

        // Lo stesso tetto per indirizzo della strada di prima, e la stessa chiave.
        $tetto = 'sponsorship-metric-api:'.IpBucket::key($request->ip()).':'.$campaign->getKey().':'.$metric;

        if (RateLimiter::tooManyAttempts($tetto, maxAttempts: 30)) {
            return response()->json(status: 204);
        }

        RateLimiter::hit($tetto, decaySeconds: 3600);

        if ($recorder->shouldCount($request, $campaign, $this->currentUser($request))) {
            $recorder->recordWithToken($campaign, $claims, $metric, $request, $this->channel($request), $now);
        }

        return response()->json(status: 204);
    }

    /** Vero dopo la data fissata in `sponsorship.legacy_metric_token_until`. */
    private static function legacyClosed(): bool
    {
        $until = config('sponsorship.legacy_metric_token_until');

        if (! is_string($until) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $until) !== 1) {
            return false;
        }

        return CarbonImmutable::now('UTC')->greaterThan(CarbonImmutable::parse($until, 'Europe/Rome')->endOfDay());
    }

    /** L'app Android si presenta con l'identificativo dell'installazione; il resto è il sito. */
    private function channel(Request $request): string
    {
        return $request->hasHeader('X-Installation-ID') ? 'android' : 'web';
    }

    private function validToken(Request $request, Sponsorship $campaign): bool
    {
        $provided = $request->header('X-Metric-Token');

        if (! is_string($provided)) {
            return false;
        }

        foreach ([now('UTC'), now('UTC')->subDay()] as $day) {
            $expected = hash_hmac(
                'sha256',
                $campaign->getKey().'|'.$campaign->placement->value.'|'.$day->format('Y-m-d'),
                (string) config('app.key'),
            );

            if (hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }

    private function actor(Request $request): string
    {
        $installation = $request->header('X-Installation-ID');

        if (is_string($installation) && preg_match('/^[A-Za-z0-9._:-]{16,64}$/', $installation) === 1) {
            return 'installation:'.$installation;
        }

        $user = $request->user('sanctum');

        return $user === null ? 'ip:'.($request->ip() ?? 'unknown') : 'user:'.$user->getAuthIdentifier();
    }
}
