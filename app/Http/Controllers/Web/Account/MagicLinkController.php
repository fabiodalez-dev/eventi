<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\MagicLinkRequest;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Accesso senza password (§15.2), che il piano raccomanda come opzione
 * primaria: per un uso saltuario come questo, una password da ricordare è
 * attrito puro e l'attrito si paga in account che non nascono.
 *
 * Tre difese, e servono tutte e tre:
 *
 * - il collegamento è **firmato** e scade in quindici minuti;
 * - porta un'impronta della password in vigore, così cambiarla invalida i
 *   collegamenti già spediti;
 * - la risposta al modulo è sempre la stessa, esista o no quell'indirizzo.
 */
final class MagicLinkController extends Controller
{
    public function create(): View
    {
        return view('account.magic-link', [
            'meta' => new PageMeta(
                title: __('account.magic.title'),
                heading: __('account.magic.title'),
                description: __('account.magic.lead', ['minutes' => config()->integer('account.magic_link_minutes')]),
                indexable: false,
            ),
        ]);
    }

    public function store(MagicLinkRequest $request): RedirectResponse
    {
        $user = User::query()->where('email', (string) $request->validated('email'))->first();

        $user?->notify(new MagicLoginLink);

        return redirect()
            ->route('account.magic-link')
            ->with('status', __('account.magic.sent'));
    }

    /**
     * La firma la verifica il middleware `signed`; qui resta da controllare
     * che la password non sia cambiata nel frattempo.
     */
    public function login(Request $request, User $user): RedirectResponse
    {
        $fingerprint = $request->query('fingerprint');

        if (! is_string($fingerprint) || ! hash_equals(MagicLoginLink::fingerprint($user), $fingerprint)) {
            return redirect()->route('account.magic-link')->with('status', __('account.magic.expired'));
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $user->forceFill(['last_active_at' => CarbonImmutable::now()])->save();

        return redirect()->route('account.feed')->with('status', __('account.login.done'));
    }
}
