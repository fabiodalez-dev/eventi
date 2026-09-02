<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\ForgotPasswordRequest;
use App\Http\Requests\Web\Account\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Reimpostare la password dal sito (§15.2).
 *
 * **Perche' ora.** `ResetPasswordLink` costruisce da sempre un collegamento
 * verso `/reimposta-password` quando `API_PASSWORD_RESET_URL` non e'
 * impostato — e in produzione non lo e'. Quella pagina pero' non esisteva:
 * chi chiedeva una nuova password riceveva un'email con dentro un 404. Il
 * commento di quella notifica diceva «il giorno in cui esistera' anche una
 * pagina web»: e' oggi.
 *
 * **La risposta e' sempre la stessa, che l'indirizzo esista o no.** Dire
 * «questa email non risulta» e' il modo piu' economico per farsi costruire da
 * chiunque l'elenco degli iscritti. Vale qui come nell'API (§13.4).
 *
 * L'accesso senza password resta la via raccomandata dal piano e la pagina lo
 * ricorda: per un uso saltuario come questo, una password da ricordare e'
 * attrito che si paga in account che non nascono. Questa pagina serve a chi
 * una password l'ha scelta e non se la ricorda piu'.
 */
final class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('account.forgot-password', [
            'meta' => new PageMeta(
                title: __('account.forgot.title'),
                heading: __('account.forgot.title'),
                description: __('account.forgot.lead'),
                indexable: false,
            ),
        ]);
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        Password::broker()->sendResetLink(
            ['email' => (string) $request->validated('email')],
            static fn (User $user, string $token) => $user->notify(new ResetPasswordLink($token)),
        );

        return redirect()
            ->route('account.password.request')
            ->with('status', __('account.forgot.sent'));
    }

    /**
     * Il modulo della nuova password.
     *
     * Token e indirizzo arrivano dalla query string del collegamento: sono i
     * due dati che servono a `Password::broker()->reset()`, e richiederli a
     * chi ha appena cliccato significherebbe fargli ricopiare a mano un token
     * di sessantaquattro caratteri.
     *
     * Qui non si verifica che il token sia valido: lo fa il broker all'invio.
     * Un controllo anticipato direbbe a chi tira a indovinare quali token
     * esistono, e non risparmierebbe niente a nessun altro.
     */
    public function edit(Request $request): View
    {
        return view('account.reset-password', [
            'token' => (string) $request->query('token', ''),
            'email' => (string) $request->query('email', ''),
            'meta' => new PageMeta(
                title: __('account.reset.title'),
                heading: __('account.reset.title'),
                description: __('account.reset.lead'),
                indexable: false,
            ),
        ]);
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => (string) $request->validated('email'),
                'password' => (string) $request->validated('password'),
                'token' => (string) $request->validated('token'),
            ],
            static function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                /*
                 * Cambiare la password butta fuori i dispositivi, come
                 * nell'API: chi reimposta perche' teme un accesso altrui non
                 * ottiene niente se i token vecchi restano vivi.
                 */
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * Token scaduto, gia' usato, o indirizzo che non corrisponde. Il
             * messaggio e' unico e non distingue: sapere QUALE dei tre e'
             * andato storto serve solo a chi sta provando.
             */
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('account.reset.failed')]);
        }

        return redirect()
            ->route('login')
            ->with('status', __('account.reset.done'));
    }
}
