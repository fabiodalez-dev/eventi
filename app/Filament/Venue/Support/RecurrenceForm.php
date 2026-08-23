<?php

declare(strict_types=1);

namespace App\Filament\Venue\Support;

use App\Actions\GenerateOccurrencesAction;
use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Models\Event;
use App\Models\EventRecurrence;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * «Ripeti *ogni settimana* il *giovedì* fino al *31 dicembre*» (§10.4).
 *
 * Tre campi, nessuno dei quali nomina RFC 5545: la regola la scrive
 * `RecurrenceRule` e la materializza `GenerateOccurrencesAction`, che esiste
 * già ed è idempotente (D20, D22) — qui non si genera nulla a mano.
 *
 * Gli stessi tre campi servono due punti diversi: l'ultimo passo del wizard,
 * dove la ripetizione si decide insieme alla prima data, e l'azione «ripeti»
 * sulla scheda di un evento già salvato. Un solo modulo per lo stesso gesto.
 */
final class RecurrenceForm
{
    /**
     * @return array<int, Component>
     */
    public static function fields(string $prefix = ''): array
    {
        $frequency = $prefix.'frequency';

        return [
            Select::make($frequency)
                ->label(__('manage.recurrence.frequency'))
                ->options(RecurrenceFrequency::options())
                ->default(RecurrenceFrequency::Weekly->value)
                ->required()
                ->live(),

            CheckboxList::make($prefix.'weekdays')
                ->label(__('manage.recurrence.weekdays'))
                ->helperText(__('manage.recurrence.weekdays_hint'))
                ->options(Weekday::options())
                ->columns(2)
                ->visible(fn (Get $get): bool => RecurrenceFrequency::tryFrom((string) $get($frequency))?->acceptsWeekdays() ?? false),

            DatePicker::make($prefix.'until')
                ->label(__('manage.recurrence.until'))
                ->helperText(__('manage.recurrence.until_hint')),
        ];
    }

    /**
     * Crea (o aggiorna) la regola dell'evento e materializza le date.
     *
     * @param  array<string, mixed>  $data
     * @return int quante date sono state create
     */
    public static function apply(Event $event, array $data, string $prefix = ''): int
    {
        $frequency = RecurrenceFrequency::tryFrom((string) ($data[$prefix.'frequency'] ?? ''));

        if (! $frequency instanceof RecurrenceFrequency) {
            return 0;
        }

        $weekdays = $data[$prefix.'weekdays'] ?? [];
        $rrule = RecurrenceRule::build($frequency, is_array($weekdays) ? $weekdays : []);

        $until = $data[$prefix.'until'] ?? null;
        $timezone = $event->city->timezone;

        $recurrence = EventRecurrence::query()->updateOrCreate(
            ['event_id' => $event->getKey()],
            [
                'rrule' => $rrule,
                // Fine giornata locale: chi scrive «fino al 31 dicembre»
                // intende compresa la sera del 31.
                'until' => filled($until)
                    ? CarbonImmutable::parse((string) $until, $timezone)->endOfDay()->utc()->toMutable()
                    : null,
            ],
        );

        $recurrence->setRelation('event', $event);

        return app(GenerateOccurrencesAction::class)($recurrence);
    }

    /**
     * Il modulo riaperto sulla regola già salvata, così che «ogni giovedì»
     * non torni a essere «ogni settimana» a ogni modifica.
     *
     * @return array<string, mixed>
     */
    public static function stateFrom(?EventRecurrence $recurrence, string $prefix = ''): array
    {
        if (! $recurrence instanceof EventRecurrence) {
            return [$prefix.'frequency' => RecurrenceFrequency::Weekly->value];
        }

        ['frequency' => $frequency, 'weekdays' => $weekdays] = RecurrenceRule::parse($recurrence->rrule);

        return [
            $prefix.'frequency' => $frequency->value,
            $prefix.'weekdays' => $weekdays,
            $prefix.'until' => $recurrence->until?->toDateString(),
        ];
    }
}
