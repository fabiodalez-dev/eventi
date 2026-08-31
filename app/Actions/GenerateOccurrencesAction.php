<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RRule\RRule;

/**
 * Materializza in `event_occurrences` le date di una ricorrenza RFC 5545 (§7.8).
 *
 * Tre proprietà la governano:
 *
 * - **orizzonte**: almeno dodici mesi avanti, poi lo scheduler mensile estende
 *   `generated_until`;
 * - **idempotenza**: la chiave logica è `(event_id, starts_at)`; rieseguirla
 *   non crea nulla di nuovo e non modifica nulla di esistente;
 * - **eccezioni intatte**: le occorrenze con `is_exception = true` non vengono
 *   toccate, perché l'azione non aggiorna né cancella — inserisce soltanto le
 *   date mancanti. Una data spostata o annullata si esclude con `exdates`.
 *
 * Le date si generano nel fuso della città e si salvano in UTC: un
 * appuntamento settimanale alle 21:00 resta alle 21:00 anche dopo il cambio
 * dell'ora, che è ciò che si aspetta chi lo ha inserito.
 */
final class GenerateOccurrencesAction
{
    /**
     * Mesi di calendario materializzati in avanti a ogni esecuzione.
     */
    public const HORIZON_MONTHS = 12;

    /**
     * @return int numero di occorrenze create
     */
    public function __invoke(EventRecurrence $recurrence, ?CarbonImmutable $horizon = null): int
    {
        $plan = $this->plan($recurrence, $horizon);

        if ($plan === null) {
            return 0;
        }

        ['event' => $event, 'end' => $end, 'template' => $template, 'starts' => $starts] = $plan;

        $existing = $this->existingStarts($event);
        $created = 0;

        DB::transaction(function () use ($starts, $event, $recurrence, $template, $existing, $end, &$created): void {
            foreach ($starts as $localStart) {
                $startsAt = $localStart->utc();
                $key = $startsAt->format('Y-m-d H:i:s');

                if (isset($existing[$key])) {
                    continue;
                }

                $occurrence = new EventOccurrence([
                    'event_id' => $event->getKey(),
                    'recurrence_id' => $recurrence->getKey(),
                    'starts_at' => $startsAt,
                    'ends_at' => $this->shifted($localStart, $template?->starts_at, $template?->ends_at),
                    'doors_at' => $this->shifted($localStart, $template?->starts_at, $template?->doors_at),
                    'is_all_day' => $template !== null && $template->is_all_day,
                    'status' => OccurrenceStatus::Scheduled,
                    'is_exception' => false,
                ]);

                // La relazione è già in memoria: senza, l'observer ricaricherebbe
                // evento, città e categoria per ogni singola data generata.
                $occurrence->setRelation('event', $event);
                $occurrence->save();

                $existing[$key] = true;
                $created++;
            }

            $recurrence->generated_until = Carbon::instance($end->utc());
            $recurrence->save();
        });

        return $created;
    }

    /**
     * Gli istanti UTC che la regola produce fino all'orizzonte, `EXDATE`
     * comprese: la stessa espansione che `__invoke()` materializza, esposta
     * senza scrivere niente.
     *
     * Esiste per l'import (§14.2). Quando un calendario a monte riscrive la
     * propria `RRULE`, le date che la nuova regola non produce più vanno
     * marcate come annullate — e per saperlo serve l'elenco di ciò che la
     * regola produce **adesso**. Chiederlo qui è l'unico modo di non avere due
     * espansori di regole RFC 5545 in due punti diversi del progetto, che è la
     * ricetta sicura per farli divergere.
     *
     * @return array<string, true> chiavi `Y-m-d H:i:s` in UTC
     */
    public function expectedStarts(EventRecurrence $recurrence, ?CarbonImmutable $horizon = null): array
    {
        $plan = $this->plan($recurrence, $horizon);

        if ($plan === null) {
            return [];
        }

        $expected = [];

        foreach ($plan['starts'] as $localStart) {
            $expected[$localStart->utc()->format('Y-m-d H:i:s')] = true;
        }

        return $expected;
    }

    /**
     * Tutto ciò che serve per sapere quali date la serie produce: l'evento, il
     * fuso, l'orizzonte effettivo, il modello da cui si eredita e le date
     * locali già ripulite dalle esclusioni.
     *
     * @return array{event: Event, timezone: string, end: CarbonImmutable, template: ?EventOccurrence, starts: list<CarbonImmutable>}|null
     */
    private function plan(EventRecurrence $recurrence, ?CarbonImmutable $horizon): ?array
    {
        $event = $recurrence->event()->withTrashed()->with(['city', 'category', 'venue'])->first();

        if (! $event instanceof Event || $event->city === null) {
            return null;
        }

        $timezone = $event->city->timezone;
        $horizon ??= CarbonImmutable::now($timezone)->addMonths(self::HORIZON_MONTHS)->endOfDay();
        $horizon = $horizon->setTimezone($timezone);

        $until = $recurrence->until !== null
            ? CarbonImmutable::instance($recurrence->until)->setTimezone($timezone)
            : null;

        $end = $until !== null && $until->lessThan($horizon) ? $until : $horizon;

        $template = $this->template($recurrence, $event);
        $dates = $this->dates($recurrence, $template, $timezone, $end);

        if ($dates === null) {
            return null;
        }

        $excluded = $this->exclusions($recurrence, $timezone);
        $starts = [];

        foreach ($dates as $date) {
            $localStart = CarbonImmutable::instance($date)->setTimezone($timezone);

            if (isset($excluded[$localStart->format('Y-m-d')]) || isset($excluded[$localStart->format('Y-m-d H:i:s')])) {
                continue;
            }

            $starts[] = $localStart;
        }

        return [
            'event' => $event,
            'timezone' => $timezone,
            'end' => $end,
            'template' => $template,
            'starts' => $starts,
        ];
    }

    /**
     * L'occorrenza da cui si ereditano durata, porte e intera giornata, e da
     * cui si ricava il `DTSTART` quando la regola non lo porta con sé.
     *
     * Deve essere **la stessa a ogni esecuzione**, altrimenti l'idempotenza
     * salta: prendendo la prima occorrenza *della ricorrenza* si otterrebbe la
     * data iniziale al primo giro e la seconda data al giro successivo — con
     * `COUNT=10` la finestra scivolerebbe in avanti di una settimana per volta,
     * creando una data nuova a ogni esecuzione. Si parte quindi sempre dalla
     * prima occorrenza dell'evento, escludendo quelle appartenenti ad altre
     * ricorrenze dello stesso evento.
     */
    private function template(EventRecurrence $recurrence, Event $event): ?EventOccurrence
    {
        return $event->occurrences()
            ->where(function (Builder $query) use ($recurrence): void {
                $query->whereNull('recurrence_id')->orWhere('recurrence_id', $recurrence->getKey());
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Espande la regola RFC 5545 fino all'orizzonte. `DTSTART` può stare nella
     * stringa; se manca si ricava dalla prima occorrenza già presente, letta
     * nel fuso della città.
     *
     * @return list<DateTimeInterface>|null `null` se la ricorrenza non ha una regola utilizzabile
     */
    private function dates(EventRecurrence $recurrence, ?EventOccurrence $template, string $timezone, CarbonImmutable $end): ?array
    {
        $rrule = trim($recurrence->rrule);

        if ($rrule === '') {
            return null;
        }

        if (str_contains(strtoupper($rrule), 'DTSTART')) {
            $rule = new RRule($rrule);
        } else {
            $starts = $template?->starts_at;

            if ($starts === null) {
                return null;
            }

            $rule = new RRule($rrule, CarbonImmutable::instance($starts)->setTimezone($timezone)->toDateTime());
        }

        $dates = [];

        foreach ($rule->getOccurrencesBetween(null, $end) as $date) {
            if ($date instanceof DateTimeInterface) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Istanti già presenti per l'evento, in UTC: è la garanzia di idempotenza.
     *
     * @return array<string, true>
     */
    private function existingStarts(Event $event): array
    {
        $starts = EventOccurrence::query()
            ->where('event_id', $event->getKey())
            ->toBase()
            ->pluck('starts_at');

        $existing = [];

        foreach ($starts as $start) {
            if (is_string($start)) {
                $existing[$start] = true;
            }
        }

        return $existing;
    }

    /**
     * Date escluse dalla serie. Una data pura esclude l'intera giornata, un
     * istante esclude la sola occorrenza corrispondente.
     *
     * @return array<string, true>
     */
    private function exclusions(EventRecurrence $recurrence, string $timezone): array
    {
        $exdates = $recurrence->exdates;

        if (! is_array($exdates)) {
            return [];
        }

        $excluded = [];

        foreach ($exdates as $exdate) {
            if (! is_string($exdate) || trim($exdate) === '') {
                continue;
            }

            $parsed = CarbonImmutable::parse($exdate, $timezone)->setTimezone($timezone);

            $excluded[preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($exdate)) === 1
                ? $parsed->format('Y-m-d')
                : $parsed->format('Y-m-d H:i:s')] = true;
        }

        return $excluded;
    }

    /**
     * Riporta sulla nuova data lo scarto che il modello aveva rispetto al
     * proprio inizio: un evento che chiudeva tre ore dopo continua a farlo.
     */
    private function shifted(CarbonImmutable $localStart, ?DateTimeInterface $reference, ?DateTimeInterface $value): ?CarbonImmutable
    {
        if ($reference === null || $value === null) {
            return null;
        }

        return $localStart->addSeconds($value->getTimestamp() - $reference->getTimestamp())->utc();
    }
}
