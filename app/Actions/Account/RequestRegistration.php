<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\MagicLoginLink;
use App\Notifications\RegistrationLink;
use App\Services\Account\MobileMagicLink;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * La registrazione che non rivela se un indirizzo è già registrato (§15.2,
 * D64), una sola per sito e API.
 *
 * Prima la registrazione rispondeva subito con il token per un indirizzo
 * nuovo e con un errore per uno già usato: bastava provare un indirizzo per
 * sapere se era iscritto, e le difese dell'accesso, del collegamento e di
 * «password dimenticata» — che rispondono sempre allo stesso modo — non
 * servivano a niente. Ora la risposta è sempre «ti abbiamo scritto», e la
 * differenza la vede solo chi legge la casella:
 *
 * - **indirizzo nuovo**: l'account nasce come prima (`RegisterUser`, email da
 *   confermare e sospeso aperto) e arriva `RegistrationLink`, il collegamento
 *   che conferma l'indirizzo ed entra;
 * - **indirizzo già registrato**: l'account non cambia e arriva
 *   `AccountAlreadyRegistered`, con il collegamento di accesso ordinario.
 *
 * Un caso sta in mezzo: l'account in sospeso e la password giusta. È chi si è
 * appena registrato e rifà la registrazione perché il messaggio non arriva:
 * gli riparte `RegistrationLink`, legato come il primo a chi conosce la
 * password.
 *
 * Il costo resta simile nei due rami: la creazione calcola un bcrypt
 * (`Hash::make`), il ramo dell'account esistente ne verifica uno
 * (`Hash::check`) con lo stesso costo, ed entrambi spediscono un messaggio.
 */
final class RequestRegistration
{
    /** I messaggi di registrazione per account in un'ora, da tutte le porte. */
    private const MESSAGGI_ORARI = 5;

    public function __construct(
        private readonly RegisterUser $register,
        private readonly MobileMagicLink $mobile,
    ) {}

    /**
     * L'account e se chi chiede ne ha scelto la password: vero per l'account
     * appena nato e per quello ancora in sospeso con la password giusta.
     *
     * @param  array{name?: string|null, first_name?: string|null, last_name?: string|null, email: string, password: string, marketing_opt_in?: bool}  $data
     * @return array{0: User, 1: bool}
     */
    public function account(array $data): array
    {
        /* Anche gli account eliminati: l'indirizzo resta occupato nella
           tabella, e cercarli solo fra i vivi mandava l'inserimento contro
           l'indice unico (un 404 che diceva «eliminato»). */
        $esistente = User::withTrashed()->where('email', $data['email'])->first();

        if (! $esistente instanceof User) {
            try {
                return [($this->register)($data), true];
            } catch (UniqueConstraintViolationException) {
                /* Due registrazioni simultanee dello stesso indirizzo: la
                   seconda trova l'account della prima, come se fosse arrivata
                   dopo. */
                $esistente = User::withTrashed()->where('email', $data['email'])->firstOrFail();
            }
        }

        /* Il controllo si fa anche per l'account eliminato: costa quanto
           la creazione, e il tempo non deve dire in quale ramo si è. */
        $password = Hash::check($data['password'], (string) $esistente->password);

        return [$esistente, $password && $esistente->email_claim_pending_at !== null && ! $esistente->trashed()];
    }

    /**
     * Il messaggio dal sito. Il collegamento porta l'impronta di un segreto
     * messo nella sessione di questo browser: aperto qui entra con un clic,
     * altrove chiede conferma (`MagicLinkController`). Se chi chiede ha
     * scelto la password, la sessione lo ricorda, e l'ingresso da qui la
     * lascia valida.
     *
     * La sessione si scrive solo quando il messaggio parte: oltre il tetto il
     * collegamento già spedito deve restare quello che entra con un clic.
     */
    public function sendFromWeb(User $user, bool $registrante, Session $session, bool $remember): void
    {
        $this->send($user, $registrante, static function () use ($user, $registrante, $session, $remember): string {
            $segreto = Str::random(40);
            $session->put('magic_link', [
                'browser' => $segreto,
                'remember' => $remember,
                'registrazione' => $registrante ? $user->getKey() : null,
            ]);

            return MagicLoginLink::url($user, hash('sha256', $segreto));
        });
    }

    /**
     * Il messaggio dall'app: il collegamento si scambia con il verificatore
     * PKCE dell'installazione che ha chiesto (`MobileMagicLink`). Senza
     * `code_challenge` — una versione dell'app che non lo manda — parte il
     * collegamento del sito, che entra dal browser ma, non essendo legato a
     * nessuno, alla prima apertura azzera la password come ogni prova della
     * casella fatta da chi non si sa chi sia.
     *
     * La sfida nasce solo quando il messaggio parte: `issue()` rende inutili
     * i collegamenti precedenti, e farlo oltre il tetto permetteva a
     * chiunque, con una sfida propria, di invalidare in silenzio quello che
     * il titolare ha nella casella.
     */
    public function sendFromApp(User $user, bool $registrante, ?string $codeChallenge): void
    {
        $this->send($user, $registrante, fn (): string => is_string($codeChallenge) && $codeChallenge !== ''
            ? $this->mobile->issue($user, $codeChallenge, keepsPassword: $registrante)
            : MagicLoginLink::url($user));
    }

    /**
     * Oltre il tetto il messaggio non parte e la risposta non cambia: chi
     * riempie la casella di un altro con registrazioni ripetute non ottiene
     * più niente, e chi aspetta il proprio messaggio ne ha già ricevuti
     * cinque. Il tetto per casella sulle rotte (`limitiPerConto()`) resta
     * davanti a questo. Il collegamento si prepara dentro il tentativo, così
     * oltre il tetto non cambia niente: né sfide né sessione.
     *
     * A un account eliminato non parte niente: la risposta è la stessa, e
     * l'indirizzo di una persona cancellata non riceve messaggi.
     *
     * @param  Closure(): string  $collegamento
     */
    private function send(User $user, bool $registrante, Closure $collegamento): void
    {
        if ($user->trashed()) {
            return;
        }

        RateLimiter::attempt(
            'posta-registrazione:'.$user->getKey(),
            self::MESSAGGI_ORARI,
            static function () use ($user, $registrante, $collegamento): void {
                $url = $collegamento();
                $user->notify($registrante ? new RegistrationLink($url) : new AccountAlreadyRegistered($url));
            },
            3600,
        );
    }
}
