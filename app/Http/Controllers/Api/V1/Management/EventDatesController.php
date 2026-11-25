<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\EventStatus;
use App\Enums\RecurrenceFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Dates\DateDeleteRequest;
use App\Http\Requests\Api\V1\Management\Dates\DateIndexRequest;
use App\Http\Requests\Api\V1\Management\Dates\DateSaveRequest;
use App\Http\Requests\Api\V1\Management\Dates\DateStatusRequest;
use App\Http\Requests\Api\V1\Management\Dates\DateViewRequest;
use App\Http\Requests\Api\V1\Management\Dates\NextDateSoldOutRequest;
use App\Http\Requests\Api\V1\Management\Dates\RecurrenceRequest;
use App\Models\EventOccurrence;
use App\Services\Analytics\OccurrenceAnalytics;
use App\Services\Events\EventDates;
use App\Services\Events\EventPoster;
use App\Services\Events\RecurrenceSetup;
use App\Services\Management\ManagedEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Le date di un evento nell'app (P2): elenco con i numeri, aggiungi e
 * modifica nei due moduli dei pannelli, annulla ed esaurito, elimina,
 * «Ripeti», «Tutto esaurito» sulla prossima data, locandina in PDF.
 *
 * Le regole stanno in `EventDates` (e, per il ritorno in revisione
 * dell'organizzazione, in `OrganizerEventEditor`), le stesse che usano le
 * sezioni delle date dei pannelli. Le differenze fra locale e organizzazione
 * le fanno rispettare le rotte e il modulo della richiesta.
 */
final class EventDatesController extends Controller
{
    public function __construct(private readonly EventDates $dates) {}

    public function index(DateIndexRequest $request): JsonResponse
    {
        $event = $request->event();
        $subject = $request->subject();
        $page = $this->dates->page($event, $request->string('period')->toString() ?: null, $request->integer('page', 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (EventOccurrence $date): array => $this->dates->row($date, $subject))->values()->all(),
            'meta' => [
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
                'total' => $page->total(),
                ...$this->dates->context($event, $subject),
            ],
        ]);
    }

    /** Una data con i campi del modulo, per riaprirla in modifica, e il quadro dell'elenco. */
    public function show(DateViewRequest $request): JsonResponse
    {
        $event = $request->event();
        $subject = $request->subject();
        $date = OccurrenceAnalytics::withTotals(EventOccurrence::query()->where('event_occurrences.event_id', $event->getKey()))
            ->whereKey($request->occurrence()->getKey())->firstOrFail();
        $date->setRelation('event', $event);

        return response()->json(['data' => $this->dates->row($date, $subject), 'meta' => $this->dates->context($event, $subject)]);
    }

    /** «Aggiungi data»: il modulo del soggetto. L'organizzazione può rimandare l'evento in revisione. */
    public function store(DateSaveRequest $request): JsonResponse
    {
        return $this->save($request, null, 201);
    }

    /** «Modifica»: le chiavi assenti restano come sono. */
    public function update(DateSaveRequest $request): JsonResponse
    {
        return $this->save($request, $request->occurrence(), 200);
    }

    /** «Annulla» (anche la serie), «Tutto esaurito», «Di nuovo disponibile»: solo il locale. */
    public function status(DateStatusRequest $request): JsonResponse
    {
        $date = $request->occurrence();
        $changed = $this->dates->changeStatus($date, $request->status(), $request->scope());

        return $this->one($date->refresh(), $request, ['changed' => $changed]);
    }

    /** «Elimina»: solo il locale, come il pannello. */
    public function destroy(DateDeleteRequest $request): Response
    {
        $request->occurrence()->delete();

        return response()->noContent();
    }

    /**
     * «Ripeti» (locale): crea o aggiorna la regola e genera le date. Come il
     * pulsante del pannello, serve almeno una data da cui partire.
     */
    public function recurrence(RecurrenceRequest $request, RecurrenceSetup $setup): JsonResponse
    {
        $event = $request->event();
        if (! $event->occurrences()->exists()) {
            throw ValidationException::withMessages(['frequency' => 'Aggiungi prima una data: la ripetizione parte da lì.']);
        }
        $weekdays = $request->input('weekdays', []);
        $created = $setup->apply(
            $event,
            RecurrenceFrequency::from($request->string('frequency')->toString()),
            is_array($weekdays) ? array_values($weekdays) : [],
            $request->string('until')->toString() ?: null,
        );

        return response()->json(['data' => $this->dates->recurrence($event), 'meta' => ['created' => $created]]);
    }

    /**
     * «Tutto esaurito» / «Di nuovo disponibile» sulla prossima data, dalla
     * riga dell'elenco eventi del locale. Il valore è esplicito (non un
     * interruttore): due telefoni che toccano insieme non si annullano a
     * vicenda.
     */
    public function soldOutNext(NextDateSoldOutRequest $request): JsonResponse
    {
        $event = $request->event();
        $next = $this->dates->nextDate($event, ManagedEvents::city($request->subject()->model));
        if ($next === null) {
            throw ValidationException::withMessages(['sold_out' => 'Questo evento non ha date in arrivo.']);
        }
        Gate::forUser($request->actor())->authorize('update', $next);
        $next->setRelation('event', $event);
        $changed = $this->dates->setSoldOut($next, $request->boolean('sold_out'));

        return $this->one($next->refresh(), $request, ['changed' => $changed]);
    }

    /**
     * La locandina A4 in PDF con il QR, la stessa del sito, per gli eventi
     * pubblicati o archiviati. Lo stesso secchio del sito (`event-poster`).
     */
    public function poster(DateViewRequest $request, EventPoster $poster): Response
    {
        $event = $request->event();
        abort_unless(in_array($event->status, [EventStatus::Published, EventStatus::Archived], true), 404);
        $date = $request->occurrence();

        return response($poster->pdf($date), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$poster->filename($date).'"',
        ]);
    }

    private function save(DateSaveRequest $request, ?EventOccurrence $date, int $status): JsonResponse
    {
        $event = $request->event();
        $data = $request->validated();
        $sent = false;
        if ($request->subject()->isVenue()) {
            $date = $this->dates->saveVenueDate($event, $date, $data);
        } else {
            [$date, $sent] = $this->dates->saveOrganizerDate($event, $date, $data, $request->actor());
        }

        return $this->one($date->refresh(), $request, ['sent_to_review' => $sent], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function one(EventOccurrence $date, DateIndexRequest|DateSaveRequest|DateStatusRequest|DateViewRequest|NextDateSoldOutRequest $request, array $meta, int $status = 200): JsonResponse
    {
        $date->setRelation('event', $request->event());

        return response()->json(['data' => $this->dates->row($date, $request->subject()), 'meta' => $meta], $status);
    }
}
