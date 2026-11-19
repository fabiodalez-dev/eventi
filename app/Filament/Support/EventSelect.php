<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Event;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Il selettore di eventi dei pannelli: uno solo, uguale dappertutto.
 *
 * Prima ogni pagina sceglieva un evento a modo suo: un ID da scrivere, un
 * elenco di titoli con lo slug fra parentesi, date in cifre. Qui ogni voce ha
 * locandina, titolo e «sab 22 nov, 21:00 · locale», divise in «Prossimi» e
 * «Passati», con la ricerca per titolo e per nome del locale.
 *
 * È un `Select` di Filament vero: ricerca, tastiera, tema e telefono li fa
 * Filament, e chi lo usa può concatenare `->label()`, `->default()`,
 * `->required()`, `->live()`, `->afterStateUpdated()`, `->placeholder()`.
 *
 * **Chi vede cosa.** `$events` restituisce la query degli eventi che chi
 * guarda può vedere (la stessa di oggi: l'autorizzazione resta dov'è). Un
 * valore che non sta in quella query non ha un'etichetta, e Filament lo
 * rifiuta con la sua regola `in`: un ID scritto a mano non passa.
 */
final class EventSelect
{
    /**
     * @param  Closure(): Builder<Event>  $events
     */
    public static function make(string $name, Closure $events, bool $showVenue = true, bool $upcomingOnly = false): Select
    {
        return Select::make($name)
            ->label(__('pannelli.select.event_label'))
            ->placeholder(__('pannelli.select.event'))
            ->searchable()
            ->allowHtml()
            ->options(static fn (Select $component): array => EventOptions::grouped(self::query($component, $events), null, $showVenue, $upcomingOnly))
            ->getSearchResultsUsing(static fn (Select $component, ?string $search): array => EventOptions::grouped(self::query($component, $events), $search, $showVenue, $upcomingOnly))
            ->getOptionLabelUsing(static fn (Select $component, mixed $value): ?string => EventOptions::labelFor(self::query($component, $events), $value, $showVenue))
            ->searchPrompt(__('pannelli.select.type_to_search'))
            ->searchingMessage(__('pannelli.select.searching'))
            ->noSearchResultsMessage(__('pannelli.select.no_results'));
    }

    /**
     * Il filtro «Evento» delle tabelle, con le stesse voci del selettore.
     *
     * Un valore che non sta nella query non allarga niente: la tabella resta
     * ristretta agli eventi che `$events` lascia vedere.
     *
     * @param  Closure(): Builder<Event>  $events
     */
    public static function filter(string $name, Closure $events, string $column = 'event_id', bool $showVenue = true): SelectFilter
    {
        return SelectFilter::make($name)
            ->label(__('pannelli.select.event_label'))
            ->placeholder(__('pannelli.select.all_events'))
            ->searchable()
            ->options(static fn (SelectFilter $filter): array => EventOptions::grouped(self::query($filter, $events), null, $showVenue))
            /* Queste due le valuta il campo della tendina, non il filtro. */
            ->getSearchResultsUsing(static fn (Select $component, ?string $search): array => EventOptions::grouped(self::query($component, $events), $search, $showVenue))
            ->getOptionLabelUsing(static fn (Select $component, mixed $value): ?string => EventOptions::labelFor(self::query($component, $events), $value, $showVenue))
            ->modifyFormFieldUsing(static fn (Select $field): Select => $field
                ->allowHtml()
                ->searchPrompt(__('pannelli.select.type_to_search'))
                ->searchingMessage(__('pannelli.select.searching'))
                ->noSearchResultsMessage(__('pannelli.select.no_results')))
            ->indicateUsing(static function (SelectFilter $filter, array $state) use ($events): array {
                $title = EventOptions::title(self::query($filter, $events), $state['value'] ?? null);

                return $title === null ? [] : [Indicator::make(__('pannelli.select.event_label').': '.$title)];
            })
            ->query(static function (SelectFilter $filter, Builder $query, array $data) use ($events, $column): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                $allowed = self::query($filter, $events);

                return $query->whereIn($query->qualifyColumn($column), $allowed->whereKey($value)->select($allowed->getModel()->qualifyColumn('id')));
            });
    }

    /**
     * La query degli eventi visibili, con le dipendenze del componente
     * (chi la scrive può chiedere `Get`, il record, il filtro).
     *
     * @param  Closure(): Builder<Event>  $events
     * @return Builder<Event>
     */
    private static function query(Select|SelectFilter $component, Closure $events): Builder
    {
        /** @var mixed $query la chiusura viene da chi chiama: controllo che sia davvero una query */
        $query = $component->evaluate($events);

        return $query instanceof Builder ? clone $query : Event::query()->whereRaw('1 = 0');
    }
}
