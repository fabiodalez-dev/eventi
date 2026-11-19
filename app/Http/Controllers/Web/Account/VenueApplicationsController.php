<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Enums\ApplicationKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VenueApplication;
use App\Support\VenueApplications\ApplicationState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Le mie richieste»: le richieste di registrare o rivendicare un locale, o di
 * registrare un'organizzazione, mandate da chi è collegato. Una card per
 * richiesta con lo stato, cosa succede adesso e il pulsante giusto: confermare
 * l'email, aprire il pannello, scriverne una nuova.
 *
 * Mostra anche quelle che aspettano la conferma dell'email (toglie lo scope
 * globale del modello), ma sempre e solo quelle dell'account collegato.
 */
final class VenueApplicationsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $applications = VenueApplication::query()
            ->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->where('user_id', $user->getKey())
            ->with(['venue', 'organizer'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20);

        $venueIds = $user->venues()->pluck('venues.id')->all();
        $organizerIds = $user->managedOrganizers()->pluck('organizers.id')->all();
        $cards = collect($applications->items())
            ->map(fn (VenueApplication $application): array => $this->card($application, $user, $venueIds, $organizerIds))
            ->all();

        return view('account.applications', [
            'applications' => $applications,
            'cards' => $cards,
            'user' => $user,
            'meta' => new PageMeta(
                title: __('venue_applications.account.title'),
                heading: __('venue_applications.account.title'),
                indexable: false,
            ),
        ]);
    }

    /**
     * L'immagine allegata a una richiesta. Sta sul disco privato finché la
     * richiesta non è accolta: la vede chi l'ha mandata e chi può leggere le
     * richieste (la redazione, dalla pagina della richiesta in admin). Mai in
     * cache condivise, mai interpretata come altro che un'immagine.
     */
    public function image(Request $request, int $application): Response
    {
        $richiesta = VenueApplication::query()
            ->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->findOrFail($application);

        abort_unless(Gate::allows('view', $richiesta), 404);

        $path = $richiesta->image_path;
        abort_unless(is_string($path) && $path !== '' && str_starts_with($path, 'venue-applications/') && Storage::disk('local')->exists($path), 404);

        $mime = (string) Storage::disk('local')->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return Storage::disk('local')->response($path, 'richiesta-'.$richiesta->getKey().'.'.match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        }, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    /**
     * Quello che la card di una richiesta deve dire, deciso qui e non nella
     * vista: lo stato, il tono del distintivo, il testo di «cosa succede
     * adesso» e le azioni possibili.
     *
     * @param  list<int|string>  $venueIds
     * @param  list<int|string>  $organizerIds
     * @return array{application: VenueApplication, state: string, tone: string, kind: string, summary: string|null, next: string, panel: string|null, public: string|null, confirm: bool}
     */
    private function card(VenueApplication $application, User $user, array $venueIds, array $organizerIds): array
    {
        $kind = $application->kind();
        ['state' => $state, 'panel' => $panel, 'public' => $public, 'next' => $next, 'confirm' => $confirm] = ApplicationState::describe($application, $user, $venueIds, $organizerIds);

        $summary = $kind === ApplicationKind::Organizer
            ? null
            : implode(' · ', array_filter([$application->type->label(), $application->address]));

        return [
            'application' => $application,
            'state' => $state,
            'tone' => ['awaiting' => 'alert', 'pending' => 'soon', 'approved' => 'accent', 'rejected' => 'muted'][$state],
            'kind' => $kind->label(),
            'summary' => $summary === '' ? null : $summary,
            'next' => $next,
            'panel' => $panel,
            'public' => $public,
            'confirm' => $confirm,
        ];
    }
}
