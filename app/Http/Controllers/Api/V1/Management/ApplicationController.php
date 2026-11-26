<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\CreateVenueApplication;
use App\Actions\SubmitVenueApplication;
use App\Enums\ApiErrorCode;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Applications\ClaimRequest;
use App\Http\Requests\Api\V1\Management\Applications\OrganizerApplicationRequest;
use App\Http\Requests\Api\V1\Management\Applications\VenueApplicationRequest;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Support\CurrentCity;
use App\Support\VenueApplications\ApplicationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Registrare o rivendicare dall'app (P13, W-AP-01…06): «Registra il tuo
 * locale» con la ricerca «è già a catalogo?», «Questo locale è mio»,
 * «Registra la tua organizzazione», e la conferma o il ritiro di una
 * richiesta scritta da ospite con l'indirizzo dell'account.
 *
 * Le stesse azioni dei moduli del sito (`SubmitVenueApplication`,
 * `CreateVenueApplication`), gli stessi tetti (tre richieste aperte, una
 * rivendicazione aperta per locale) e gli stessi secchi dei limiti di
 * frequenza. A differenza del sito si scrive solo da collegati con l'email
 * confermata: niente account creato dal modulo, niente Turnstile. Per questo
 * ogni richiesta dell'app entra subito in coda. Un ospite compila il modulo
 * con le letture pubbliche (elenchi, catalogo, locale da rivendicare), crea
 * l'account con `POST /auth/register` e l'app manda la richiesta da qui
 * quando arriva la sessione con l'email confermata (Q2, W-AP-02).
 */
final class ApplicationController extends Controller
{
    /**
     * Gli elenchi dei moduli.
     *
     * `types[]` `{value, label}` (il tipo di locale, facoltativo),
     * `municipality` (il comune proposto, quello della città), `account_email`
     * (a cui risponde la richiesta; `null` per un ospite, che la scrive nel
     * modulo) e i tetti del modulo. Pubblica, come la pagina del sito: un
     * ospite compila il modulo prima di avere l'account.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $city = app(CurrentCity::class)->get();

        return $this->json([
            'types' => array_map(static fn (VenueType $type): array => ['value' => $type->value, 'label' => $type->label()], VenueType::cases()),
            'municipality' => $city instanceof City ? $city->name : null,
            'account_email' => $user instanceof User ? $user->email : null,
            'limits' => [
                'pending' => CreateVenueApplication::MAX_PENDING_PER_EMAIL,
                'image_max_kb' => 5120,
                'image_min_width' => 400,
                'image_min_height' => 300,
                'claim_message_min' => 10,
            ],
        ]);
    }

    /**
     * «Il tuo locale è già nel catalogo?»
     *
     * I locali pubblici con il nome cercato (`q`, da 3 a 80 caratteri, al
     * massimo sei risultati): `{id, slug, name, address, municipality,
     * claimable}`. Un locale `claimable` si rivendica invece di registrarlo
     * di nuovo; gli altri hanno già un referente.
     */
    public function lookup(Request $request, SubmitVenueApplication $submit): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:80']]);

        return $this->json($submit->catalogue(trim((string) $data['q']))
            ->map(static fn (Venue $venue): array => [
                'id' => (int) $venue->getKey(),
                'slug' => (string) $venue->slug,
                'name' => (string) $venue->name,
                'address' => $venue->address,
                'municipality' => $venue->municipality,
                'claimable' => $venue->isClaimable(),
            ])->values()->all());
    }

    /**
     * Il locale da rivendicare.
     *
     * `{id, slug, name, type_label, address, municipality}`. 404 se il locale
     * non è pubblico o ha già un referente, anche solo invitato.
     */
    public function claimTarget(string $slug): JsonResponse
    {
        $venue = $this->claimable($slug);

        return $this->json([
            'id' => (int) $venue->getKey(),
            'slug' => (string) $venue->slug,
            'name' => (string) $venue->name,
            'type_label' => $venue->type->label(),
            'address' => $venue->address,
            'municipality' => $venue->municipality,
        ]);
    }

    /**
     * Registra il tuo locale.
     *
     * Multipart se c'è l'immagine (`image`), altrimenti JSON. 201 con
     * `{state, message, application}`; 409 con tre richieste già aperte.
     */
    public function storeVenue(VenueApplicationRequest $request, CreateVenueApplication $applications, SubmitVenueApplication $submit): JsonResponse
    {
        $user = $request->applicant();
        $email = $request->applicantEmail();
        if ($applications->limitReached($email, $user)) {
            throw new ApiException(ApiErrorCode::Conflict, (string) __('forms.application.limit_reached'));
        }

        $application = $submit->venue($request->validated(), $email, (int) $user->getKey(), $user->hasVerifiedEmail(), $request->venueImage());

        return $this->sent($application, $user, $user->hasVerifiedEmail() ? 'queued' : 'verify');
    }

    /**
     * Questo locale è mio.
     *
     * 201 con `{state, message, application}`; 404 se il locale non si può
     * rivendicare; 409 con una rivendicazione già aperta per lo stesso locale
     * o tre richieste aperte.
     */
    public function claim(ClaimRequest $request, string $slug, CreateVenueApplication $applications, SubmitVenueApplication $submit): JsonResponse
    {
        $venue = $this->claimable($slug);
        $user = $request->applicant();
        $email = $request->applicantEmail();
        if ($applications->limitReached($email, $user, (int) $venue->getKey())) {
            throw new ApiException(ApiErrorCode::Conflict, (string) __('forms.claim.limit_reached'));
        }

        $application = $submit->claim($venue, $request->validated(), $email, (int) $user->getKey(), $user->hasVerifiedEmail());

        return $this->sent($application, $user, $user->hasVerifiedEmail() ? 'queued' : 'verify');
    }

    /**
     * Registra la tua organizzazione.
     *
     * Entra subito in coda. 201 con `{state, message, application}`; 409 con
     * tre richieste già aperte.
     */
    public function storeOrganizer(OrganizerApplicationRequest $request, CreateVenueApplication $applications, SubmitVenueApplication $submit): JsonResponse
    {
        $user = $request->applicant();
        $email = $request->applicantEmail();
        if ($applications->limitReached($email, $user)) {
            throw new ApiException(ApiErrorCode::Conflict, (string) __('forms.application.limit_reached'));
        }

        $application = $submit->organizer($request->validated(), $email, (int) $user->getKey());

        return $this->sent($application, $user, 'organizer');
    }

    /**
     * Conferma e invia.
     *
     * Una richiesta scritta da ospite con l'indirizzo di questo account
     * (`can_confirm` in «Le mie richieste») entra in coda. 404 per una
     * richiesta di un altro, già in coda o già decisa.
     */
    public function confirm(Request $request, int $application, SubmitVenueApplication $submit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $pending = $this->awaiting($submit, $user, $application);
        if (! $user->hasVerifiedEmail()) {
            throw new ApiException(ApiErrorCode::Forbidden, (string) __('forms.application.verify_first'));
        }

        $submit->confirm($pending);

        return $this->json(['message' => __('forms.application.confirmed')]);
    }

    /**
     * Elimina una richiesta in attesa di conferma, con l'immagine. 404 come
     * per la conferma.
     */
    public function discard(Request $request, int $application, SubmitVenueApplication $submit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $submit->discard($this->awaiting($submit, $user, $application));

        return $this->json(['message' => __('forms.application.discarded')]);
    }

    private function awaiting(SubmitVenueApplication $submit, User $user, int $id): VenueApplication
    {
        $pending = $submit->awaiting($user, $id);
        Gate::forUser($user)->authorize('delete', $pending);

        return $pending;
    }

    /**
     * Si rivendica un locale pubblico che nessuno gestisce ancora: la stessa
     * domanda del modulo del sito (`Venue::isClaimable()`).
     */
    private function claimable(string $slug): Venue
    {
        $venue = Venue::query()->where('slug', $slug)->first();
        if (! $venue instanceof Venue || ! $venue->isClaimable()) {
            throw new ApiException(ApiErrorCode::NotFound, $venue?->status === VenueStatus::Approved ? (string) __('forms.claim.already_managed') : null);
        }

        return $venue;
    }

    private function sent(VenueApplication $application, User $user, string $state): JsonResponse
    {
        $application->loadMissing(['venue', 'organizer']);

        return $this->json([
            'state' => $state,
            'message' => __('forms.application.sent.'.$state),
            'application' => ApplicationPayload::item($application, $user, [], []),
        ], 201);
    }

    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}
