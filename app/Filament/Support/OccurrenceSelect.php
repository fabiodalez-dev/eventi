<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Scegliere una data di un evento: «sab 22 nov, 21:00», con sotto il locale
 * quando non è quello principale dell'evento. Mai «Data n. 45», mai
 * «27/08/2026 21:30».
 *
 * `$occurrences` restituisce la query delle date che chi guarda può vedere;
 * un valore fuori da quella query non ha etichetta e Filament lo rifiuta.
 * Con `$showEvent` ogni voce porta anche la riga dell'evento (locandina e
 * titolo), per i selettori in cui le date sono di eventi diversi.
 */
final class OccurrenceSelect
{
    /** Quante date si mostrano al massimo per gruppo. */
    public const LIMIT = 60;

    /**
     * @param  Closure(): Builder<EventOccurrence>  $occurrences
     */
    public static function make(string $name, Closure $occurrences, bool $showEvent = false): Select
    {
        return Select::make($name)
            ->label(__('pannelli.select.occurrence'))
            ->placeholder(__('pannelli.select.occurrence_placeholder'))
            ->allowHtml()
            ->options(static fn (Select $component): array => self::grouped(self::query($component, $occurrences), $showEvent))
            ->getOptionLabelUsing(static function (Select $component, mixed $value) use ($occurrences, $showEvent): ?string {
                if (blank($value) || ! is_scalar($value)) {
                    return null;
                }

                $occurrence = self::query($component, $occurrences)->whereKey($value)->first();

                return $occurrence instanceof EventOccurrence ? self::render($occurrence, $showEvent) : null;
            });
    }

    /**
     * @param  Builder<EventOccurrence>  $occurrences
     * @return array<string, array<int|string, string>>
     */
    public static function grouped(Builder $occurrences, bool $showEvent = false): array
    {
        $now = CarbonImmutable::now('UTC');
        $column = $occurrences->getModel()->qualifyColumn('starts_at');

        $upcoming = (clone $occurrences)->where($column, '>=', $now)->orderBy($column)->limit(self::LIMIT)->get();
        $past = (clone $occurrences)->where($column, '<', $now)->orderByDesc($column)->limit(self::LIMIT)->get();

        return array_filter([
            __('pannelli.select.upcoming_dates') => self::labels($upcoming, $showEvent),
            __('pannelli.select.past_dates') => self::labels($past, $showEvent),
        ]);
    }

    /**
     * @param  EloquentCollection<int, EventOccurrence>  $occurrences
     * @return array<int|string, string>
     */
    private static function labels(EloquentCollection $occurrences, bool $showEvent): array
    {
        $occurrences->load(['venue:id,name', 'event' => fn ($query) => $query->withoutGlobalScopes()->with(['media', 'venue:id,name', 'city:id,timezone'])]);

        return $occurrences
            ->mapWithKeys(fn (EventOccurrence $occurrence): array => [$occurrence->getKey() => self::render($occurrence, $showEvent)])
            ->all();
    }

    private static function render(EventOccurrence $occurrence, bool $showEvent): string
    {
        $event = $occurrence->event;
        $when = PanelDate::event($occurrence->starts_at, $event?->city?->timezone);
        /* Il locale si scrive solo quando la data è altrove. */
        $elsewhere = $occurrence->venue_id !== null && (int) $occurrence->venue_id !== (int) $event?->venue_id
            ? $occurrence->venue?->name
            : null;

        if ($showEvent && $event !== null) {
            return view('filament.partials.event-option', [
                'event' => $event,
                'meta' => collect([$when, $elsewhere ?? $event->venue?->name])->filter()->implode(' · '),
            ])->render();
        }

        return '<span class="incitta-occurrence-option"><span class="incitta-occurrence-option-when">'.e($when).'</span>'
            .($elsewhere !== null ? '<span class="incitta-occurrence-option-venue">'.e($elsewhere).'</span>' : '')
            .'</span>';
    }

    /**
     * @param  Closure(): Builder<EventOccurrence>  $occurrences
     * @return Builder<EventOccurrence>
     */
    private static function query(Select $component, Closure $occurrences): Builder
    {
        /** @var mixed $query la chiusura viene da chi chiama: controllo che sia davvero una query */
        $query = $component->evaluate($occurrences);

        return $query instanceof Builder ? clone $query : EventOccurrence::query()->whereRaw('1 = 0');
    }
}
