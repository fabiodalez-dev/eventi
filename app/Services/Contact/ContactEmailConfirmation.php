<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Organizer;
use App\Models\Venue;
use App\Notifications\ContactEmailConfirmationRequested;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * La conferma del recapito del modulo contatti (doppio opt-in).
 *
 * Il collegamento è firmato, dura `LINK_DAYS` giorni e porta l'impronta
 * dell'indirizzo a cui è stato spedito: se nel frattempo il recapito cambia,
 * il vecchio collegamento non conferma quello nuovo.
 */
final class ContactEmailConfirmation
{
    public const LINK_DAYS = 7;

    /**
     * Email di conferma al giorno per una stessa scheda, comprese quelle che
     * partono da sole quando si cambia indirizzo: chi gestisce la scheda non
     * può usare il cambio d'indirizzo per spedire posta del sito a chiunque.
     */
    public const TARGET_DAILY_LIMIT = 5;

    /**
     * Email di conferma al giorno verso uno stesso indirizzo, da qualunque
     * scheda: una casella di terzi non si riempie ruotando le schede.
     */
    public const RECIPIENT_DAILY_LIMIT = 3;

    public function url(Venue|Organizer $target): string
    {
        return URL::temporarySignedRoute('contact-email.confirm', now()->addDays(self::LINK_DAYS), [
            'type' => self::type($target),
            'id' => $target->getKey(),
            'email' => self::fingerprint((string) $target->contact_email),
        ]);
    }

    public function canSend(Venue|Organizer $target): bool
    {
        return filled($target->contact_email) && ! $target->hasConfirmedContactEmail()
            && ! RateLimiter::tooManyAttempts($this->targetKey($target), self::TARGET_DAILY_LIMIT)
            && ! RateLimiter::tooManyAttempts($this->recipientKey($target), self::RECIPIENT_DAILY_LIMIT);
    }

    /**
     * Spedisce il collegamento, se i tetti lo consentono. Un errore del
     * server di posta non fa fallire il salvataggio della scheda: il recapito
     * resta in attesa e il pannello offre di riprovare.
     */
    public function send(Venue|Organizer $target): bool
    {
        if (! $this->canSend($target)) {
            return false;
        }

        RateLimiter::hit($this->targetKey($target), 86400);
        RateLimiter::hit($this->recipientKey($target), 86400);

        try {
            Notification::route('mail', (string) $target->contact_email)
                ->notify(new ContactEmailConfirmationRequested((string) $target->name, $this->url($target)));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Conferma il recapito se l'impronta del collegamento è quella
     * dell'indirizzo salvato adesso. La firma e la scadenza le verifica il
     * middleware `signed` sulla rotta.
     */
    public function confirm(Venue|Organizer $target, string $fingerprint): bool
    {
        if (blank($target->contact_email) || ! hash_equals(self::fingerprint((string) $target->contact_email), $fingerprint)) {
            return false;
        }

        if ($target->hasConfirmedContactEmail()) {
            return true;
        }

        /* Si conferma solo se l'indirizzo è ancora quello letto: un salvataggio
           che lo cambia nel frattempo non deve ereditare la conferma. Il
           salvataggio passa poi dal modello, che invalida le pagine in cache. */
        $confermato = $target->newQueryWithoutScopes()->whereKey($target->getKey())
            ->where('contact_email', $target->contact_email)
            ->update(['contact_email_verified_at' => now()]) === 1;
        if ($confermato) {
            $target->refresh()->touch();
        }

        return $confermato;
    }

    public static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', Venue::normalizeContactEmail($email), (string) config('app.key'));
    }

    public static function type(Venue|Organizer $target): string
    {
        return $target instanceof Venue ? 'venues' : 'organizers';
    }

    private function targetKey(Venue|Organizer $target): string
    {
        return 'contact-email-confirm:'.self::type($target).':'.$target->getKey();
    }

    private function recipientKey(Venue|Organizer $target): string
    {
        return 'contact-email-confirm-to:'.sha1(Venue::normalizeContactEmail($target->contact_email));
    }
}
