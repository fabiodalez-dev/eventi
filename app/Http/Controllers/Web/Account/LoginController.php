<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\LoginRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Accesso con password (§15.2).
 *
 * Il magic link resta la via raccomandata e sta accanto, non al posto: chi una
 * password l'ha scelta deve poterla usare.
 */
final class LoginController extends Controller
{
    public function create(): View
    {
        return view('account.login', [
            'meta' => new PageMeta(
                title: __('account.login.title'),
                heading: __('account.login.title'),
                description: __('account.login.lead'),
                indexable: false,
            ),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = [
            'email' => (string) $request->validated('email'),
            'password' => (string) $request->validated('password'),
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            /*
             * Un solo messaggio per «email inesistente» e «password
             * sbagliata»: due messaggi diversi direbbero a chiunque quali
             * indirizzi sono registrati.
             */
            throw ValidationException::withMessages(['email' => __('account.login.failed')]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        $user?->forceFill(['last_active_at' => CarbonImmutable::now()])->save();

        return redirect()
            ->intended(route('account.feed'))
            ->with('status', __('account.login.done'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('account.login.logged_out'));
    }
}
