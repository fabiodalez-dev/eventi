<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StatsPeriod;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventViewDaily;
use App\Models\SavedEvent;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * I numeri del pannello di un locale: il riepilogo di §10.1 e le statistiche
 * di §10.5.
 *
 * Il confine con `EventOccurrenceQuery` è lo stesso che vale per la redazione
 * (vedi `EditorialDashboardQuery`): le **finestre di cartellone** — oggi, da
 * oggi in poi — non si calcolano qui, si chiedono là. Quello che vive in
 * questa classe è l'**andamento**: quante volte una scheda è stata aperta,
 * salvata, e quante volte qualcuno ha chiesto come arrivarci.
 *
 * **Nessun dato personale.** §10.5 lo prescrive e la classe lo rispetta per
 * costruzione: si contano righe, mai persone. `event_views_daily` è aggregata
 * già nello schema (unique su `(event_id, date)`), e dei salvataggi si legge
 * il solo conteggio — mai chi li ha fatti.
 */
final class VenueDashboardQuery
{
    private readonly CarbonImmutable $now;

    private function __construct(private readonly Venue $venue)
    {
        $this->now = CarbonImmutable::now($venue->city->timezone);
    }

    public static function for(Venue $venue): self
    {
        return new self($venue->relationLoaded('city') ? $venue : $venue->load('city'));
    }

    // ------------------------------------------------------------- riepilogo

    /**
     * Le date del locale nella giornata evento corrente (§10.1). La
     * definizione di "oggi" non sta qui: la dà il motore temporale.
     */
    public function today(): int
    {
        return $this->occurrences()->today()->count();
    }

    /**
     * Le date del locale da oggi in avanti, oggi compreso.
     */
    public function upcoming(): int
    {
        return $this->occurrences()->upcoming()->count();
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function todaySchedule(): Collection
    {
        return $this->occurrences()->today()->get();
    }

    // ---------------------------------------------------------- statistiche

    /**
     * I quattro numeri di §10.5 sul periodo scelto.
     *
     * @return array{views: int, saves: int, direction_clicks: int, ticket_clicks: int}
     */
    public function totals(StatsPeriod $period): array
    {
        $from = $this->from($period);

        /** @var object{views: int|null, direction_clicks: int|null, ticket_clicks: int|null}|null $row */
        $row = EventViewDaily::query()
            ->whereIn('event_id', $this->eventIds())
            ->where('date', '>=', $from)
            ->selectRaw('SUM(views) as views, SUM(direction_clicks) as direction_clicks, SUM(ticket_clicks) as ticket_clicks')
            ->first();

        return [
            'views' => (int) ($row->views ?? 0),
            'saves' => $this->saves($period),
            'direction_clicks' => (int) ($row->direction_clicks ?? 0),
            'ticket_clicks' => (int) ($row->ticket_clicks ?? 0),
        ];
    }

    public function views(StatsPeriod $period): int
    {
        return $this->totals($period)['views'];
    }

    /**
     * Quante volte gli eventi del locale sono stati salvati nel periodo.
     * Si conta la riga, non la persona.
     */
    public function saves(StatsPeriod $period): int
    {
        return SavedEvent::query()
            ->whereHas(
                'occurrence',
                fn (Builder $query) => $query->whereIn('event_id', $this->eventIds()),
            )
            ->where('saved_events.created_at', '>=', $this->from($period)->startOfDay()->utc())
            ->count();
    }

    /**
     * Gli eventi del locale con i totali del periodo già sommati: è la tabella
     * della pagina delle statistiche.
     *
     * @return Builder<Event>
     */
    public function eventTotals(StatsPeriod $period): Builder
    {
        $from = $this->from($period);

        /** @var Builder<Event> $query */
        $query = Event::query()
            ->where('venue_id', $this->venue->getKey())
            ->withSum(['dailyViews as views_total' => fn (Builder $views) => $views->where('date', '>=', $from)], 'views')
            ->withSum(['dailyViews as direction_clicks_total' => fn (Builder $views) => $views->where('date', '>=', $from)], 'direction_clicks')
            ->withSum(['dailyViews as ticket_clicks_total' => fn (Builder $views) => $views->where('date', '>=', $from)], 'ticket_clicks');

        return $query;
    }

    /**
     * Aggiunge alla lista degli eventi la **prossima data in cartellone**
     * (`next_starts_at`), che è l'unica informazione temporale di cui il
     * gestore ha bisogno per riconoscere una riga.
     *
     * La soglia non viene ricalcolata: è la giornata evento corrente della
     * città, quella di `EventOccurrenceQuery` (§8.1). La query però non passa
     * dal motore, e per una ragione precisa: il motore mostra soltanto ciò che
     * è **pubblicato**, mentre questo elenco esiste proprio per lavorare sulle
     * bozze. Il confine è netto — dal motore arriva la definizione di "oggi",
     * qui resta il permesso di vedere anche ciò che il pubblico non vede.
     *
     * @param  Builder<Event>  $query
     */
    public static function applyNextOccurrence(Builder $query, City $city): void
    {
        $today = EventOccurrenceQuery::for($city)->currentBusinessDate();

        $query->withMin(
            ['occurrences as next_starts_at' => fn (Builder $occurrences) => $occurrences->where('business_date', '>=', $today)],
            'starts_at',
        );
    }

    // ----------------------------------------------------------------- interno

    /**
     * Il motore temporale ristretto al locale: ogni finestra di giornata di
     * questa classe passa di qui e da nessun'altra parte (§8.1).
     */
    private function occurrences(): EventOccurrenceQuery
    {
        return EventOccurrenceQuery::for($this->venue->city)->atVenue($this->venue);
    }

    private function from(StatsPeriod $period): CarbonImmutable
    {
        return $this->now->subDays($period->days() - 1)->startOfDay();
    }

    /**
     * @return Builder<Event>
     */
    private function eventIds(): Builder
    {
        /** @var Builder<Event> $query */
        $query = Event::query()
            ->select('id')
            ->where('venue_id', $this->venue->getKey());

        return $query;
    }
}
