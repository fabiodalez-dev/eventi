<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Actions\GenerateOccurrencesAction;
use App\Enums\RecurrenceFrequency;
use App\Models\Event;
use App\Models\EventRecurrence;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;

/**
 * «Ripeti *ogni settimana* il *giovedì* fino al *31 dicembre*» (§10.4): crea
 * o aggiorna la regola dell'evento e materializza le date.
 *
 * Stava in `RecurrenceForm::apply`, dentro il pannello; è qui perché la
 * chiama anche l'API di gestione quando l'app crea un evento con «Si ripete».
 * La regola la scrive `RecurrenceRule` e le date le genera
 * `GenerateOccurrencesAction`, che è idempotente (D20, D22).
 */
final class RecurrenceSetup
{
    /**
     * @param  array<int, mixed>  $weekdays  i valori di `Weekday` (`mon`, `thu`…)
     * @param  string|null  $until  la data `Y-m-d`, compresa, nell'ora della città
     * @return int quante date sono state create
     */
    public function apply(Event $event, ?RecurrenceFrequency $frequency, array $weekdays, ?string $until): int
    {
        if (! $frequency instanceof RecurrenceFrequency) {
            return 0;
        }

        $rrule = RecurrenceRule::build($frequency, $weekdays);
        $timezone = $event->city->timezone;

        $recurrence = EventRecurrence::query()->updateOrCreate(
            ['event_id' => $event->getKey()],
            [
                'rrule' => $rrule,
                // Fine giornata locale: chi scrive «fino al 31 dicembre»
                // intende compresa la sera del 31.
                'until' => filled($until)
                    ? CarbonImmutable::parse($until, $timezone)->endOfDay()->utc()->toMutable()
                    : null,
            ],
        );

        $recurrence->setRelation('event', $event);

        return app(GenerateOccurrencesAction::class)($recurrence);
    }
}
