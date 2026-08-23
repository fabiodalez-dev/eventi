<?php

declare(strict_types=1);

namespace App\Enums;

use App\DTOs\NotificationPreferences;
use App\Models\User;

/**
 * Le tipologie di notifica di §15.4, con dentro le tre regole che ne governano
 * il volume — perché sono regole del tipo, non del momento dell'invio.
 *
 * - `isMandatory()`  → annullamenti e spostamenti: attivi e **non
 *   disattivabili**. Non hanno interruttore, ignorano le ore di silenzio e non
 *   consumano il tetto giornaliero: chi ha salvato una serata che non esiste
 *   più deve saperlo, anche se ha spento tutto il resto (§18, scenario J).
 * - `respectsQuietHours()` → tutti gli altri. Un invio che cade nel silenzio
 *   si sposta all'uscita; se nel frattempo è diventato inutile, `skipped`.
 * - `countsTowardDailyCap()` → i soli tipi intrusivi. I promemoria di ciò che
 *   la persona ha salvato **non** contano: sono richiesti, non intrusivi
 *   (§15.4).
 *
 * Il valore è quello scritto in `scheduled_notifications.type` e nella colonna
 * `type` di `notification_log`; è anche il prefisso della `dedupe_key`.
 */
enum NotificationType: string
{
    case EventReminder = 'event_reminder';
    case EventCancelled = 'event_cancelled';
    case EventMoved = 'event_moved';
    case EventSoldOut = 'event_sold_out';
    case VenueDigest = 'venue_digest';
    case DailyDigest = 'daily_digest';
    case WeekendNewsletter = 'weekend_newsletter';
    case EventPublished = 'event_published';
    case EventRejected = 'event_rejected';
    case VenueInactive = 'venue_inactive';

    public function label(): string
    {
        return __('enums.notification_type.'.$this->value);
    }

    /**
     * §15.4: «evento annullato o spostato — attivo, non disattivabile».
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::EventCancelled, self::EventMoved => true,
            default => false,
        };
    }

    /**
     * §15.4: «quiet hours rispettate su tutti i canali tranne annullamenti».
     */
    public function respectsQuietHours(): bool
    {
        return ! $this->isMandatory();
    }

    /**
     * Il tetto di due al giorno riguarda ciò che la piattaforma manda di
     * propria iniziativa. Un promemoria per una data messa in agenda a mano è
     * l'opposto: è stato chiesto.
     */
    public function countsTowardDailyCap(): bool
    {
        return match ($this) {
            self::VenueDigest, self::DailyDigest, self::WeekendNewsletter, self::EventSoldOut => true,
            default => false,
        };
    }

    /**
     * Le comunicazioni di marketing sono giuridicamente distinte da quelle
     * transazionali (§15.9): il consenso è separato, tracciato con la sua data
     * in `users.marketing_opt_in_at`, e la disiscrizione lo cancella.
     */
    public function isMarketing(): bool
    {
        return $this === self::WeekendNewsletter;
    }

    /**
     * Le notifiche che arrivano a chi gestisce un locale (§15.4, ultima riga)
     * non passano dalle preferenze di chi consulta il sito: chi ha proposto un
     * evento deve sapere se è stato pubblicato o rifiutato.
     */
    public function isForVenueStaff(): bool
    {
        return match ($this) {
            self::EventPublished, self::EventRejected, self::VenueInactive => true,
            default => false,
        };
    }

    /**
     * Vero se questa persona ha acceso questa tipologia. Le tipologie
     * obbligatorie rispondono sempre vero: non hanno un interruttore, e un
     * interruttore che il server ignora è peggio di nessun interruttore.
     */
    public function isEnabledFor(User $user): bool
    {
        if ($this->isMandatory() || $this->isForVenueStaff()) {
            return true;
        }

        if ($this->isMarketing()) {
            return $user->marketing_opt_in_at !== null;
        }

        $preferences = $user->notificationPreferences();

        return match ($this) {
            self::EventReminder => $preferences->reminders,
            self::EventSoldOut => $preferences->soldOut,
            self::VenueDigest => $preferences->venueDigest,
            self::DailyDigest => $preferences->dailyDigest,
            default => true,
        };
    }

    /**
     * Spegne questa tipologia per questa persona: è ciò che fa il collegamento
     * di disiscrizione a un click di §15.9. Le tipologie obbligatorie non si
     * spengono, e la funzione lo dice invece di fingere.
     */
    public function disableFor(User $user): bool
    {
        if ($this->isMandatory() || $this->isForVenueStaff()) {
            return false;
        }

        if ($this->isMarketing()) {
            $user->marketing_opt_in_at = null;
            $user->save();

            return true;
        }

        $key = match ($this) {
            self::EventReminder => 'reminders',
            self::EventSoldOut => 'sold_out',
            self::VenueDigest => 'venue_digest',
            self::DailyDigest => 'daily_digest',
            default => null,
        };

        if ($key === null) {
            return false;
        }

        $user->notification_preferences = NotificationPreferences::fromUser($user)
            ->with([$key => false])
            ->toArray();
        $user->save();

        return true;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * I tipi che consumano il tetto giornaliero, nella forma che serve a una
     * clausola `IN`.
     *
     * @return list<string>
     */
    public static function cappedValues(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->countsTowardDailyCap()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }
}
