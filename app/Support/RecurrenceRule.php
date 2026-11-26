<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Exceptions\InvalidRecurrenceRule;
use DateTimeImmutable;
use DateTimeInterface;
use RRule\RRule;
use Throwable;

/**
 * Il traduttore fra la frase del gestore — *ripeti ogni settimana il giovedì* —
 * e la stringa RFC 5545 che `GenerateOccurrencesAction` sa espandere.
 *
 * §10.4 è categorico: «il gestore non deve mai vedere la sintassi RRULE».
 * Questa classe è il solo punto del progetto in cui quella sintassi viene
 * scritta a partire da una scelta umana, e il solo in cui viene riletta per
 * tornare a essere una frase. Tenerla in un posto unico significa che il
 * pannello dei locali, quello di redazione e un domani l'API mostrano la
 * stessa frase per la stessa regola.
 *
 * Non contiene `UNTIL`: la data di fine vive nella colonna
 * `event_recurrences.until`, che è ciò che l'azione di generazione legge
 * davvero. Duplicarla nella stringa vorrebbe dire poterla contraddire.
 */
final class RecurrenceRule
{
    /**
     * Le frequenze che il sito accetta: un evento ripete al più una volta al
     * giorno. `HOURLY`, `MINUTELY` e `SECONDLY` espanderebbero migliaia di
     * date in una richiesta (con `FREQ=MINUTELY` più di mezzo milione in un
     * anno).
     */
    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * Le date al massimo in un anno di serie: «ogni giorno» ne fa 366, una
     * regola giornaliera con più orari (`BYHOUR`) le moltiplica.
     */
    public const MAX_DATES_PER_YEAR = 1000;

    /**
     * La regola letta dalla libreria, con `DTSTART` se la stringa non lo
     * porta. Una regola illeggibile o troppo fitta dà `InvalidRecurrenceRule`
     * con il messaggio per chi l'ha scritta.
     *
     * @return RRule<DateTimeInterface>
     *
     * @throws InvalidRecurrenceRule
     */
    public static function expand(string $rrule, ?DateTimeInterface $start): RRule
    {
        try {
            $rule = str_contains(strtoupper($rrule), 'DTSTART') ? new RRule(trim($rrule)) : new RRule(trim($rrule), $start);
        } catch (Throwable) {
            throw new InvalidRecurrenceRule((string) __('admin.validation.rrule_invalid'));
        }
        $frequency = $rule->getRule()['FREQ'] ?? null;
        if (! in_array(is_string($frequency) ? strtoupper($frequency) : '', self::FREQUENCIES, true)) {
            throw new InvalidRecurrenceRule((string) __('admin.validation.rrule_too_frequent'));
        }

        return $rule;
    }

    /**
     * Che cosa non va nella regola, per il campo del modulo; `null` se va
     * bene. Oltre a `expand`, conta le date del primo anno della serie.
     */
    public static function problem(string $rrule): ?string
    {
        try {
            $rule = self::expand($rrule, new DateTimeImmutable('today'));
            $first = $rule->getOccurrences(1)[0] ?? null;
            if ($first instanceof DateTimeInterface) {
                $from = DateTimeImmutable::createFromInterface($first);
                $dates = $rule->getOccurrencesBetween($from, $from->modify('+1 year'), self::MAX_DATES_PER_YEAR + 1);
                if (count($dates) > self::MAX_DATES_PER_YEAR) {
                    return (string) __('admin.validation.rrule_too_many', ['max' => self::MAX_DATES_PER_YEAR]);
                }
            }
        } catch (InvalidRecurrenceRule $exception) {
            return $exception->getMessage();
        } catch (Throwable) {
            return (string) __('admin.validation.rrule_invalid');
        }

        return null;
    }

    /**
     * @param  array<int, Weekday|string>  $weekdays  vuoto: la serie ripete nel
     *                                                giorno della prima data
     */
    public static function build(RecurrenceFrequency $frequency, array $weekdays = []): string
    {
        $parts = ['FREQ='.$frequency->rruleFrequency()];

        if ($frequency->interval() > 1) {
            $parts[] = 'INTERVAL='.$frequency->interval();
        }

        if ($frequency->acceptsWeekdays()) {
            $codes = self::codes($weekdays);

            if ($codes !== []) {
                $parts[] = 'BYDAY='.implode(',', $codes);
            }
        }

        return implode(';', $parts);
    }

    /**
     * La regola riletta come frequenza e giorni, per riaprire il modulo sulla
     * scelta che il gestore aveva fatto invece che su quella predefinita.
     *
     * @return array{frequency: RecurrenceFrequency, weekdays: array<int, string>}
     */
    public static function parse(string $rrule): array
    {
        $parts = [];

        foreach (explode(';', $rrule) as $chunk) {
            if (! str_contains($chunk, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $chunk, 2);
            $parts[strtoupper(trim($key))] = trim($value);
        }

        $frequency = RecurrenceFrequency::fromRrule(
            $parts['FREQ'] ?? 'WEEKLY',
            (int) ($parts['INTERVAL'] ?? 1),
        );

        $weekdays = [];

        foreach (explode(',', $parts['BYDAY'] ?? '') as $code) {
            $weekday = Weekday::fromRruleCode($code);

            if ($weekday instanceof Weekday) {
                $weekdays[] = $weekday->value;
            }
        }

        return ['frequency' => $frequency, 'weekdays' => $weekdays];
    }

    /**
     * La regola detta in italiano: è ciò che compare nel pannello al posto
     * della stringa.
     */
    public static function describe(string $rrule): string
    {
        ['frequency' => $frequency, 'weekdays' => $weekdays] = self::parse($rrule);

        if (! $frequency->acceptsWeekdays() || $weekdays === []) {
            return $frequency->label();
        }

        $names = [];

        foreach ($weekdays as $value) {
            $weekday = Weekday::tryFrom($value);

            if ($weekday instanceof Weekday) {
                $names[] = mb_strtolower($weekday->label());
            }
        }

        return __('manage.recurrence.summary', [
            'frequency' => mb_strtolower($frequency->label()),
            'days' => implode(', ', $names),
        ]);
    }

    /**
     * @param  array<int, Weekday|string>  $weekdays
     * @return array<int, string>
     */
    private static function codes(array $weekdays): array
    {
        $codes = [];

        foreach ($weekdays as $weekday) {
            $case = $weekday instanceof Weekday ? $weekday : Weekday::tryFrom((string) $weekday);

            if ($case instanceof Weekday) {
                $codes[$case->isoNumber()] = $case->rruleCode();
            }
        }

        ksort($codes);

        return array_values($codes);
    }
}
