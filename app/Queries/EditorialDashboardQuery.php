<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ApplicationStatus;
use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\ReportStatus;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use App\Models\Report;
use App\Models\Venue;
use App\Models\VenueApplication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le code di lavoro della redazione (§9.1) e i controlli di qualità (§14.5).
 *
 * Ogni riquadro della dashboard e ogni filtro corrispondente nelle liste
 * nascono **dallo stesso metodo di questa classe**: il numero che il pannello
 * mostra e le righe che si aprono cliccandolo non possono divergere, perché
 * sono la stessa query.
 *
 * Il confine con `EventOccurrenceQuery` è netto:
 *
 * - le finestre del **cartellone** ("oggi", "stasera", "in corso") restano là
 *   e qui si chiamano soltanto — vedi `scheduledToday()`;
 * - qui vivono le domande sull'**atto redazionale** (che cosa è stato
 *   pubblicato, che cosa aspetta una decisione) e sullo **stato delle schede**,
 *   che non sono finestre temporali del catalogo.
 *
 * "Pubblicato oggi" usa comunque il fuso della città, mai quello del server:
 * un turno di redazione finisce a mezzanotte locale (§8.1).
 */
final class EditorialDashboardQuery
{
    /**
     * Un locale è inattivo quando non ha date in cartellone da tanto tempo
     * (§14.5: «locali inattivi da 60 giorni»).
     */
    public const INACTIVE_VENUE_DAYS = 60;

    /**
     * Soglia di somiglianza fra due titoli oltre la quale la coppia diventa
     * un duplicato sospetto (§14.4).
     */
    public const DUPLICATE_SIMILARITY = 0.85;

    /**
     * Sotto questa distanza due locali diversi contano come lo stesso luogo
     * (§14.4: «stesso locale o distanza < 300m»).
     */
    public const DUPLICATE_DISTANCE_METERS = 300.0;

    private readonly CarbonImmutable $now;

    private function __construct(private readonly City $city)
    {
        $this->now = CarbonImmutable::now($city->timezone);
    }

    public static function for(City $city): self
    {
        return new self($city);
    }

    // ------------------------------------------------------------- le code

    /** @return Builder<Event> */
    public function pendingEvents(): Builder
    {
        return $this->events()->where('status', EventStatus::Pending);
    }

    /** @return Builder<Venue> */
    public function pendingVenues(): Builder
    {
        return $this->venues()->where('status', VenueStatus::Pending);
    }

    /** @return Builder<Event> */
    public function cancelledEvents(): Builder
    {
        return $this->events()->where('status', EventStatus::Cancelled);
    }

    /** @return Builder<VenueApplication> */
    public function pendingApplications(): Builder
    {
        return VenueApplication::query()->where('status', ApplicationStatus::Pending);
    }

    /** @return Builder<Report> */
    public function openReports(): Builder
    {
        return Report::query()->tap(self::openReportScope(...));
    }

    /** @return Builder<ImportSource> */
    public function failedImports(): Builder
    {
        return ImportSource::query()->where('city_id', $this->city->getKey())->tap(self::failedImportScope(...));
    }

    // ------------------------------------------------------- la pubblicazione

    /** @return Builder<Event> */
    public function publishedToday(): Builder
    {
        return $this->events()->tap($this->applyPublishedToday(...));
    }

    /** @return Builder<Event> */
    public function publishedThisWeek(): Builder
    {
        return $this->events()->tap($this->applyPublishedThisWeek(...));
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function applyPublishedToday(Builder $query): void
    {
        $query
            ->where('status', EventStatus::Published)
            ->where('published_at', '>=', $this->now->startOfDay()->utc());
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function applyPublishedThisWeek(Builder $query): void
    {
        $query
            ->where('status', EventStatus::Published)
            ->where('published_at', '>=', $this->now->startOfWeek()->utc());
    }

    /**
     * Quante date sono in cartellone nella giornata evento corrente. La
     * definizione di "oggi" non si trova qui: la dà `EventOccurrenceQuery`,
     * che è l'unico posto autorizzato a conoscerla (§3 delle convenzioni).
     */
    public function scheduledToday(): int
    {
        return EventOccurrenceQuery::for($this->city)->today()->count();
    }

    // ---------------------------------------------------------- la qualità

    /** @return Builder<Event> */
    public function eventsWithoutPoster(): Builder
    {
        return $this->events()->tap(self::missingPosterScope(...));
    }

    /** @return Builder<Event> */
    public function incompleteEvents(): Builder
    {
        return $this->events()->tap(self::incompleteScope(...));
    }

    /** @return Builder<Venue> */
    public function inactiveVenues(): Builder
    {
        return $this->venues()->tap(self::inactiveVenueScope(...));
    }

    /** @return Builder<Event> */
    public function possibleDuplicates(): Builder
    {
        return $this->events()->tap($this->applyPossibleDuplicates(...));
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function applyPossibleDuplicates(Builder $query): void
    {
        $query->whereKey($this->possibleDuplicateIds());
    }

    // -------------------------------------------- clausole riusate dai filtri

    /**
     * Senza locandina: né la colonna `poster`, né un file nella collezione
     * media omonima. Sono due strade per la stessa immagine — un import
     * scrive un URL, il pannello carica un file — e mancano entrambe.
     *
     * @param  Builder<Event>  $query
     */
    public static function missingPosterScope(Builder $query): void
    {
        $query
            ->where(fn (Builder $inner) => $inner->whereNull('poster')->orWhere('poster', ''))
            ->whereDoesntHave('media', fn (Builder $media) => $media->where('collection_name', 'poster'));
    }

    /**
     * Informazioni incomplete (§14.5): manca la descrizione, manca il prezzo
     * dichiarato, oppure non si sa dove si tiene — nessun locale e nessun
     * luogo libero. Ciascuna delle tre rende la scheda inservibile a chi legge.
     *
     * @param  Builder<Event>  $query
     */
    public static function incompleteScope(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner
                ->where(fn (Builder $q) => $q->whereNull('description')->orWhere('description', ''))
                ->orWhere('price_type', PriceType::Unknown)
                ->orWhere(fn (Builder $q) => $q->whereNull('venue_id')->whereNull('custom_location'));
        });
    }

    /**
     * Segnalazione ancora aperta: da leggere o già in lavorazione. Chiusa e
     * archiviata sono l'opposto, e non compaiono in nessuna coda.
     *
     * @param  Builder<Report>  $query
     */
    public static function openReportScope(Builder $query): void
    {
        $query->whereIn('status', [ReportStatus::Pending, ReportStatus::Reviewing]);
    }

    /**
     * @param  Builder<Venue>  $query
     */
    public static function inactiveVenueScope(Builder $query): void
    {
        $threshold = CarbonImmutable::now()->subDays(self::INACTIVE_VENUE_DAYS)->toDateString();

        $query
            ->where('status', VenueStatus::Approved)
            ->whereDoesntHave(
                'events.occurrences',
                fn (Builder $occurrences) => $occurrences->where('business_date', '>=', $threshold),
            );
    }

    /**
     * Un import è "fallito" quando la sorgente è attiva e l'ultima esecuzione
     * **è finita male**: `last_status = failed`.
     *
     * Prima si guardava `last_error`, ed era la domanda sbagliata. Quella
     * colonna la riempie anche un'esecuzione **riuscita in parte** — duecento
     * date entrate e tre voci illeggibili — che non è un import fallito e non
     * va contata fra i guasti: chi apre la dashboard e trova «1» si aspetta un
     * calendario che non porta più niente, non tre righe storte dentro un
     * calendario che funziona. Nella direzione opposta l'errore era peggiore:
     * un guasto senza testo (una condizione che nessuno scrive apposta, ma che
     * un driver futuro può produrre) restava invisibile.
     *
     * `last_status` è una stringa libera per contratto, quindi il valore si
     * legge dall'enum e non da una stringa scritta a mano qui.
     *
     * @param  Builder<ImportSource>  $query
     */
    public static function failedImportScope(Builder $query): void
    {
        $query
            ->where('is_active', true)
            ->where('last_status', ImportRunStatus::Failed->value);
    }

    /**
     * I duplicati sospetti di §14.4: **stessa giornata evento**, **stesso
     * locale oppure due locali a meno di trecento metri**, titoli quasi
     * identici.
     *
     * La prossimità serve al caso che il solo confronto per locale non vede:
     * la stessa sagra inserita due volte, una volta sul circolo e una volta
     * sulla piazza davanti. Sono due righe di `venues` diverse e per §14.4
     * sono lo stesso appuntamento.
     *
     * La somiglianza si calcola in PHP e non in SQL perché nessuno dei due
     * motori la offre; il costo resta basso perché il confronto avviene solo
     * dentro la stessa giornata, e solo sulle date non passate. Nessuna
     * cancellazione automatica: questo metodo produce candidati, la decisione
     * resta al moderatore.
     *
     * @return list<int>
     */
    public function possibleDuplicateIds(): array
    {
        $rows = EventOccurrence::query()
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->join('venues', 'venues.id', '=', 'events.venue_id')
            ->where('events.city_id', $this->city->getKey())
            ->whereNull('events.deleted_at')
            ->whereNull('venues.deleted_at')
            ->whereIn('events.status', [EventStatus::Draft, EventStatus::Pending, EventStatus::Published])
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled)
            ->where('event_occurrences.business_date', '>=', $this->now->toDateString())
            ->select([
                'events.id as event_id',
                'events.title as title',
                'events.venue_id as venue_id',
                'venues.lat as lat',
                'venues.lng as lng',
                'event_occurrences.business_date as business_date',
            ])
            ->get();

        /** @var array<string, array<int, array{id: int, title: string, venue_id: int, lat: float, lng: float}>> $days */
        $days = [];

        foreach ($rows as $row) {
            /** @var object{event_id: int, title: string, venue_id: int, lat: mixed, lng: mixed, business_date: string} $row */
            $days[(string) $row->business_date][(int) $row->event_id] = [
                'id' => (int) $row->event_id,
                'title' => (string) $row->title,
                'venue_id' => (int) $row->venue_id,
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
            ];
        }

        $duplicates = [];

        foreach ($days as $day) {
            $day = array_values($day);
            $size = count($day);

            for ($i = 0; $i < $size; $i++) {
                for ($j = $i + 1; $j < $size; $j++) {
                    if (! self::sameSpot($day[$i], $day[$j])) {
                        continue;
                    }

                    if (! self::titlesLookAlike($day[$i]['title'], $day[$j]['title'])) {
                        continue;
                    }

                    $duplicates[$day[$i]['id']] = true;
                    $duplicates[$day[$j]['id']] = true;
                }
            }
        }

        return array_map(intval(...), array_keys($duplicates));
    }

    /**
     * Stesso luogo ai fini di §14.4: lo stesso locale, oppure due locali
     * abbastanza vicini da essere la stessa piazza.
     *
     * @param  array{id: int, title: string, venue_id: int, lat: float, lng: float}  $first
     * @param  array{id: int, title: string, venue_id: int, lat: float, lng: float}  $second
     */
    private static function sameSpot(array $first, array $second): bool
    {
        if ($first['venue_id'] === $second['venue_id']) {
            return true;
        }

        return self::metersBetween($first['lat'], $first['lng'], $second['lat'], $second['lng'])
            < self::DUPLICATE_DISTANCE_METERS;
    }

    /**
     * La distanza in metri fra due coordinate (formula dell'emisenoverso).
     *
     * Si calcola qui e non con `ST_Distance_Sphere` perché la domanda è fra
     * **due righe già lette**, non fra una riga e un punto: portarla in SQL
     * significherebbe un prodotto cartesiano della tabella con se stessa per
     * ottenere numeri che a questa scala si contano sulle dita.
     */
    private static function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;

        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function titlesLookAlike(string $first, string $second): bool
    {
        $first = mb_strtolower(trim($first));
        $second = mb_strtolower(trim($second));

        if ($first === '' || $second === '') {
            return false;
        }

        similar_text($first, $second, $percent);

        return $percent / 100 >= self::DUPLICATE_SIMILARITY;
    }

    // ----------------------------------------------------------------- interno

    /** @return Builder<Event> */
    private function events(): Builder
    {
        return Event::query()->where('city_id', $this->city->getKey());
    }

    /** @return Builder<Venue> */
    private function venues(): Builder
    {
        return Venue::query()->where('city_id', $this->city->getKey());
    }
}
