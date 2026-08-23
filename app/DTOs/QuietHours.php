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
 * Chi non le dichiara non ne ha. Non esiste un valore predefinito: un silenzio
 * imposto d'ufficio sposterebbe invii che nessuno ha chiesto di spostare.
 */
final readonly class QuietHours
{
    private function __construct(
        private int $fromSeconds,
        private int $toSeconds,
    ) {}

    /**
     * `null` quando la persona non ha dichiarato alcuna finestra, o quando i
     * due estremi coincidono: un silenzio lungo zero secondi non è silenzio.
     */
    public static function fromUser(User $user): ?self
    {
        if (! config()->boolean('notifications.quiet_hours.enabled')) {
            return null;
        }

        $stored = $user->quiet_hours;

        if (! is_array($stored)) {
            return null;
        }

        $from = self::seconds($stored['from'] ?? null);
        $to = self::seconds($stored['to'] ?? null);

        if ($from === null || $to === null || $from === $to) {
            return null;
        }

        return new self($from, $to);
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

    private static function seconds(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time) !== 1) {
            return null;
        }

        $parts = array_map(intval(...), explode(':', $time));

        return $parts[0] * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }
}
