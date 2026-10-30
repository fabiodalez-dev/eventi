<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\ClaimMailbox;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\AuthEntryRequest;
use App\Http\Requests\Web\Account\MagicLinkRequest;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accesso senza password (§15.2), che il piano raccomanda come opzione
 * primaria: per un uso saltuario come questo, una password da ricordare è
 * attrito puro e l'attrito si paga in account che non nascono.
 *
 * Quattro difese, e servono tutte e quattro:
 *
 * - il collegamento è **firmato** e scade in quindici minuti;
 * - porta un'impronta della password in vigore, così cambiarla invalida i
 *   collegamenti già spediti;
 * - la risposta al modulo è sempre la stessa, esista o no quell'indirizzo;
 * - entra con un clic solo nel browser che l'ha chiesto: altrove chiede di
 *   confermare su quale account si sta entrando.
 */
final class MagicLinkController extends Controller
{
    public function create(AuthEntryRequest $request): View
    {
        $request->rememberDestination(freshEntry: true);

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

        /*
         * Il segreto del browser che chiede il collegamento: il link ne porta
         * l'impronta, e aperto qui entra senza altri passaggi. Si scrive
         * sempre, esista o no l'account, perché scriverlo solo per gli
         * indirizzi veri direbbe quali sono registrati. Qui resta anche la
         * scelta «resta collegato», che vale per questo browser.
         */
        $segreto = Str::random(40);
        /* Se questo browser ha appena scelto (o dimostrato) la password di
           questo account, il collegamento nuovo non deve dimenticarlo:
           aperto qui lascia valida la password come quello della
           registrazione (`RequestRegistration`). Il marcatore resta solo per
           lo stesso account. */
        $registrazione = $request->session()->get('magic_link.registrazione');
        $request->session()->put('magic_link', [
            'browser' => $segreto,
            'remember' => $request->boolean('remember'),
            'registrazione' => $user instanceof User && $registrazione === $user->getKey() ? $registrazione : null,
        ]);

        $user?->notify(new MagicLoginLink(browser: hash('sha256', $segreto)));

        return redirect()
            ->route('account.magic-link')
            ->with('status', __('account.magic.sent'));
    }

    /**
     * L'apertura del collegamento (GET). Nel browser che l'ha chiesto entra
     * subito; altrove non consuma niente e mostra su quale account si sta per
     * entrare, con un pulsante che manda un POST protetto da CSRF.
     *
     * Così un collegamento valido per il proprio account, girato a un altro o
     * aperto con un rinvio, non lo fa entrare a sua insaputa in un account
     * altrui (login CSRF); e gli scanner di posta, che aprono ogni indirizzo
     * prima della persona, non lo bruciano.
     */
    public function show(Request $request, User $user, ClaimMailbox $claim): View|RedirectResponse
    {
        $fingerprint = $request->query('fingerprint');

        if (! is_string($fingerprint) || ! hash_equals(MagicLoginLink::fingerprint($user), $fingerprint)
            || DB::table('used_web_login_links')->where('hash', hash('sha256', $request->fullUrl()))->exists()) {
            return redirect()->route('account.magic-link')->with('status', __('account.magic.expired'));
        }

        $segreto = $request->session()->get('magic_link.browser');
        $browser = $request->query('browser');

        if (is_string($segreto) && is_string($browser) && hash_equals(hash('sha256', $segreto), $browser)) {
            return $this->login($request, $user, $claim);
        }

        [$locale, $dominio] = array_pad(explode('@', (string) $user->email, 2), 2, '');

        return view('account.magic-link-confirm', [
            'email' => Str::mask($locale, '*', 1).'@'.$dominio,
            'action' => $request->fullUrl(),
            'meta' => new PageMeta(
                title: __('account.magic.confirm_title'),
                heading: __('account.magic.confirm_title'),
                indexable: false,
            ),
        ]);
    }

    /**
     * L'accesso vero: dal pulsante della pagina di conferma (POST) o
     * direttamente da `show()` nel browser che ha chiesto il collegamento.
     * La firma la verifica il middleware `signed`; qui resta da controllare
     * che la password non sia cambiata nel frattempo.
     */
    public function login(Request $request, User $user, ClaimMailbox $claim): RedirectResponse
    {
        $fingerprint = $request->query('fingerprint');

        if (! is_string($fingerprint) || ! hash_equals(MagicLoginLink::fingerprint($user), $fingerprint)) {
            return redirect()->route('account.magic-link')->with('status', __('account.magic.expired'));
        }

        // Persistent atomic consumption survives cache clearing and simultaneous requests.
        DB::table('used_web_login_links')->where('expires_at', '<', now())->delete();
        $inserted = DB::table('used_web_login_links')->insertOrIgnore([
            'hash' => hash('sha256', $request->fullUrl()),
            'expires_at' => CarbonImmutable::createFromTimestamp((int) $request->query('expires')),
        ]);
        if ($inserted !== 1) {
            return redirect()->route('account.magic-link')->with('status', __('account.magic.expired'));
        }

        /*
         * «Resta collegato» solo se chiesto: dalla casella della pagina di
         * conferma, o da quella del modulo nel browser che ha chiesto il
         * collegamento. Un cookie che dura mesi su un computer condiviso non
         * deve nascere da solo.
         */
        $remember = $request->isMethod('POST')
            ? $request->boolean('remember')
            : (bool) $request->session()->get('magic_link.remember', false);
        $registrante = $request->session()->get('magic_link.registrazione') === $user->getKey();
        $request->session()->forget('magic_link');

        /* Il collegamento prova la casella: se password e token sono nati
           prima di questa prova, chi li ha creati non deve restare dentro. Va
           fatto prima di `Auth::login`, così la sessione nuova sopravvive.
           Se questo browser ha scelto (o dimostrato) la password di questo
           account — la registrazione lo scrive nella sessione, che sta sul
           server — chi entra è la stessa persona, e la password resta. */
        if ($registrante) {
            $claim->confermaRegistrazione($user);
        } else {
            $claim($user);
        }

        Auth::login($user, remember: $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_active_at' => CarbonImmutable::now()])->save();

        /* Chi ha appena confermato la propria registrazione passa
           dall'onboarding della community, come dalla vecchia conferma
           aperta nella stessa sessione; la destinazione ricordata resta. */
        if ($registrante && config('community.enabled')) {
            return redirect()->route('community.whatsapp')->with('status', __('account.verify.done'));
        }

        return redirect()->intended(route('account.feed'))->with('status', __($registrante ? 'account.verify.done' : 'account.login.done'));
    }
}
