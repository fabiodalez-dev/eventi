<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Events\EditionAlerts;
use App\Services\Ticketing\AvailabilityAlerts;
use App\Services\Ticketing\BookingOpeningAlerts;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gli avvisi di una volta, dal sito e dall'API: «tornano disponibili»,
 * «aprono le prenotazioni» (su una data) e «prossima edizione» (su un
 * evento). La rotta dice quale con il default `kind`; le regole su quando
 * l'avviso si può chiedere stanno nei rispettivi servizi.
 */
final class OneTimeAlertController extends Controller
{
    /** @var array<string, array{0: class-string, 1: string}> tipo => [servizio, prefisso dei testi] */
    private const KINDS = [
        'availability' => [AvailabilityAlerts::class, 'ticketing.availability'],
        'opening' => [BookingOpeningAlerts::class, 'ticketing.opening'],
        'edition' => [EditionAlerts::class, 'events.edition_alert'],
    ];

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::collection($this->service($this->kind($request))->ids($this->user($request)));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $kind = $this->kind($request);
        $subject = $this->subject($request);
        $service = $this->service($kind);
        if ($service instanceof EditionAlerts) {
            $model = Event::query()->whereKey($subject)->whereIn('status', [EventStatus::Published, EventStatus::Archived])->firstOrFail();
            $key = 'event_id';
        } else {
            $model = EventOccurrence::query()->with('event')->findOrFail($subject);
            abort_unless($model->event?->status === EventStatus::Published, 404);
            $key = 'occurrence_id';
        }
        $service->subscribe($this->user($request), $model);

        return $request->is('api/*')
            ? ApiResponse::item([$key => (int) $model->getKey()], status: 201)
            : back()->with('status', __(self::KINDS[$kind][1].'.stored'));
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        $kind = $this->kind($request);
        $this->service($kind)->unsubscribe($this->user($request), $this->subject($request));

        return $request->is('api/*') ? response()->json(null, 204) : back()->with('status', __(self::KINDS[$kind][1].'.removed'));
    }

    /*
     * Tipo e soggetto si leggono dalla rotta per nome: Laravel passa i
     * parametri al metodo per posizione, e il default `kind` arriva dopo
     * quelli del percorso.
     */
    private function kind(Request $request): string
    {
        return (string) $request->route('kind');
    }

    private function subject(Request $request): int
    {
        return (int) $request->route('subject');
    }

    private function service(string $kind): AvailabilityAlerts|BookingOpeningAlerts|EditionAlerts
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        /** @var AvailabilityAlerts|BookingOpeningAlerts|EditionAlerts $service */
        $service = app(self::KINDS[$kind][0]);

        return $service;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
