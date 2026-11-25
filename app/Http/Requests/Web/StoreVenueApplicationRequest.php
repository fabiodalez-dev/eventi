<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Actions\Account\RequestRegistration;
use App\Models\User;
use App\Models\Venue;
use App\Support\Honeypot;
use App\Support\Turnstile;
use App\Support\VenueApplications\ApplicationRules;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rules\Password;

/**
 * Richiesta di accreditamento di un locale (§11.1, §7.3) e rivendicazione di
 * un locale già a catalogo (`/locali/{venue}/rivendica`).
 *
 * Il referente è un dato obbligatorio: l'accreditamento è un rapporto con una
 * persona, non con un indirizzo email generico, ed è la persona che la
 * redazione richiama per verificare.
 *
 * **Sempre con un account.** Chi è collegato scrive a nome del proprio
 * account, con la sua email (quella scritta nel modulo non conta). Chi non lo
 * è sceglie una password: l'account nasce con la registrazione di sempre
 * (`RequestRegistration`, la stessa risposta per indirizzi nuovi e già
 * registrati) e la richiesta entra in coda solo quando l'email è confermata.
 *
 * **Tempo minimo di compilazione.** Accanto al campo esca c'è
 * `form_started`, l'ora in cui il modulo è stato disegnato, cifrata con la
 * chiave dell'applicazione: un robot che invia il modulo un istante dopo
 * averlo letto, o che lo invia senza averlo letto, viene respinto.
 */
class StoreVenueApplicationRequest extends FormRequest
{
    public const STARTED_FIELD = 'form_started';

    /** Meno di così non è una persona che ha letto il modulo. */
    public const MIN_SECONDS = 3;

    /** Oltre un giorno il modulo si ridisegna con un'ora nuova. */
    public const MAX_SECONDS = 86400;

    /** I campi che si conservano andando ad accedere: mai la password, mai i file. */
    public const DRAFT_FIELDS = ['venue_name', 'type', 'address', 'postal_code', 'municipality', 'website', 'public_email', 'public_phone', 'instagram', 'facebook', 'capacity', 'description', 'contact_name', 'contact_role', 'contact_phone', 'contact_email', 'message'];

    /**
     * Un locale che non si può più rivendicare risponde 404 prima ancora di
     * guardare i campi: la scheda pubblica resta in cache fino a mezz'ora
     * dopo un'approvazione e può ancora mostrare l'invito.
     */
    public function authorize(): bool
    {
        $venue = $this->claimedVenue();
        if ($venue instanceof Venue && ! $venue->isClaimable()) {
            abort(404, (string) __('forms.claim.already_managed'));
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $guest = ! $this->user() instanceof User;
        $claim = $this->claimedVenue() instanceof Venue;

        return [
            ...($claim ? ApplicationRules::claim() : ApplicationRules::venue()),
            ...ApplicationRules::contact(),
            'contact_email' => $guest ? ['required', 'email:filter', 'max:255'] : ['nullable'],
            'password' => $guest ? ['required', 'string', 'confirmed', Password::defaults()] : ['prohibited'],
            self::STARTED_FIELD => self::startedRules(),
            ...Honeypot::rules(),
            ...Turnstile::rules('registrazione-locale'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('forms.application.fields');

        return [
            ...$fields,
            'message' => $this->claimedVenue() instanceof Venue ? (string) __('forms.claim.fields.message') : ($fields['message'] ?? 'message'),
        ];
    }

    /** L'email a cui la richiesta risponde: quella dell'account, se c'è. */
    public function applicantEmail(): string
    {
        $user = $this->user();

        return mb_strtolower(trim($user instanceof User ? $user->email : (string) $this->validated('contact_email')));
    }

    /**
     * I dati pubblici del locale, per `venue_applications.venue_data`.
     *
     * @return array<string, string|int>
     */
    public function venueData(): array
    {
        return ApplicationRules::venueData($this->validated());
    }

    /**
     * L'account di chi scrive da ospite: nuovo, o quello già registrato con
     * quell'indirizzo. La risposta della pagina è la stessa nei due casi, e il
     * messaggio lo vede solo chi legge la casella (D64). Dopo l'ingresso dal
     * collegamento si torna al modulo, dove la richiesta si ritrova.
     */
    public function guestAccount(RequestRegistration $registration, string $returnTo): User
    {
        [$user, $registrante] = $registration->account([
            'name' => (string) $this->validated('contact_name'),
            'email' => $this->applicantEmail(),
            'password' => (string) $this->validated('password'),
        ]);

        $this->session()->put('url.intended', $returnTo);
        $registration->sendFromWeb($user, $registrante, $this->session(), false);

        return $user;
    }

    public static function startedToken(?CarbonInterface $at = null): string
    {
        return Crypt::encryptString((string) ($at ?? now())->getTimestamp());
    }

    /** @return array<int, mixed> */
    public static function startedRules(): array
    {
        return ['required', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                $started = is_string($value) ? Crypt::decryptString($value) : '';
            } catch (DecryptException) {
                $started = '';
            }

            if (! ctype_digit($started)) {
                $fail(__('forms.timing.invalid'));

                return;
            }

            $elapsed = now()->getTimestamp() - (int) $started;
            if ($elapsed < self::MIN_SECONDS) {
                $fail(__('forms.timing.too_fast'));
            } elseif ($elapsed > self::MAX_SECONDS) {
                $fail(__('forms.timing.expired'));
            }
        }];
    }

    /**
     * Conserva in sessione quanto scritto finora, per ritrovarlo dopo
     * l'accesso (`$scope` distingue la registrazione dalla rivendicazione).
     */
    public static function saveDraft(Request $request, string $scope): void
    {
        $draft = [];
        foreach (self::DRAFT_FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $draft[$field] = mb_substr($value, 0, 5000);
            }
        }

        $request->session()->put('venue_application_draft.'.$scope, $draft);
    }

    /**
     * Rimette nel modulo la bozza conservata, una volta sola, se il modulo non
     * ha già i dati di un invio respinto.
     */
    public static function restoreDraft(Request $request, string $scope): void
    {
        $draft = $request->session()->pull('venue_application_draft.'.$scope);

        if (is_array($draft) && $draft !== [] && ! $request->session()->has('_old_input')) {
            $request->session()->now('_old_input', $draft);
        }
    }

    private function claimedVenue(): ?Venue
    {
        $venue = $this->route('venue');

        return $venue instanceof Venue ? $venue : null;
    }
}
