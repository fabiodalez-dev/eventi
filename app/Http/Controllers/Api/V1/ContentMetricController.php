<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\ContentMetric;
use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Analytics\RecordContentMetric;
use App\Support\IpBucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/reports/content/{type}/{id}`: una misura della scheda di un evento,
 * di un locale o di un organizzatore aperta dall'app — la stessa che il sito
 * scrive con `/misure/{type}/{id}` (`Web\ContentMetricController`).
 *
 * ## La stessa regola del sito
 *
 * Sul sito si conta solo con il consenso alle statistiche, letto dal cookie.
 * L'app non ha quel cookie: il consenso lo raccoglie lei e lo dichiara qui con
 * `consent: true`. Senza, la risposta è la stessa (204) e non si scrive nulla —
 * così l'app non deve distinguere i due casi e nessuno capisce dalla risposta
 * che cosa ha scelto la persona (decisione del 19/11/2026: senza consenso si
 * contano soltanto le aperture dei link brevi).
 *
 * ## Niente identificativi
 *
 * Il corpo accetta soltanto la misura, la data (facoltativa) e il consenso:
 * nessun identificativo dell'installazione o della persona, e nei contatori
 * finisce solo un +1 per giorno. L'indirizzo serve a un tetto in memoria —
 * trenta misure uguali l'ora per scheda, come per le sponsorizzazioni — e non
 * viene salvato.
 */
final class ContentMetricController extends Controller
{
    private const HOURLY_CAP = 30;

    /**
     * Misura di una scheda.
     *
     * `metric` è una delle misure del sito (`views`, `shares`, …); `consent`
     * vero solo se la persona ha accettato le statistiche, altrimenti non si
     * conta nulla. Risponde sempre 204.
     */
    public function __invoke(Request $request, string $type, int $id, RecordContentMetric $record): JsonResponse
    {
        if (! config()->boolean('eventi.analytics.app_content_metrics')) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $data = $request->validate([
            'metric' => ['required', 'string', Rule::enum(ContentMetric::class)],
            'occurrence' => ['nullable', 'integer', 'min:1'],
            'consent' => ['sometimes', 'boolean'],
        ]);

        $occurrence = isset($data['occurrence']) ? (int) $data['occurrence'] : null;

        $this->ensureVisible($type, $id, $occurrence);

        if (! $request->boolean('consent')) {
            return $this->noContent();
        }

        $tetto = 'content-metric-api:'.IpBucket::key($request->ip()).':'.$type.':'.$id.':'.$data['metric'];

        if (RateLimiter::tooManyAttempts($tetto, self::HOURLY_CAP)) {
            return $this->noContent();
        }

        RateLimiter::hit($tetto, 3600);

        $record->record($type, $id, ContentMetric::from($data['metric']), $occurrence);

        return $this->noContent();
    }

    /**
     * La scheda deve esistere ed essere pubblica anche quando non si conta:
     * altrimenti il 204 senza consenso direbbe «sì» a qualunque numero.
     */
    private function ensureVisible(string $type, int $id, ?int $occurrence): void
    {
        $exists = match ($type) {
            'event' => Event::query()->whereKey($id)->whereIn('status', [EventStatus::Published, EventStatus::Archived])
                ->when($occurrence !== null, fn ($query) => $query->whereHas('occurrences', fn ($dates) => $dates->whereKey($occurrence)))
                ->exists(),
            'venue' => $occurrence === null && Venue::query()->whereKey($id)->where('status', VenueStatus::Approved)->exists(),
            'organizer' => $occurrence === null && Organizer::query()->whereKey($id)->where('is_active', true)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
    }

    private function noContent(): JsonResponse
    {
        return response()->json(status: 204)->header('Cache-Control', 'private, no-store');
    }
}
