<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Support\Sponsorship\SponsorableEvents;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Il selettore dell'evento da sponsorizzare, nel pannello della redazione.
 *
 * Prima era una tendina di soli titoli: niente locale, niente data, cinquanta
 * voci su novantasei, nessuna ricerca per locale, quattro «Degustazione: dei»
 * identiche, e nella ricerca anche eventi già passati. Qui ogni voce è la riga
 * dei pannelli (`filament.partials.event-option`): locandina, titolo, «sab 22
 * nov, 21:00 · Officina dei Portici», con il distintivo «già sponsorizzato».
 *
 * **Solo eventi sponsorizzabili**, nell'elenco e nella ricerca: la regola è
 * `SponsorableEvents` (pubblicati, con una data ancora da fare), la stessa del
 * pannello del gestore e della sincronizzazione automatica. Chi chiama passa
 * la query di partenza già ristretta al committente (`$events`); il selettore
 * aggiunge la regola, l'ordine per prossima data e la ricerca per titolo e
 * per nome del locale.
 *
 * **Un valore fuori dalla query non passa.** L'etichetta del valore scelto si
 * cerca nella stessa query: un evento di un altro locale, o passato, non ha
 * etichetta e Filament lo rifiuta con la sua regola `in`. `$keep` permette
 * alla modifica di una campagna esistente di tenere il proprio evento anche
 * quando non è più sponsorizzabile.
 *
 * È un `Select` di Filament vero: chi lo usa concatena `->label()`,
 * `->required()`, `->live()`, `->afterStateUpdated()`, `->visible()`.
 */
final class EventPicker
{
    public const LIST_LIMIT = 50;

    public const SEARCH_LIMIT = 30;

    /**
     * @param  Closure  $events  la query di partenza (`Builder<Event>` o null), valutata con le dipendenze del campo (`Get`, il record)
     * @param  Closure|null  $keep  l'id di un evento da tenere anche se non è più sponsorizzabile (o null), valutato allo stesso modo
     */
    public static function make(string $name, Closure $events, ?Closure $keep = null): Select
    {
        return Select::make($name)
            ->label(__('sponsorships.picker.label'))
            ->placeholder(__('sponsorships.picker.placeholder'))
            ->searchable()
            ->allowHtml()
            ->options(static fn (Select $component): array => self::options(self::base($component, $events, $keep)))
            ->getSearchResultsUsing(static fn (Select $component, ?string $search): array => self::options(self::base($component, $events, $keep), $search))
            ->getOptionLabelUsing(static fn (Select $component, mixed $value): ?string => self::labelFor(self::base($component, $events, $keep), $value))
            ->searchPrompt(__('sponsorships.picker.search_prompt'))
            ->searchingMessage(__('pannelli.select.searching'))
            ->noSearchResultsMessage(__('sponsorships.picker.no_results'))
            /* Il titolo lungo che sul telefono usciva dalla tendina lo
               accorcia la regola generale nel tema del pannello. */
            ->noOptionsMessage(__('sponsorships.picker.no_options'));
    }

    /**
     * Le voci della query, dalla prossima data. La query è già ristretta
     * (`sponsorable()`): qui si aggiungono solo ricerca, ordine e righe.
     *
     * @param  Builder<Event>  $events
     * @return array<int|string, string>
     */
    public static function options(Builder $events, ?string $search = null, ?CarbonImmutable $now = null): array
    {
        $search = trim((string) $search);
        $query = self::withDates($events, $now);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->where('events.title', 'like', $like)
                ->orWhereExists(DB::table('venues')
                    ->whereColumn('venues.id', 'events.venue_id')
                    ->where('venues.name', 'like', $like)));
        }

        $rows = $query
            ->orderByRaw('next_starts_at is null')
            ->orderBy('next_starts_at')
            ->orderByDesc('events.id')
            ->limit($search !== '' ? self::SEARCH_LIMIT : self::LIST_LIMIT)
            ->get();

        $sponsored = array_flip(SponsorableEvents::alreadySponsored(array_map('intval', $rows->modelKeys()), $now));
        $labels = [];

        foreach ($rows as $event) {
            $labels[$event->getKey()] = self::render($event, isset($sponsored[(int) $event->getKey()]));
        }

        return $labels;
    }

    /**
     * La riga dell'evento scelto, oppure null se non sta nella query (ed è
     * così che un valore scritto a mano non passa).
     *
     * @param  Builder<Event>  $events
     */
    public static function labelFor(Builder $events, mixed $value, ?CarbonImmutable $now = null): ?string
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        $event = self::withDates($events, $now)->whereKey($value)->first();

        if (! $event instanceof Event) {
            return null;
        }

        /* Senza distintivo: nel campo già scelto «già sponsorizzato» direbbe
           della campagna stessa che si sta modificando, e sul telefono il
           distintivo non ci starebbe accanto alla freccia. */
        return self::render($event, false);
    }

    /**
     * Quanti eventi sponsorizzabili ha oggi la query: è il numero che «tutti
     * gli eventi» mostra prima di salvare.
     *
     * @param  Builder<Event>  $events
     */
    public static function count(Builder $events, ?CarbonImmutable $now = null): int
    {
        return SponsorableEvents::scope(clone $events, $now)->count();
    }

    /**
     * I primi eventi sponsorizzabili della query, con la prossima data già
     * calcolata: l'anteprima di «tutti gli eventi».
     *
     * @param  Builder<Event>  $events
     * @return list<Event>
     */
    public static function preview(Builder $events, int $limit = 5, ?CarbonImmutable $now = null): array
    {
        return array_values(self::withDates(self::sponsorable($events, null, $now), $now)
            ->orderBy('next_starts_at')
            ->orderByDesc('events.id')
            ->limit($limit)
            ->get()
            ->all());
    }

    /**
     * La query di partenza ristretta agli sponsorizzabili, più l'evento da
     * tenere se c'è.
     *
     * @return Builder<Event>
     */
    private static function base(Select $component, Closure $events, ?Closure $keep): Builder
    {
        /** @var mixed $query la chiusura viene da chi chiama: controllo che sia davvero una query */
        $query = $component->evaluate($events);
        $kept = $keep === null ? null : $component->evaluate($keep);

        if (! $query instanceof Builder) {
            return is_numeric($kept) ? Event::query()->whereKey((int) $kept) : Event::query()->whereRaw('1 = 0');
        }

        return self::sponsorable($query, is_numeric($kept) ? (int) $kept : null);
    }

    /**
     * Gli eventi della query che si possono sponsorizzare (`SponsorableEvents`),
     * oppure l'evento `$keep`.
     *
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    public static function sponsorable(Builder $events, ?int $keep = null, ?CarbonImmutable $now = null): Builder
    {
        /* `select()` sulla query di base toglie anche la sotto-query della
           prossima data che `scope()` aggiunge: resta la sola colonna degli id. */
        $ids = SponsorableEvents::scope(clone $events, $now)->toBase()->select('events.id');

        return Event::query()->where(fn (Builder $either) => $either
            ->whereIn('events.id', $ids)
            ->when($keep !== null, fn (Builder $q) => $q->orWhere('events.id', $keep)));
    }

    /**
     * Prossima data, quante date restano, locandina, locale e fuso, tutto
     * nella stessa lettura: il numero di query non cambia con le voci.
     *
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    private static function withDates(Builder $events, ?CarbonImmutable $now): Builder
    {
        $now ??= CarbonImmutable::now('UTC');
        $query = clone $events;

        if ($query->getQuery()->columns === null) {
            $query->select('events.*');
        }

        return $query
            ->addSelect([
                'next_starts_at' => self::validDates($now)->selectRaw('min(event_occurrences.starts_at)'),
                'upcoming_count' => self::validDates($now)->selectRaw('count(*)'),
            ])
            ->with(['media', 'venue:id,name', 'city:id,timezone']);
    }

    /** Le date ancora da fare (o in corso) di un evento, con la regola di `SponsorableEvents`. */
    private static function validDates(CarbonImmutable $now): QueryBuilder
    {
        return DB::table('event_occurrences')
            ->whereColumn('event_occurrences.event_id', 'events.id')
            ->whereNull('event_occurrences.deleted_at')
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
            ->where('event_occurrences.effective_ends_at', '>', $now);
    }

    private static function render(Event $event, bool $alreadySponsored): string
    {
        $upcoming = (int) $event->getAttribute('upcoming_count');

        return view('filament.partials.event-option', [
            'event' => $event,
            'when' => $event->getAttribute('next_starts_at'),
            'more' => max(0, $upcoming - 1),
            'venue' => $event->venue?->name,
            'badge' => $alreadySponsored ? ['label' => __('sponsorships.picker.already_sponsored'), 'color' => 'warning'] : null,
        ])->render();
    }
}
