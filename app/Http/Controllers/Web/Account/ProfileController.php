<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\DeleteAccount;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Requests\Web\Account\DeleteAccountRequest;
use App\Http\Requests\Web\Account\UpdateProfileRequest;
use App\Services\Account\AccountExport;
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
        return view('account.profile', [
            'user' => $this->accountUser($request),
            'meta' => new PageMeta(
                title: __('account.profile.title'),
                heading: __('account.profile.title'),
                description: __('account.profile.lead'),
                indexable: false,
            ),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $this->accountUser($request);

        $user->fill([
            'name' => $request->string('name')->value() === '' ? null : $request->string('name')->value(),
            'timezone' => (string) $request->validated('timezone'),
            'locale' => (string) $request->validated('locale'),
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
         */
        $user->marketing_opt_in_at = $request->boolean('marketing_opt_in')
            ? ($user->marketing_opt_in_at ?? Carbon::now())
            : null;

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
