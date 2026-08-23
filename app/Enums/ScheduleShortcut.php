<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Le quattro scorciatoie del secondo passo del wizard: *Stasera · Domani ·
 * Venerdì · Ogni giovedì* (§10.2).
 *
 * Sono l'unico modo per stare dentro ai 90 secondi di §2.4: quattro tocchi
 * possibili al posto di un calendario da aprire, scorrere e chiudere.
 *
 * **Che cosa non sono.** Non sono finestre temporali del cartellone: non
 * rispondono alla domanda «che cosa c'è stasera», che resta di
 * `EventOccurrenceQuery` e di nessun altro (§3 delle convenzioni). Qui si
 * propone soltanto un valore per un campo data che il gestore vede e può
 * correggere prima di salvare.
 *
 * "Adesso" resta comunque quello della città (§8.1): il fuso arriva da chi
 * chiama, mai da `now()` del server.
 */
enum ScheduleShortcut: string
{
    case Tonight = 'tonight';
    case Tomorrow = 'tomorrow';
    case Friday = 'friday';
    case EveryThursday = 'every_thursday';

    /**
     * L'istante proposto: il giorno della scorciatoia, all'ora abituale del
     * locale.
     */
    public function startsAt(CarbonImmutable $now, int $hour, int $minute): CarbonImmutable
    {
        $day = match ($this) {
            self::Tonight => $now,
            self::Tomorrow => $now->addDay(),
            default => $this->nextWeekday($now),
        };

        return $day->setTime($hour, $minute);
    }

    /**
     * Il giorno della settimana implicato dalla scorciatoia, quando ce n'è uno.
     */
    public function weekday(): ?Weekday
    {
        return match ($this) {
            self::Friday => Weekday::Friday,
            self::EveryThursday => Weekday::Thursday,
            default => null,
        };
    }

    /**
     * Vero per la sola scorciatoia che accende anche la ripetizione: «ogni
     * giovedì» è una serie, non una data.
     */
    public function repeats(): bool
    {
        return $this === self::EveryThursday;
    }

    public function label(): string
    {
        return __('manage.shortcuts.'.$this->value);
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
     * Oggi stesso se oggi è già il giorno giusto — chi tocca "venerdì" di
     * venerdì mattina intende quella sera, non fra sette giorni.
     */
    private function nextWeekday(CarbonImmutable $now): CarbonImmutable
    {
        $target = $this->weekday();

        if (! $target instanceof Weekday) {
            return $now;
        }

        $delta = ($target->isoNumber() - $now->dayOfWeekIso + 7) % 7;

        return $now->addDays($delta);
    }
}
