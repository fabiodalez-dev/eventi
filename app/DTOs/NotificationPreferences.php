<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\NotificationDelivery;
use App\Models\User;

/**
 * Le preferenze di notifica di §15.4, con i loro valori predefiniti.
 *
 * Stanno in un oggetto e non in un array sparso perché la colonna
 * `users.notification_preferences` è un JSON: senza un punto solo che ne
 * conosca le chiavi, ogni lettore ne inventerebbe una variante e un utente
 * registrato prima di un cambio si ritroverebbe con un valore mancante letto
 * come «spento».
 *
 * **Gli annullamenti non compaiono qui.** §15.4 li dichiara «attivi, non
 * disattivabili»: un promemoria per una serata che non esiste più è peggio
 * dell'assenza di promemoria, e una preferenza che non si può cambiare non è
 * una preferenza. Chi invia non deve nemmeno poter chiedere il permesso.
 *
 * Il consenso alla newsletter non sta qui per una ragione diversa e più forte:
 * è giuridicamente distinto (§15.9) e vive in `users.marketing_opt_in_at`, che
 * porta con sé la data in cui è stato dato.
 */
final readonly class NotificationPreferences
{
    /**
     * @param  list<int>  $reminderHours  quante ore prima parte ciascun promemoria
     */
    private function __construct(
        public bool $reminders,
        public array $reminderHours,
        public bool $soldOut,
        public bool $venueDigest,
        public bool $dailyDigest,
        /*
         * La spinta della sera: spenta finché non la si accende. È una
         * notifica che parte di nostra iniziativa e interrompe una persona
         * che non ha chiesto niente — accenderla per difetto sarebbe
         * decidere al posto suo.
         *
         * @var list<int> $tonightDays giorni ISO (1 = lunedì) in cui parte
         */
        public bool $tonight = false,
        /*
         * Il rapporto mensile a chi gestisce un locale: acceso di default,
         * perché è un resoconto su ciò che si è pubblicato, non una proposta.
         * Spegnibile, perché nessuno deve ricevere una mail che non vuole.
         */
        public bool $venueReport = true,
        public array $tonightDays = [5, 6],
        public string $tonightTime = '18:00',
        /*
         * Risposte e reazioni ai propri commenti. Acceso di default: chi
         * scrive una domanda pubblica si aspetta di sapere quando qualcuno
         * risponde, ed è la sola notifica che nasce da un gesto suo.
         */
        public bool $comments = true,
        public NotificationDelivery $delivery = NotificationDelivery::Auto,
    ) {}

    /**
     * I valori predefiniti della tabella di §15.4: promemoria e digest
     * settimanale accesi, digest giornaliero spento.
     */
    public static function defaults(): self
    {
        return new self(
            reminders: true,
            reminderHours: [24, 3],
            soldOut: true,
            venueDigest: true,
            dailyDigest: false,
            tonight: false,
            venueReport: true,
            tonightDays: self::configuredDays(),
            tonightTime: self::configuredTime(),
            comments: true,
        );
    }

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $defaults = self::defaults();

        if ($stored === null) {
            return $defaults;
        }

        return new self(
            reminders: self::boolean($stored, 'reminders', $defaults->reminders),
            reminderHours: self::hours($stored['reminder_hours'] ?? null, $defaults->reminderHours),
            soldOut: self::boolean($stored, 'sold_out', $defaults->soldOut),
            venueDigest: self::boolean($stored, 'venue_digest', $defaults->venueDigest),
            dailyDigest: self::boolean($stored, 'daily_digest', $defaults->dailyDigest),
            tonight: self::boolean($stored, 'tonight', $defaults->tonight),
            venueReport: self::boolean($stored, 'venue_report', $defaults->venueReport),
            tonightDays: self::days($stored['tonight_days'] ?? null, $defaults->tonightDays),
            tonightTime: self::time($stored['tonight_time'] ?? null, $defaults->tonightTime),
            comments: self::boolean($stored, 'comments', $defaults->comments),
            delivery: NotificationDelivery::tryFrom(is_string($stored['delivery'] ?? null) ? $stored['delivery'] : '') ?? $defaults->delivery,
        );
    }

    public static function fromUser(User $user): self
    {
        $stored = $user->notification_preferences;

        return self::fromArray(is_array($stored) ? $stored : null);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(
            reminders: self::boolean($changes, 'reminders', $this->reminders),
            reminderHours: self::hours($changes['reminder_hours'] ?? null, $this->reminderHours),
            soldOut: self::boolean($changes, 'sold_out', $this->soldOut),
            venueDigest: self::boolean($changes, 'venue_digest', $this->venueDigest),
            dailyDigest: self::boolean($changes, 'daily_digest', $this->dailyDigest),
            tonight: self::boolean($changes, 'tonight', $this->tonight),
            venueReport: self::boolean($changes, 'venue_report', $this->venueReport),
            tonightDays: self::days($changes['tonight_days'] ?? null, $this->tonightDays),
            tonightTime: self::time($changes['tonight_time'] ?? null, $this->tonightTime),
            comments: self::boolean($changes, 'comments', $this->comments),
            delivery: NotificationDelivery::tryFrom(is_string($changes['delivery'] ?? null) ? $changes['delivery'] : '') ?? $this->delivery,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reminders' => $this->reminders,
            'reminder_hours' => $this->reminderHours,
            'sold_out' => $this->soldOut,
            'venue_digest' => $this->venueDigest,
            'daily_digest' => $this->dailyDigest,
            'tonight' => $this->tonight,
            'venue_report' => $this->venueReport,
            'tonight_days' => $this->tonightDays,
            'tonight_time' => $this->tonightTime,
            'comments' => $this->comments,
            'delivery' => $this->delivery->value,
        ];
    }

    /**
     * Le chiavi che una richiesta può cambiare. L'elenco vive qui e non nella
     * Form Request: chi aggiunge una preferenza la aggiunge in un posto solo.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return ['reminders', 'reminder_hours', 'sold_out', 'venue_digest', 'daily_digest', 'tonight', 'tonight_days', 'tonight_time', 'venue_report', 'comments', 'delivery'];
    }

    /** @return list<int> */
    private static function configuredDays(): array
    {
        $days = config('notifications.digests.tonight.weekdays', [5, 6]);

        return self::days(is_array($days) ? $days : null, [5, 6]);
    }

    private static function configuredTime(): string
    {
        $time = config('notifications.digests.tonight.time', '18:00');

        return self::time(is_string($time) ? $time : null, '18:00');
    }

    /**
     * I giorni in cui parte la spinta della sera, come numeri ISO ordinati e
     * senza ripetizioni. Un elenco vuoto ricade sul predefinito invece di
     * significare «mai»: per non ricevere niente c'è l'interruttore, e due
     * modi diversi di spegnere la stessa cosa finiscono per contraddirsi.
     *
     * @param  list<int>  $fallback
     * @return list<int>
     */
    private static function days(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $days = [];

        foreach ($value as $day) {
            if (is_numeric($day) && (int) $day >= 1 && (int) $day <= 7) {
                $days[] = (int) $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? $fallback : $days;
    }

    private static function time(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function boolean(array $values, string $key, bool $fallback): bool
    {
        return array_key_exists($key, $values) ? filter_var($values[$key], FILTER_VALIDATE_BOOLEAN) : $fallback;
    }

    /**
     * Ore ordinate dalla più lontana alla più vicina all'inizio, senza
     * ripetizioni: due promemoria alla stessa ora sarebbero due messaggi
     * identici a un minuto di distanza.
     *
     * @param  list<int>  $fallback
     * @return list<int>
     */
    private static function hours(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $hours = [];

        foreach ($value as $hour) {
            if (is_numeric($hour) && (int) $hour > 0) {
                $hours[] = (int) $hour;
            }
        }

        $hours = array_values(array_unique($hours));
        rsort($hours);

        return $hours === [] ? $fallback : $hours;
    }
}
