<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\ModerateVenueAction;
use App\Enums\ApiErrorCode;
use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Services\Editorial\EditorialVenueEditor;
use App\Services\Editorial\EditorialVenueExport;
use App\Services\Editorial\EditorialVenues;
use App\Services\Geo\AddressGeocoder;
use App\Support\ActivityHistory;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * I locali della redazione (R-VN-01…11, 13…15): l'elenco, la scheda, il
 * modulo, la moderazione, il ticketing, gli eventi collegati, la cronologia,
 * l'eliminazione e l'esportazione. Le immagini sono in
 * `VenueMediaController`, le persone in `VenueMemberController`.
 *
 * Ogni azione rifà la domanda del pannello alla `VenuePolicy`: il moderatore
 * legge, approva, rifiuta, sospende e verifica; modifica, ticketing ed
 * eliminazione sono di admin e super admin. La città di lavoro non filtra:
 * l'elenco del pannello mostra i locali di tutte le città.
 */
final class VenueController extends Controller
{
    use ListsPlaces;

    /**
     * L'elenco: `tab` (`all`, `pending`, `approved`, `suspended`), ricerca su
     * nome e comune, `filter[city|type|status|is_verified|auto_publish|inactive]`,
     * `sort` (`name`, `municipality`, `events_count`, con `-` davanti per il
     * verso opposto). Ogni riga ha le sue `abilities`.
     */
    public function index(Request $request, EditorialVenues $venues): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->can('venues.view'), 403);
        $tab = $this->listTab($request, EditorialVenues::TABS, 'all');
        $filters = $this->listFilters($request, EditorialVenues::FILTERS);
        $search = $this->listSearch($request);
        $page = $venues->paginate($tab, $filters, $search, $this->listSort($request), $this->listPerPage($request), $this->listPage($request));

        return response()->json($this->listPayload(
            $page,
            array_values(array_map(fn (Venue $venue): array => $venues->row($venue, $user), $page->items())),
            $venues->tabCounts($filters, $search),
        ));
    }

    /** Il foglio Excel dell'elenco, con gli stessi criteri di `index` (decisione 8). */
    public function export(Request $request, EditorialVenueExport $export): BinaryFileResponse
    {
        abort_unless($this->user($request)->can('venues.view'), 403);

        return $export->download(
            $this->listTab($request, EditorialVenues::TABS, 'all'),
            $this->listFilters($request, EditorialVenues::FILTERS),
            $this->listSearch($request),
            $this->listSort($request),
        );
    }

    /**
     * Le scelte del modulo del locale (città, comuni per città, quartieri,
     * tipi, stati, piani, indicizzazione), per «Nuovo locale».
     */
    public function choices(Request $request): JsonResponse
    {
        Gate::forUser($this->user($request))->authorize('create', Venue::class);

        return response()->json(['data' => EditorialVenueEditor::choices()]);
    }

    /**
     * «Trova» del modulo (scheda Dove): dall'indirizzo e dal comune al punto
     * sulla mappa, con lo stesso servizio del pannello. 422 su `address` se
     * l'indirizzo non si trova: va messo a mano.
     */
    public function locate(Request $request, AddressGeocoder $geocoder): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(Gate::forUser($user)->allows('create', Venue::class) || $user->can(Permission::UpdateVenues->value), 403);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
        ]);
        $point = $geocoder->coordinate((string) $data['address'], isset($data['municipality']) ? (string) $data['municipality'] : null);
        if ($point === null) {
            throw ValidationException::withMessages(['address' => __('admin.map.not_located')]);
        }

        return response()->json(['data' => ['lat' => $point['lat'], 'lng' => $point['lng'], 'message' => (string) __('admin.map.located')]]);
    }

    /** La scheda in sola lettura: testata, informazioni, descrizione, prossimi eventi, persone. */
    public function show(Request $request, EditorialVenues $venues, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('view', $record);

        return response()->json(['data' => $venues->overview($record, $user)]);
    }

    /** Il modulo completo (scheda pubblica e campi della redazione), per chi può modificare. */
    public function form(Request $request, EditorialVenueEditor $editor, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('update', $record);

        return response()->json(['data' => $editor->form($record, $user)]);
    }

    /** Un locale nuovo (`VenuePolicy::create`). Risponde con il modulo del locale creato. */
    public function store(Request $request, EditorialVenueEditor $editor): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('create', Venue::class);
        $venue = $editor->create($request->all(), $user);

        return response()->json(['data' => $editor->form($venue, $user)], 201);
    }

    /** Salva le chiavi inviate (una sezione alla volta); le altre restano come sono. */
    public function update(Request $request, EditorialVenueEditor $editor, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('update', $record);
        $saved = $editor->update($record, $request->all(), $user);

        return response()->json(['data' => $editor->form($saved, $user)]);
    }

    /** «Approva»: non su un locale già approvato (409). Avvisa chi lo gestisce. */
    public function approve(Request $request, EditorialVenues $venues, ModerateVenueAction $action, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->moderated($user, $venue);
        $this->allowed(ModerateVenueAction::canApprove($record));
        $action->approve($record, $user);

        return $this->moderatedResponse($venues, $record, $user, 'admin.notifications.approved');
    }

    /** «Rifiuta» con il motivo (obbligatorio): non su un locale già rifiutato. */
    public function reject(Request $request, EditorialVenues $venues, ModerateVenueAction $action, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->moderated($user, $venue);
        $reason = $this->reason($request);
        $this->allowed(ModerateVenueAction::canReject($record));
        $action->reject($record, $user, $reason);

        return $this->moderatedResponse($venues, $record, $user, 'admin.notifications.rejected');
    }

    /**
     * «Sospendi» con il motivo: solo un locale approvato. Esce dal sito
     * pubblico finché non lo si approva di nuovo. Azione pericolosa: chiede
     * la password recente.
     */
    public function suspend(Request $request, EditorialVenues $venues, ModerateVenueAction $action, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->moderated($user, $venue);
        $reason = $this->reason($request);
        $this->allowed(ModerateVenueAction::canSuspend($record));
        $action->suspend($record, $reason);

        return $this->moderatedResponse($venues, $record, $user, 'admin.notifications.suspended');
    }

    /** «Verifica» o «Togli la verifica»: `verified` (sì/no); senza, inverte. */
    public function verify(Request $request, EditorialVenues $venues, ModerateVenueAction $action, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->moderated($user, $venue);
        $request->validate(['verified' => ['sometimes', 'boolean']]);
        $action->setVerified($record, $request->has('verified') ? $request->boolean('verified') : ! $record->is_verified);

        return $this->moderatedResponse($venues, $record, $user, 'admin.notifications.verified');
    }

    /**
     * «Abilita il ticketing» (scheda Ticketing del modulo): solo admin e
     * super admin, come il campo del pannello. Corpo `{"enabled": true}`.
     */
    public function ticketing(Request $request, EditorialVenues $venues, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('update', $record);
        abort_unless($user->hasAnyRole(['admin', 'super_admin']), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $record->ticketing_enabled = (bool) $data['enabled'];
        $record->save();

        return response()->json(['data' => $venues->overview($record->refresh(), $user)]);
    }

    /** Elimina il locale (cestino, come «Elimina» del pannello). Azione pericolosa: chiede la password recente. */
    public function destroy(Request $request, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('delete', $record);
        $record->delete();

        return response()->json(['data' => ['id' => (int) $record->getKey(), 'deleted' => true]]);
    }

    /** Gli eventi del locale: ricerca sul titolo, `filter[status]`, i più recenti prima. */
    public function events(Request $request, EditorialVenues $venues, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('view', $record);
        $status = EventStatus::tryFrom($this->listFilters($request, ['status'])['status'] ?? '');
        $timezone = $record->city->timezone ?? config()->string('app.timezone');
        $page = $venues->events($record, $status, $this->listSearch($request), $this->listPerPage($request), $this->listPage($request));

        return response()->json($this->listPayload(
            $page,
            array_values(array_map(fn (Event $event): array => $venues->eventRow($event, $user, $timezone), $page->items())),
        ));
    }

    /** La cronologia delle modifiche, dalla più recente; ogni riga dice se si può ripristinare. */
    public function activity(Request $request, EditorialVenues $venues, int $venue): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('view', $record);
        $canUpdate = Gate::forUser($user)->allows('update', $record);
        $timezone = $record->city->timezone ?? config()->string('app.timezone');
        $page = $venues->activity($record, $this->listPerPage($request), $this->listPage($request));

        return response()->json($this->listPayload(
            $page,
            array_values(array_map(fn (Activity $activity): array => $venues->activityRow($activity, $record, $canUpdate, $timezone), $page->items())),
        ));
    }

    /** «Ripristina» una riga della cronologia: rimette i contenuti di prima (`VenuePolicy::update`). */
    public function restoreActivity(Request $request, EditorialVenueEditor $editor, int $venue, int $activity): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->venue($venue);
        Gate::forUser($user)->authorize('update', $record);
        $row = $record->activitiesAsSubject()->whereKey($activity)->first();
        if (! $row instanceof Activity) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        $this->allowed(ActivityHistory::restorable($row, $record), (string) __('admin.activity.restore_empty'));
        ActivityHistory::restore($record, $row);

        return response()->json(['data' => [
            'message' => (string) __('admin.activity.restored'),
            'venue' => $editor->form($record->refresh(), $user),
        ]]);
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** Il locale per id (mai per slug), anche se non pubblicato; 404 se non c'è o è nel cestino. */
    private function venue(int $id): Venue
    {
        $venue = Venue::query()->with('city')->find($id);
        if (! $venue instanceof Venue) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $venue;
    }

    private function moderated(User $user, int $id): Venue
    {
        $venue = $this->venue($id);
        Gate::forUser($user)->authorize('moderate', $venue);

        return $venue;
    }

    /** Un'azione che con lo stato di adesso non ha senso: il pannello non mostra il pulsante, qui 409. */
    private function allowed(bool $allowed, ?string $message = null): void
    {
        if (! $allowed) {
            throw new ApiException(ApiErrorCode::Conflict, $message);
        }
    }

    /** Il motivo di un rifiuto o di una sospensione: obbligatorio, come nel pannello. */
    private function reason(Request $request): string
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:65535']], [], ['reason' => __('admin.fields.rejection_reason')]);

        return trim((string) $data['reason']);
    }

    private function moderatedResponse(EditorialVenues $venues, Venue $venue, User $user, string $message): JsonResponse
    {
        return response()->json(['data' => [
            'message' => (string) __($message),
            'venue' => $venues->overview($venue->refresh(), $user),
        ]]);
    }
}
