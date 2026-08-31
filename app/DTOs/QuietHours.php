<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Le ore di silenzio di una persona (§7.10: `quiet_hours` = `{from, to}`).
 *
 * Sono espresse nell'**ora locale di chi riceve**, non in quella del server né
 * in quella della città: un utente che dorme dalle 23:30 alle 8:00 lo fa nel
 * proprio fuso, e il fuso è un campo del profilo (§15.2).
 *
 * Una finestra che chiude prima di aprire attraversa la mezzanotte — è la
 * stessa convenzione degli orari dei locali (D19), e non è un caso limite ma
 * il caso normale: quasi tutte le ore di silenzio la attraversano.
 *
 * Chi non ha ancora scelto riceve la finestra predefinita di
 * `config/notifications.php` — 23:00-08:00 (D36). Non è un silenzio imposto:
 * è ciò che serve perché l'omissione non si traduca in un promemoria alle tre
 * di notte, e si toglie in un clic.
 *
 * Tre stati, non due, ed è la parte che conta:
 *
 * | `users.quiet_hours` | significato          | risultato            |
 * |---------------------|----------------------|----------------------|
 * | `null`              | non ho ancora scelto | finestra predefinita |
 * | `['from','to']`     | le mie ore           | quelle               |
 * | `[]`                | ho scelto di no      | nessun silenzio      |
 *
 * Senza il terzo stato il valore predefinito sarebbe impossibile da spegnere:
 * svuotare i due campi tornerebbe a `null`, cioè di nuovo al predefinito.
 */
final readonly class QuietHours
{
    private function __construct(
        private int $fromSeconds,
        private int $toSeconds,
    ) {}

    /**
     * La finestra che vale per questa persona: la sua se l'ha espressa, quella
     * predefinita se non ha ancora scelto, `null` se ha scelto di non averne.
     *
     * Un array — anche vuoto — è una scelta e vale come tale. Solo l'assenza
     * di un array fa scattare il valore predefinito.
     */
    public static function fromUser(User $user): ?self
    {
        if (! config()->boolean('notifications.quiet_hours.enabled')) {
            return null;
        }

        $stored = $user->quiet_hours;

        if (is_array($stored)) {
            return self::fromWindow($stored['from'] ?? null, $stored['to'] ?? null);
        }

        return self::default();
    }

    /**
     * La finestra di chi non ha scelto. `null` quando la configurazione non ne
     * dichiara una: è l'interruttore per tornare al comportamento precedente,
     * in cui il silenzio esisteva solo se richiesto.
     */
    public static function default(): ?self
    {
        $window = config('notifications.quiet_hours.default');

        if (! is_array($window)) {
            return null;
        }

        return self::fromWindow($window['from'] ?? null, $window['to'] ?? null);
    }

    /**
     * `null` quando uno dei due estremi non è un orario, o quando coincidono:
     * un silenzio lungo zero secondi non è silenzio.
     */
    private static function fromWindow(mixed $from, mixed $to): ?self
    {
        $start = self::seconds($from);
        $end = self::seconds($to);

        if ($start === null || $end === null || $start === $end) {
            return null;
        }

        return new self($start, $end);
    }

    /**
     * Come si presenta la finestra dentro un modulo: i due orari da mostrare e
     * se l'interruttore «nessun silenzio» è alzato.
     *
     * Quando è alzato i due campi restano compilati con la finestra
     * predefinita: togliere la spunta deve rimettere qualcosa di sensato, non
     * lasciare due caselle vuote.
     *
     * @return array{from: string, to: string, off: bool}
     */
    public static function formFor(User $user): array
    {
        $chosen = is_array($user->quiet_hours);

        $own = $chosen
            ? self::fromWindow($user->quiet_hours['from'] ?? null, $user->quiet_hours['to'] ?? null)
            : null;

        $shown = $own ?? self::default();

        return [
            'from' => $shown?->from() ?? '',
            'to' => $shown?->to() ?? '',
            'off' => $chosen && ! $own instanceof self,
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from(), 'to' => $this->to()];
    }

    public function from(): string
    {
        return self::format($this->fromSeconds);
    }

    public function to(): string
    {
        return self::format($this->toSeconds);
    }

    /**
     * Vero se l'istante — già espresso nell'ora locale di chi riceve — cade
     * dentro la finestra.
     */
    public function contains(CarbonImmutable $local): bool
    {
        $seconds = $local->hour * 3600 + $local->minute * 60 + $local->second;

        return $this->fromSeconds < $this->toSeconds
            ? $seconds >= $this->fromSeconds && $seconds < $this->toSeconds
            : $seconds >= $this->fromSeconds || $seconds < $this->toSeconds;
    }

    /**
     * Il primo istante utile dopo la fine del silenzio.
     *
     * Si costruisce con `setTime()` e non sommando secondi alla mezzanotte:
     * nel giorno in cui scatta l'ora legale una giornata non dura ventiquattro
     * ore, e un promemoria uscirebbe con un'ora di scarto proprio nel giorno
     * in cui nessuno se ne accorgerebbe.
     */
    public function endsAfter(CarbonImmutable $local): CarbonImmutable
    {
        $hour = intdiv($this->toSeconds, 3600);
        $minute = intdiv($this->toSeconds % 3600, 60);

        $end = $local->setTime($hour, $minute);

        return $end->greaterThan($local) ? $end : $local->addDay()->setTime($hour, $minute);
    }

    private static function format(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private static function seconds(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time) !== 1) {
            return null;
        }

        $parts = array_map(intval(...), explode(':', $time));

        return $parts[0] * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }
}
