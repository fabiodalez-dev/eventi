<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Models\CatalogReview;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Reviews\CatalogReviews;
use App\Support\Management\ManagementSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * I contenuti di un soggetto di gestione, scritti una volta sola.
 *
 * Nei pannelli il confine lo mette la tenancy di Filament; nell'API non c'è
 * nessuno scope globale, e ogni query va ristretta a mano. Le regole sono
 * quelle dei pannelli, copiate da lì e non reinventate:
 *
 * - eventi del locale: `events.venue_id`;
 * - eventi dell'organizzazione: `organizer_id` oppure co-organizzati
 *   (`event_organizer`), come l'elenco di `/organizza`;
 * - commenti: gli eventi del locale, o quelli con il solo `organizer_id`
 *   dell'organizzazione (un co-organizzatore non modera i commenti altrui);
 * - recensioni: quelle pubblicate sul soggetto, come la pagina «Recensioni».
 *
 * Ogni metodo accetta la query su cui applicare la regola: così le risorse
 * Filament la applicano alla propria query (che porta già i suoi scope) invece
 * di aprirne una nuova dentro `scopeEloquentQueryToTenant`, dove una
 * sottoquery sullo stesso modello ricorrerebbe all'infinito.
 */
final class ManagedContent
{
    public function __construct(private readonly Venue|Organizer $subject) {}

    public static function for(Venue|Organizer $subject): self
    {
        return new self($subject);
    }

    /** Il contenuto del soggetto della richiesta; 404 se non c'è un soggetto. */
    public static function current(): self
    {
        return new self(ManagementSubject::require()->model);
    }

    /** @return Builder<Event> */
    public function events(): Builder
    {
        return $this->restrictEvents(Event::query());
    }

    /**
     * La regola degli eventi applicata a una query data: la usa la risorsa
     * degli eventi del pannello dell'organizzazione sulla propria query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrictEvents(Builder $query): Builder
    {
        $id = $this->subject->getKey();

        if ($this->subject instanceof Venue) {
            return $query->where('events.venue_id', $id);
        }

        return $query->where(fn (Builder $owned) => $owned->where('events.organizer_id', $id)
            ->orWhereHas('organizers', fn (Builder $shared) => $shared->whereKey($id)));
    }

    /**
     * Le date degli eventi del soggetto. Si parte dall'evento, non dal luogo
     * della data: una data spostata in un altro locale resta di chi ha
     * l'evento, come nell'elenco delle date del pannello.
     *
     * @param  Builder<EventOccurrence>|null  $query
     * @return Builder<EventOccurrence>
     */
    public function occurrences(?Builder $query = null): Builder
    {
        $query ??= EventOccurrence::query();

        return $query->whereIn('event_occurrences.event_id', $this->events()->select('events.id'));
    }

    /**
     * @param  Builder<EventComment>|null  $query
     * @return Builder<EventComment>
     */
    public function comments(?Builder $query = null): Builder
    {
        $query ??= EventComment::query();
        $column = $this->subject instanceof Venue ? 'venue_id' : 'organizer_id';
        $id = $this->subject->getKey();

        return $query->whereHas('event', fn (Builder $event) => $event->where($column, $id));
    }

    /**
     * @param  Builder<CatalogReview>|null  $query
     * @return Builder<CatalogReview>
     */
    public function reviews(?Builder $query = null): Builder
    {
        return app(CatalogReviews::class)->onlyPublished($query ?? CatalogReview::query(), $this->subject);
    }

    /** Un evento del soggetto, o 404: mai un `Event::findOrFail` libero. */
    public function event(int $id): Event
    {
        return $this->events()->whereKey($id)->firstOrFail();
    }
}
