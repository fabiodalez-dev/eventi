<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\DeleteAccount;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Requests\Web\Account\DeleteAccountRequest;
use App\Http\Requests\Web\Account\UpdateProfileRequest;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Services\Account\AccountExport;
use App\Services\Carpool\CarpoolAccess;
use App\Support\Features;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Il profilo (§15.2) e i due diritti che gli stanno accanto (§15.9): scaricare
 * i propri dati e cancellare l'account.
 *
 * Profilo minimo: nome facoltativo, email, fuso, lingua. Nessun altro dato è
 * raccolto, quindi nessun altro dato è modificabile.
 */
final class ProfileController extends Controller
{
    use InteractsWithAccount;

    public function edit(Request $request): View
    {
        $user = $this->accountUser($request);

        /* Tutto ciò che la pagina chiede al database lo chiede qui, una volta:
           la vista disegna, non interroga. */
        $publicProfile = config('community.enabled')
            ? $user->communityProfile()->with('media')->first()
            : null;

        $carpool = config('carpool.enabled')
            ? app(CarpoolAccess::class)->state($user)
            : null;

        return view('account.profile', [
            'user' => $user,
            'publicProfile' => $publicProfile,
            'carpool' => $carpool,
            'carpoolStep' => $carpool === null || $carpool['eligible'] ? null : $this->carpoolStep((string) $carpool['reason']),
            'management' => $this->managementLinks($user),
            'meta' => new PageMeta(
                title: __('account.profile.title'),
                heading: __('account.profile.title'),
                description: __('account.profile.lead'),
                indexable: false,
            ),
        ]);
    }

    /**
     * Le aree di lavoro raggiungibili da questo account: redazione, locali,
     * organizzatori, biglietteria. Solo quelle a cui la persona ha accesso.
     *
     * @return list<array{href: string, label: string, description: string, icon: string}>
     */
    private function managementLinks(User $user): array
    {
        $links = [];

        if ($user->isEditorialStaff()) {
            $links[] = ['href' => url('/admin'), 'label' => __('account.nav.admin'), 'description' => __('account.profile.manage_admin'), 'icon' => 'star'];
        }

        /* Stessa domanda di `$user->venues()->exists()`, fatta sul locale e non
           sulla relazione: il menu dell'account in testata la fa già così, e
           due letture della stessa relazione sono un N+1 per il rilevatore. */
        if (Venue::query()->join('venue_user', 'venue_user.venue_id', '=', 'venues.id')->where('venue_user.user_id', $user->getKey())->whereNotNull('venue_user.accepted_at')->exists()) {
            $links[] = ['href' => url('/gestione'), 'label' => __('account.nav.venue'), 'description' => __('account.profile.manage_venue'), 'icon' => 'map-pin'];
        }

        if ($user->managedOrganizers()->exists()) {
            $links[] = ['href' => url('/organizza'), 'label' => __('account.profile.manage_organizers'), 'description' => __('account.profile.manage_organizers_hint'), 'icon' => 'users'];
        }

        $ticketing = $user->ownedVenues()->exists()
            || $user->hasAnyRole(['admin', 'super_admin'])
            || EventOccurrence::query()->whereHas('checkinStaff', fn ($query) => $query->whereKey($user->getKey()))->exists();

        if ($ticketing) {
            $links[] = ['href' => route('ticketing.manage.index'), 'label' => __('ticketing.manage'), 'description' => __('account.profile.manage_ticketing'), 'icon' => 'ticket'];
        }

        return $links;
    }

    /** Il passo che manca per poter usare i passaggi. */
    private function carpoolStep(string $reason): string
    {
        return match ($reason) {
            'email' => route('verification.notice'),
            'whatsapp' => config('community.enabled') ? route('community.whatsapp') : route('carpool.requirements'),
            'suspended' => route('carpool.cases'),
            default => route('carpool.requirements'),
        };
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $this->accountUser($request);

        $user->fill([
            'name' => $request->string('name')->value() === '' ? null : $request->string('name')->value(),
            'timezone' => (string) $request->validated('timezone'),
            'locale' => (string) $request->validated('locale'),
        ]);

        if (! $request->boolean('profile_only')) {
            $user->fill([
                'notification_preferences' => $request->preferences()->toArray(),
                'daily_digest_time' => $request->string('daily_digest_time')->value() === ''
                    ? null
                    : $request->string('daily_digest_time')->value(),
                'quiet_hours' => $request->quietHours(),
            ]);
            /*
             * Il consenso marketing conserva la propria data (§15.9): riconfermarlo
             * non la riscrive, toglierlo la cancella. È quella data la prova, non
             * un booleano.
             *
             * Con la newsletter spenta la casella non è nel modulo, e la sua
             * assenza **non significa revoca**: il consenso già dato resta com'è.
             * Interpretarla come un «no» cancellerebbe di nascosto una scelta della
             * persona al primo salvataggio di un campo qualsiasi.
             */
            if (Features::newsletterActive()) {
                $user->marketing_opt_in_at = $request->boolean('marketing_opt_in')
                    ? ($user->marketing_opt_in_at ?? Carbon::now())
                    : null;
            }
        }

        $user->save();

        return redirect()->route('account.profile')->with('status', __('account.profile.saved'));
    }

    /**
     * Portabilità (§15.9). Il file si scarica dal browser: chi vuole i propri
     * dati non deve installare un client HTTP per averli.
     */
    public function export(Request $request, AccountExport $export): JsonResponse
    {
        return response()
            ->json($export($this->accountUser($request)), options: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.config()->string('app.name').'-dati.json"',
            ]);
    }

    public function destroy(DeleteAccountRequest $request, DeleteAccount $delete): RedirectResponse
    {
        $user = $this->accountUser($request);

        $delete($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('account.profile.deleted'));
    }
}
