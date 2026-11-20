<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\SponsorshipPlacement;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use App\Support\IpBucket;
use App\Support\Sponsorship\BotFilter;
use App\Support\Sponsorship\MetricExclusions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Il segnale di occasione: uno spazio sponsorizzabile è stato visto mentre
 * mostrava il contenuto redazionale, perché nessuno l'aveva comprato.
 *
 * Serve a una cosa sola: sapere quanto traffico ha ogni spazio anche quando
 * non è venduto, per stimare quante visualizzazioni un periodo può dare prima
 * di venderle. Aggiunge una vista all'aggregato orario della città e della
 * collocazione (`sponsorship_slot_hourly.views`), e nient'altro: nessuna
 * campagna, nessun dato sulla persona.
 *
 * Il browser lo manda con la stessa regola della visualizzazione (metà
 * elemento nello schermo per un secondo, scheda visibile, niente programmi
 * automatici), **con il gettone dell'occasione** che lo spazio vuoto porta
 * (`ImpressionToken::verifyOpportunity()`): città e collocazione vengono dal
 * gettone, e ogni gettone conta una volta sola.
 *
 * Non è «solo una previsione»: la previsione decide quanto spazio ricevono
 * le campagne a visualizzazioni e quanta capacità vede chi vende. Per questo,
 * oltre al gettone, un limite per indirizzo stretto
 * (`sponsorship.opportunity_per_hour`, 12 all'ora per città e collocazione):
 * una persona che naviga ne manda poche, e contarne meno del vero costa meno
 * che contarne troppe.
 *
 * La risposta è sempre 204, come per le misure.
 */
final class SponsorshipOpportunityController extends Controller
{
    /** Quante ore resta il ricordo di un gettone già usato: la sua vita. */
    private const NONCE_HOURS = 6;

    public function __invoke(Request $request, RecordSponsorshipMetric $recorder, ImpressionToken $tokens): JsonResponse
    {
        $token = $request->input('token');
        $claims = is_string($token) ? $tokens->verifyOpportunity($token, CarbonImmutable::now('UTC')) : null;

        if ($claims === null || SponsorshipPlacement::tryFrom($claims['placement']) === null
            || BotFilter::isBot($request->userAgent()) || MetricExclusions::isEditorialStaff($request->user())) {
            return response()->json(status: 204);
        }

        $cityId = $claims['city'];
        $placement = $claims['placement'];

        if (City::timezones()->firstWhere('id', $cityId) === null) {
            return response()->json(status: 204);
        }

        $chiave = 'sponsorship-opportunity:'.IpBucket::key($request->ip()).':'.$cityId.':'.$placement;
        $tetto = max(1, config()->integer('sponsorship.opportunity_per_hour', 12));

        if (RateLimiter::tooManyAttempts($chiave, maxAttempts: $tetto)) {
            return response()->json(status: 204);
        }

        /* Un gettone, un'occasione: lo stesso segno rimandato non conta due volte. */
        if (! Cache::add('sponsorship-opportunity-nonce:'.$claims['nonce'], true, now()->addHours(self::NONCE_HOURS))) {
            return response()->json(status: 204);
        }

        RateLimiter::hit($chiave, decaySeconds: 3600);

        $recorder->recordOpportunity($cityId, $placement, 'web');

        return response()->json(status: 204);
    }
}
