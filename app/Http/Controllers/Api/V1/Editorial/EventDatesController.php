<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Enums\LineupRole;
use App\Enums\MembershipRequirement;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Enums\TicketTierStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Events\EditorialEventDates;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le date di un evento nella redazione (R3: R-EV-19…24): elenco, nuova,
 * modifica, sposta, annulla, tutto esaurito, elimina e il collegamento alla
 * biglietteria della data. Il lavoro lo fa `EditorialEventDates`, che usa
 * anche la sezione «Date» del pannello; decide la `EventOccurrencePolicy`.
 *
 * L'elenco lo legge chi vede l'evento (il moderatore lo vede in sola
 * lettura, come nella pagina dell'evento del pannello); tutto il resto è di
 * chi modifica. «Elimina» è pericolosa (password recente, decisione 4).
 */
final class EventDatesController extends Controller
{
    public function __construct(private readonly EditorialEventDates $dates) {}

    /** Le date in ordine di inizio, cinquanta per pagina. */
    public function index(Request $request, int $event): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        Gate::authorize('view', $record);
        $page = $this->dates->page($record, $request->integer('page', 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (EventOccurrence $date): array => $this->dates->row($date, $desk->user))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                ...$this->context($record),
            ],
        ]);
    }

    /** Una data con i campi del modulo. */
    public function show(int $event, int $occurrence): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        Gate::authorize('view', $record);

        return response()->json(['data' => $this->dates->row($this->date($record, $occurrence), $desk->user), 'meta' => $this->context($record)]);
    }

    /** «Aggiungi data». */
    public function store(Request $request, int $event): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        $date = $this->dates->save($record, null, $request->all(), $desk->user);
        $date->setRelation('event', $record);

        return response()->json(['data' => $this->dates->row($date, $desk->user), 'message' => 'Salvato'], 201);
    }

    /** «Modifica»: le chiavi assenti restano come sono. */
    public function update(Request $request, int $event, int $occurrence): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        $date = $this->dates->save($record, $this->date($record, $occurrence), $request->all(), $desk->user);
        $date->setRelation('event', $record);

        return response()->json(['data' => $this->dates->row($date, $desk->user), 'message' => 'Salvato']);
    }

    /**
     * «Sposta» di `shift_minutes` (anche negativi), con `scope` `single` o
     * `series` per una data di una ricorrenza.
     */
    public function shift(Request $request, int $event, int $occurrence): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        $date = $this->date($record, $occurrence);
        Gate::authorize('update', $date);
        $data = $request->validate([
            'shift_minutes' => ['required', 'integer', 'between:-525600,525600'],
            'scope' => ['nullable', Rule::enum(OccurrenceScope::class)],
        ], [], ['shift_minutes' => (string) __('admin.fields.shift_minutes')]);
        $changed = $this->dates->shift($date, (int) $data['shift_minutes'], self::scope($data));

        return $this->changed($record, $date, $changed, $desk);
    }

    /**
     * Lo stato della data dalla riga: `cancel` («Annulla la data», con
     * `status_note` e `scope`) oppure `sold_out` («Tutto esaurito», o
     * «Rimetti in programma» se lo è già; sempre la sola data).
     */
    public function status(Request $request, int $event, int $occurrence): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        $date = $this->date($record, $occurrence);
        Gate::authorize('update', $date);
        $data = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'sold_out'])],
            'status_note' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', Rule::enum(OccurrenceScope::class)],
        ], [], ['status_note' => (string) __('admin.fields.status_note')]);
        if ($data['action'] === 'cancel') {
            if ($date->status === OccurrenceStatus::Cancelled) {
                throw ValidationException::withMessages(['action' => 'Questa data è già annullata.']);
            }
            $changed = $this->dates->cancel($date, filled($data['status_note'] ?? null) ? (string) $data['status_note'] : null, self::scope($data));
        } else {
            $changed = $this->dates->toggleSoldOut($date);
        }

        return $this->changed($record, $date, $changed, $desk);
    }

    /** «Elimina» la data (nel cestino, come il pannello). */
    public function destroy(int $event, int $occurrence): Response
    {
        EditorialDesk::require();
        $record = EventController::findActive($event);
        $date = $this->date($record, $occurrence);
        Gate::authorize('delete', $date);
        $date->delete();

        return response()->noContent();
    }

    /** Una data dell'evento, o 404. */
    private function date(Event $event, int $id): EventOccurrence
    {
        $date = EventOccurrence::query()->where('event_id', $event->getKey())->whereKey($id)->first();
        if (! $date instanceof EventOccurrence) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        $date->setRelation('event', $event);

        return $date;
    }

    /**
     * Il quadro della schermata: l'evento in breve, il fuso in cui si
     * scrivono gli orari e che cosa si può fare.
     *
     * @return array<string, mixed>
     */
    private function context(Event $event): array
    {
        $event->loadMissing('city');
        $update = Gate::allows('update', $event);

        return [
            'event' => [
                'id' => (int) $event->getKey(),
                'title' => (string) $event->title,
                'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
                'city' => (string) $event->city->name,
                'timezone' => (string) $event->city->timezone,
                'currency' => $event->currency ?: 'EUR',
            ],
            'abilities' => [
                'create' => Gate::allows('create', [EventOccurrence::class, $event]),
                'generate' => $update && $event->recurrences()->exists(),
            ],
            // Le scelte del modulo della data e dei gesti, con le etichette del pannello.
            'choices' => [
                'occurrence_statuses' => self::pairs(OccurrenceStatus::options()),
                'lineup_roles' => self::pairs(LineupRole::options()),
                'ticket_tier_statuses' => self::pairs(TicketTierStatus::options()),
                'memberships' => self::pairs(MembershipRequirement::options()),
                'scopes' => array_map(fn (OccurrenceScope $scope): array => ['value' => $scope->value, 'label' => $scope->label(), 'help' => $scope->helpText()], OccurrenceScope::cases()),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function pairs(array $options): array
    {
        return array_map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label], $options, array_keys($options));
    }

    /** @param  array<string, mixed>  $data */
    private static function scope(array $data): OccurrenceScope
    {
        return OccurrenceScope::tryFrom((string) ($data['scope'] ?? '')) ?? OccurrenceScope::Single;
    }

    /** La data dopo un gesto, con quante date sono cambiate e il messaggio del pannello. */
    private function changed(Event $event, EventOccurrence $date, int $changed, EditorialDesk $desk): JsonResponse
    {
        $date->refresh()->setRelation('event', $event);

        return response()->json([
            'data' => $this->dates->row($date, $desk->user),
            'meta' => ['changed' => $changed],
            'message' => $changed === 0 ? __('admin.notifications.nothing_to_do') : __('admin.notifications.occurrences_updated', ['count' => $changed]),
        ]);
    }
}
