<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Account\RequestRegistration;
use App\Actions\CreateVenueApplication;
use App\Actions\SubmitVenueApplication;
use App\DTOs\PageMeta;
use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\User;
use App\Models\VenueApplication;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * `/registra-il-tuo-locale` (§11.1).
 *
 * È la porta d'ingresso dei locali, cioè la leva di crescita del catalogo. Il
 * modulo chiede i dati della scheda pubblica (separati dai contatti privati di
 * chi scrive) e lega la richiesta a un account: chi è collegato la manda col
 * suo, chi non lo è ne crea uno, e la richiesta entra in coda quando l'email
 * è confermata (`CreateVenueApplication::queue()`).
 *
 * Prima del modulo, una ricerca: se il locale è già a catalogo si rivendica,
 * invece di crearne un doppione.
 */
final class VenueApplicationController extends Controller
{
    use InteractsWithCity;

    public function create(Request $request, SubmitVenueApplication $submit): View
    {
        $city = $this->city();
        StoreVenueApplicationRequest::restoreDraft($request, 'venue');

        $query = trim((string) $request->query('cerca', ''));

        return view('venues.apply', [
            'city' => $city,
            'mode' => 'venue',
            'types' => VenueType::options(),
            'search' => $query,
            'results' => mb_strlen($query) >= 3 ? $submit->catalogue($query) : null,
            ...self::accountState($request->user()),
            'meta' => new PageMeta(
                title: __('forms.application.title'),
                heading: __('forms.application.title'),
                description: __('forms.application.lead', ['city' => $city->name]),
                canonical: route('venue-applications.create'),
            ),
        ]);
    }

    public function store(StoreVenueApplicationRequest $request, CreateVenueApplication $action, SubmitVenueApplication $submit, RequestRegistration $registration): RedirectResponse
    {
        $user = $request->user();
        $email = $request->applicantEmail();
        $returnTo = route('venue-applications.create');

        if ($action->limitReached($email, $user instanceof User ? $user : null)) {
            /* A chi è collegato si dice; a un ospite no: la risposta resta la
               stessa di sempre, così il modulo non racconta niente di un
               indirizzo. */
            return $user instanceof User
                ? back()->withInput()->withErrors(['form' => __('forms.application.limit_reached')])
                : self::sent($returnTo, 'check_email');
        }

        $account = $user instanceof User ? $user : $request->guestAccount($registration, $returnTo);
        $confirmed = $user instanceof User && $user->hasVerifiedEmail();
        $image = $request->file('image');

        $submit->venue($request->validated(), $email, (int) $account->getKey(), $confirmed, $image instanceof UploadedFile ? $image : null);

        return self::sent($returnTo, $confirmed ? 'queued' : ($user instanceof User ? 'verify' : 'check_email'));
    }

    /** «Ho già un account»: quanto scritto resta, si accede e si torna qui. */
    public function draft(Request $request): RedirectResponse
    {
        StoreVenueApplicationRequest::saveDraft($request, 'venue');

        return self::toLogin($request, route('venue-applications.create'));
    }

    /**
     * Una richiesta scritta da ospite con l'indirizzo di un account che
     * esisteva già: la mette in coda il titolare, dopo essere entrato.
     */
    public function confirm(Request $request, SubmitVenueApplication $action, int $application): RedirectResponse
    {
        $richiesta = $action->awaiting($request->user(), $application);
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            return back()->withErrors(['form' => __('forms.application.verify_first')]);
        }

        $action->confirm($richiesta);

        return back()->with('status', __('forms.application.confirmed'));
    }

    public function discard(Request $request, SubmitVenueApplication $action, int $application): RedirectResponse
    {
        $action->discard($action->awaiting($request->user(), $application));

        return back()->with('status', __('forms.application.discarded'));
    }

    /**
     * Le richieste di chi è collegato: quelle da confermare e quelle già in
     * valutazione, mostrate in cima ai moduli.
     *
     * @return array{awaiting: Collection<int, VenueApplication>, inReview: Collection<int, VenueApplication>}
     */
    public static function accountState(mixed $user): array
    {
        if (! $user instanceof User) {
            return ['awaiting' => new Collection, 'inReview' => new Collection];
        }

        $mine = VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->where('user_id', $user->getKey())
            ->where('status', ApplicationStatus::Pending)
            ->latest('id')
            ->limit(10)
            ->get();

        return [
            'awaiting' => $mine->filter(static fn (VenueApplication $a): bool => $a->isAwaitingEmail())->values(),
            'inReview' => $mine->reject(static fn (VenueApplication $a): bool => $a->isAwaitingEmail())->values(),
        ];
    }

    public static function sent(string $returnTo, string $state): RedirectResponse
    {
        return redirect($returnTo)
            ->with('status', __('forms.application.sent.'.$state))
            ->with('application_sent', $state);
    }

    public static function toLogin(Request $request, string $returnTo): RedirectResponse
    {
        $request->session()->put('url.intended', $returnTo);

        return redirect()->route('login', ['intended' => $returnTo]);
    }
}
