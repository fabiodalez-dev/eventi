<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\RegisterUser;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\RegisterRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Registrazione dal sito (§15.2).
 *
 * **La leva per registrarsi è la notifica, non il salvataggio** (§15.1): la
 * pagina lo dice, e non promette di custodire i salvataggi come se senza
 * account andassero persi — da anonimi restano nel browser e funzionano.
 *
 * Chi arriva qui dal riquadro del terzo salvataggio porta con sé le date nel
 * `localStorage`: la migrazione la chiede il browser subito dopo l'accesso
 * (`POST /salvataggi/unisci`), non questo modulo, così vale anche per chi
 * l'account ce l'aveva già e ha salvato da sloggato.
 */
final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('account.register', [
            'meta' => new PageMeta(
                title: __('account.register.title'),
                heading: __('account.register.title'),
                description: __('account.register.lead'),
                indexable: false,
            ),
        ]);
    }

    public function store(RegisterRequest $request, RegisterUser $register): RedirectResponse
    {
        $user = $register([
            'name' => $request->string('name')->value(),
            'email' => (string) $request->validated('email'),
            'password' => (string) $request->validated('password'),
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()
            ->route('account.feed')
            ->with('status', __('account.register.done'));
    }
}
