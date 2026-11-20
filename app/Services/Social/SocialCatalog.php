<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\OccurrenceStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Collection;

final class SocialCatalog
{
    /**
     * Le date di un giorno per il carosello. Gli eventi dimostrativi non
     * entrano nel catalogo automatico (`social:daily`, il riquadro «oggi»):
     * sui social l'avviso della scheda non c'è. Chi ne sceglie uno per
     * identificativo dallo studio lo sta chiedendo apposta, e così il pannello
     * di un locale, che li mostra con il distintivo «Dimostrativo»
     * (`$includeDemo`).
     *
     * Le preferenze di chi guarda (categorie e locali nascosti) non contano:
     * è un catalogo di lavoro, non una vetrina, e chi gestisce un locale
     * deve vedere tutte le proprie serate.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function events(City $city, string $date, ?int $venueId = null, ?int $eventId = null, bool $includeDemo = false): Collection
    {
        /* Lo studio chiede le stesse serate più volte nella stessa pagina
           (anteprima, elenco, azioni): una lettura sola per richiesta. */
        return RequestMemo::remember('social-events:'.json_encode([$city->id, $date, $venueId, $eventId, $includeDemo]), fn (): Collection => $this->loadEvents($city, $date, $venueId, $eventId, $includeDemo));
    }

    /** @return Collection<int, EventOccurrence> */
    private function loadEvents(City $city, string $date, ?int $venueId, ?int $eventId, bool $includeDemo): Collection
    {
        $query = $eventId !== null
            ? EventOccurrenceQuery::managementFor($this->event($city, $eventId))->onDate($date)
            : EventOccurrenceQuery::competitionFor($city)->onDate($date);
        if ($eventId === null && ! $includeDemo) {
            $query->excludingDemo();
        }
        if ($venueId !== null) {
            $query->atVenue($venueId);
        }
        if ($eventId !== null) {
            $query->forEvent($eventId);
        }

        return $query->get()->filter(fn (EventOccurrence $date) => $date->status === OccurrenceStatus::Scheduled)->values()
            ->load(['event.media', 'event.venue', 'venue']);
    }

    /**
     * Le serate di un periodo, per l'elenco a card dello studio: tutte, anche
     * quelle annullate o rinviate, così chi le cerca capisce perché non si
     * possono preparare. Con l'evento della scheda (`$eventId`) valgono le
     * stesse regole di `events()`.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function period(City $city, string $from, string $until, ?int $venueId = null, ?int $eventId = null, bool $includeDemo = false, string $search = '', int $limit = 24): Collection
    {
        return RequestMemo::remember('social-period:'.json_encode([$city->id, $from, $until, $venueId, $eventId, $includeDemo, $search, $limit]), fn (): Collection => $this->periodQuery($city, $from, $until, $venueId, $eventId, $includeDemo)
            ->search($search)->take($limit)->load(['event.media', 'event.venue', 'venue']));
    }

    /**
     * Quante serate ci sono in ciascun giorno, per il piccolo calendario.
     *
     * @return array<string, int>
     */
    public function counts(City $city, string $from, string $until, ?int $venueId = null, ?int $eventId = null, bool $includeDemo = false, string $search = ''): array
    {
        return $this->periodQuery($city, $from, $until, $venueId, $eventId, $includeDemo)->search($search)->countsByBusinessDate();
    }

    public function total(City $city, string $from, string $until, ?int $venueId = null, ?int $eventId = null, bool $includeDemo = false, string $search = ''): int
    {
        return $this->periodQuery($city, $from, $until, $venueId, $eventId, $includeDemo)->search($search)->count();
    }

    private function periodQuery(City $city, string $from, string $until, ?int $venueId, ?int $eventId, bool $includeDemo): EventOccurrenceQuery
    {
        $query = $eventId !== null
            ? EventOccurrenceQuery::managementFor($this->event($city, $eventId))
            : EventOccurrenceQuery::competitionFor($city);
        $query->between($from, $until);
        if ($eventId === null && ! $includeDemo) {
            $query->excludingDemo();
        }
        if ($venueId !== null) {
            $query->atVenue($venueId);
        }

        return $query;
    }

    /** L'evento della scheda, con la sua città, letto una volta per richiesta. */
    private function event(City $city, int $eventId): Event
    {
        return RequestMemo::remember('social-event:'.$city->id.':'.$eventId, fn (): Event => Event::with('city')->where('city_id', $city->id)->findOrFail($eventId));
    }
}
