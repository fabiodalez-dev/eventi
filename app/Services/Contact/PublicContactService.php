<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Enums\ContactMode;
use App\Mail\PublicContact;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PublicContactService
{
    /**
     * Messaggi al giorno verso uno stesso recapito, chiunque li mandi. Il
     * recapito lo sceglie chi gestisce la scheda e non e' verificato: il tetto
     * impedisce che il modulo diventi un modo di riempire una casella di terzi
     * con la posta del sito, anche ruotando account e indirizzi.
     */
    private const TARGET_DAILY_LIMIT = 30;

    /**
     * Messaggi al giorno di uno stesso mittente (account, o indirizzo IP per
     * gli ospiti) verso uno stesso recapito. Si conta prima del tetto
     * comune: un solo mittente non puo' consumare da solo i messaggi di
     * tutti e zittire il modulo di una scheda.
     */
    private const SENDER_DAILY_LIMIT = 3;

    /** @return array<string, mixed> */
    public function settings(Venue|Organizer $target): array
    {
        $enabled = $target->contact_mode !== ContactMode::Disabled && filled($target->contact_email);

        return ['enabled' => $enabled, 'guests' => $enabled && $target->contact_mode === ContactMode::Everyone && $this->captchaReady(),
            'url' => route($target instanceof Venue ? 'venues.show' : 'organizers.show', $target).'#contatta'];
    }

    public function captchaReady(): bool
    {
        return filled(config('contact.recaptcha_site_key')) && filled(config('contact.recaptcha_secret_key'));
    }

    /** @param array<string, mixed> $data */
    public function send(Venue|Organizer $target, ?User $user, array $data): void
    {
        abort_unless($target->contact_mode !== ContactMode::Disabled && filled($target->contact_email), 404);
        /* Mittente e Reply-To sono quelli dell'account e il captcha si salta:
           solo con un indirizzo confermato (il controller lo dice per bene). */
        abort_if($user !== null && ! $user->hasVerifiedEmail(), 403);
        if ($user === null) {
            abort_unless($target->contact_mode === ContactMode::Everyone && $this->captchaReady(), 403);
            try {
                $result = Http::asForm()->connectTimeout(2)->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', ['secret' => config('contact.recaptcha_secret_key'), 'response' => $data['g-recaptcha-response'] ?? ''])->json();
            } catch (Throwable) {
                $result = [];
            }
            if (($result['success'] ?? false) !== true || ($result['hostname'] ?? '') !== config('contact.recaptcha_hostname')) {
                throw ValidationException::withMessages(['g-recaptcha-response' => __('contact.captcha')]);
            }
        }
        $recapito = sha1(mb_strtolower(trim((string) $target->contact_email)));
        $mittente = 'public-contact-sender:'.$recapito.':'.($user !== null ? 'u'.$user->getKey() : 'ip'.request()->ip());
        $tetto = 'public-contact-target:'.$recapito;
        /* Un errore sul campo e non un 429 nudo: sul sito il testo scritto
           torna nel modulo, nell'app arriva un messaggio che dice per quanto. */
        if (RateLimiter::tooManyAttempts($mittente, self::SENDER_DAILY_LIMIT)) {
            throw ValidationException::withMessages(['message' => __('contact.sender_daily_limit')]);
        }
        if (RateLimiter::tooManyAttempts($tetto, self::TARGET_DAILY_LIMIT)) {
            throw ValidationException::withMessages(['message' => __('contact.target_daily_limit')]);
        }
        RateLimiter::hit($mittente, 86400);
        RateLimiter::hit($tetto, 86400);
        Mail::to($target->contact_email)->send(new PublicContact($target->name, $user->name ?? (string) $data['name'], $user->email ?? (string) $data['email'], (string) $data['message']));
    }
}
