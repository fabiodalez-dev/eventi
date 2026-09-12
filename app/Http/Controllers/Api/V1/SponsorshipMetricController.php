<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class SponsorshipMetricController extends Controller
{
    use InteractsWithApi;

    public function __invoke(Request $request, int $sponsorship, string $metric): JsonResponse
    {
        if (! in_array($metric, ['impressions', 'clicks'], true)) {
            throw new ApiException(ApiErrorCode::NotFound);
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
         * per indirizzo, gli stessi del web.
         */
        $tetto = 'sponsorship-metric-api:'.($request->ip() ?? 'sconosciuto').':'.$campaign->getKey().':'.$metric;

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

        app(RecordSponsorshipMetric::class)->record($campaign, $metric, $request, $request->hasHeader('X-Installation-ID') ? 'android' : 'web');

        return response()->json(status: 204);
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
