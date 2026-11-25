<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Analytics\AnalyticsChannelRequest;
use App\Models\Venue;
use App\Services\Analytics\EventShares;
use App\Services\Management\ManagedAnalytics;
use App\Services\Management\ManagedContent;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * I canali propri del locale e i loro QR (W-ST-06), la sezione «Canali» di
 * «Statistiche in dettaglio»: l'elenco con link breve, condivisioni e
 * aperture, e la creazione di un canale sulla scheda del locale o su un suo
 * evento. Solo il referente (`AnalyticsChannelRequest`).
 *
 * Le regole del nome e del tetto di dodici canali sono quelle del pannello
 * (`EventShares::channelProblem`), con lo stesso messaggio sotto il campo.
 */
final class AnalyticsChannelController extends Controller
{
    public function __construct(private readonly ManagedAnalytics $analytics, private readonly EventShares $shares) {}

    public function index(AnalyticsChannelRequest $request): JsonResponse
    {
        return response()->json($this->analytics->channels($this->venue()));
    }

    public function store(AnalyticsChannelRequest $request): JsonResponse
    {
        $venue = $this->venue();
        $label = (string) $request->validated('label');
        $problem = $this->shares->channelProblem($venue, $label);
        if ($problem !== null) {
            throw ValidationException::withMessages(['label' => EventShares::channelProblemMessage($problem)]);
        }
        // L'evento si cerca fra quelli del locale: un identificativo inventato non trova nulla.
        $eventId = $request->validated('event');
        $event = $eventId === null ? null : ManagedContent::for($venue)->events()->whereKey((int) $eventId)->first();
        if ($eventId !== null && $event === null) {
            throw ValidationException::withMessages(['event' => __('validation.exists', ['attribute' => __('event-shares.custom.event')])]);
        }
        $link = $this->shares->addChannel($venue, $label, $event);

        return response()->json([
            ...$this->analytics->channels($venue),
            'created' => $link->code,
            'message' => __('event-shares.custom.created', ['channel' => EventShares::channelLabel($link->channel)]),
        ], 201);
    }

    private function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }
}
