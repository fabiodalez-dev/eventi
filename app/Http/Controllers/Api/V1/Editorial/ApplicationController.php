<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\ReviewVenueApplication;
use App\Enums\ApiErrorCode;
use App\Enums\ApplicationKind;
use App\Enums\VenueType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\User;
use App\Models\VenueApplication;
use App\Services\Editorial\EditorialApplications;
use App\Support\Editorial\EditorialDesk;
use App\Support\Venues\VenueApplicationImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le richieste di iscrizione (R-AP-01…06): la coda, la pagina di una
 * richiesta, «Approva», «Rifiuta», le note interne, l'eliminazione e
 * l'immagine allegata. Le decisioni passano da `ReviewVenueApplication`, la
 * stessa regola del pannello (lettura sotto lock, contatore del menu
 * azzerato). Le richieste che aspettano ancora la conferma dell'email non
 * esistono qui: 404.
 */
final class ApplicationController extends Controller
{
    use ListsPlaces;

    public function __construct(private readonly EditorialApplications $applications) {}

    /**
     * La coda: `tab` (`pending` predefinita, `approved`, `rejected`, `all`)
     * con i conteggi, `filter[kind|type]`, ricerca su nome e contatto, `sort`
     * (`created_at`, `venue_name`; predefinito il più recente prima).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', VenueApplication::class);
        $filters = $this->listFilters($request, ['kind', 'type']);
        $page = $this->applications->paginate(
            $this->listTab($request, EditorialApplications::TABS, EditorialApplications::DEFAULT_TAB),
            $filters,
            $this->listSearch($request),
            $this->listSort($request),
            $this->listPerPage($request),
            $this->listPage($request),
        );
        $timezone = EditorialDesk::require()->timezone();

        return response()->json([
            ...$this->listPayload(
                $page,
                array_values(array_map(fn (VenueApplication $application): array => $this->applications->row($application, $user, $timezone), $page->items())),
                $this->applications->tabCounts(),
            ),
            'filters' => [
                'kind' => array_map(static fn (ApplicationKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], ApplicationKind::cases()),
                'type' => array_map(static fn (VenueType $type): array => ['value' => $type->value, 'label' => $type->label()], VenueType::cases()),
            ],
        ]);
    }

    /** La pagina di una richiesta, sezione per sezione come il pannello, con i doppioni probabili. */
    public function show(Request $request, int $application): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->application($user, $application, 'view');

        return response()->json(['data' => $this->applications->detail($record, $user, EditorialDesk::require()->timezone())]);
    }

    /**
     * «Approva»: crea il locale (o l'organizzatore, o consegna il locale
     * rivendicato). 422 su `status` se è già approvata. Risponde con la
     * richiesta aggiornata e quello che è nato.
     */
    public function approve(Request $request, ReviewVenueApplication $review, int $application): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->application($user, $application, 'update');
        $kind = $record->kind();
        $subject = $review->approve($record, $user);

        [$title, $body] = match (true) {
            $subject instanceof Organizer => [__('venue_applications.approved_organizer', ['organizer' => $subject->name]), __('venue_applications.approved_organizer_body')],
            $kind === ApplicationKind::Claim => [__('venue_applications.approved_claim', ['venue' => $subject->name]), __('venue_applications.approved_claim_body')],
            default => [__('venue_applications.approved', ['venue' => $subject->name]), __('venue_applications.approved_body')],
        };

        return response()->json(['data' => [
            'message' => (string) $title,
            'detail' => (string) $body,
            'created' => ['kind' => $subject instanceof Organizer ? 'organizer' : 'venue', 'id' => (int) $subject->getKey(), 'name' => (string) $subject->name],
            'application' => $this->applications->detail($record->refresh(), $user, EditorialDesk::require()->timezone()),
        ]]);
    }

    /**
     * «Rifiuta»: `notes` (motivo, obbligatorio, fino a 5000 caratteri),
     * `notify` (avvisa chi l'ha mandata, solo se ha un account), `message`
     * (facoltativo, fino a 2000). 409 se nel frattempo qualcuno ha già deciso.
     */
    public function reject(Request $request, ReviewVenueApplication $review, int $application): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->application($user, $application, 'update');
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:5000'],
            'notify' => ['sometimes', 'boolean'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [], [
            'notes' => __('venue_applications.reject_reason'),
            'message' => __('venue_applications.admin.reject_message'),
        ]);
        $notify = (bool) ($data['notify'] ?? false);
        $result = $review->reject($record, $user, (string) $data['notes'], $notify, $notify && isset($data['message']) ? (string) $data['message'] : null);
        if ($result === null) {
            throw new ApiException(ApiErrorCode::Conflict, (string) __('venue_applications.admin.already_decided'));
        }

        return response()->json(['data' => [
            'message' => (string) __('venue_applications.rejected'),
            'detail' => $result['notified'] ? (string) __('venue_applications.admin.rejected_notified') : null,
            'application' => $this->applications->detail($result['application'], $user, EditorialDesk::require()->timezone()),
        ]]);
    }

    /**
     * Le note interne, il solo campo che si scrive nella pagina del pannello:
     * salvarle non firma la richiesta e non ne cambia lo stato.
     */
    public function notes(Request $request, int $application): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->application($user, $application, 'update');
        $data = $request->validate(['notes' => ['present', 'nullable', 'string', 'max:5000']], [], ['notes' => __('admin.fields.notes')]);
        $record->forceFill(['notes' => is_string($data['notes']) ? $data['notes'] : null])->save();

        return response()->json(['data' => [
            'message' => (string) __('venue_applications.admin.notes_saved'),
            'application' => $this->applications->detail($record->refresh(), $user, EditorialDesk::require()->timezone()),
        ]]);
    }

    /** Elimina la richiesta (`VenueApplicationPolicy::delete`). Azione pericolosa: chiede la password recente. */
    public function destroy(Request $request, int $application): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->application($user, $application, 'delete');
        $record->delete();

        return response()->json(['data' => ['id' => $application, 'deleted' => true]]);
    }

    /** L'immagine allegata, dal disco privato (`venue_applications.view`). */
    public function image(Request $request, int $application): Response
    {
        $user = $this->user($request);

        return VenueApplicationImage::response($this->application($user, $application, 'view'));
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** La richiesta in coda per id, con la domanda alla `VenueApplicationPolicy`. */
    private function application(User $user, int $id, string $ability): VenueApplication
    {
        $application = VenueApplication::query()->find($id);
        if (! $application instanceof VenueApplication) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize($ability, $application);

        return $application;
    }
}
