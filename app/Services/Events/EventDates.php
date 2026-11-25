<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Actions\UpdateOccurrencesAction;
use App\Enums\EventStatus;
use App\Enums\LineupRole;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Models\Booking;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Lineup;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Queries\VenueDashboardQuery;
use App\Services\Analytics\OccurrenceAnalytics;
use App\Support\Management\ManagementSubject;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Le date di un evento come le gestiscono i pannelli, per l'app.
 *
 * Due moduli, come le due sezioni dei pannelli:
 *
 * - **locale** («Date», `OccurrencesRelationManager`): inizio, fine, posti
 *   totali e rimasti, evidenza, lineup; lo stato si cambia con due gesti
 *   (annulla, anche «questa e le successive», e tutto esaurito) e la data
 *   si può eliminare;
 * - **organizzazione** («Date e luoghi», `DatesRelationManager`): inizio,
 *   fine, tutto il giorno, locale della data, stato fra i cinque e avviso
 *   al pubblico; niente eliminazione. Una data nuova o portata in un altro
 *   locale su un evento pubblicato lo rimanda alla redazione
 *   (`OrganizerEventEditor::afterDateSaved`).
 *
 * In entrambi informazioni pratiche e costi dichiarati della data
 * (`OccurrenceDecisionFields`), con il permesso della data
 * (`EventOccurrencePolicy::update`): li ha anche il collaboratore, come sul
 * sito (decisione 6). Le due parti, quando arrivano, sostituiscono quelle
 * salvate per intero: un campo `null` vuol dire «usa quello dell'evento o del
 * locale», come nell'endpoint della biglietteria.
 */
final class EventDates
{
    public const PER_PAGE = 50;

    /** Le colonne del modulo del locale. */
    private const VENUE_COLUMNS = ['starts_at', 'ends_at', 'capacity', 'capacity_left', 'highlight', 'practical_details', 'cost_breakdown'];

    /** Le colonne del modulo dell'organizzazione. */
    private const ORGANIZER_COLUMNS = ['starts_at', 'ends_at', 'is_all_day', 'venue_id', 'status', 'status_note', 'practical_details', 'cost_breakdown'];

    public function __construct(
        private readonly UpdateOccurrencesAction $occurrences,
        private readonly OrganizerEventEditor $organizerEditor,
    ) {}

    /**
     * Le date dell'evento, con visite, clic e salvataggi come le colonne del
     * pannello. `period`: `upcoming` (da oggi, in ordine), `past` (dalla più
     * recente), altrimenti tutte in ordine di inizio come la tabella.
     *
     * @return LengthAwarePaginator<int, EventOccurrence>
     */
    public function page(Event $event, ?string $period, int $page): LengthAwarePaginator
    {
        $today = EventOccurrenceQuery::for($event->city)->currentBusinessDate();
        $query = OccurrenceAnalytics::withTotals(EventOccurrence::query()->where('event_occurrences.event_id', $event->getKey()))
            ->with(['venue', 'lineups' => fn ($lineups) => $lineups->ordered()]);

        match ($period) {
            'upcoming' => $query->where('business_date', '>=', $today)->orderBy('starts_at'),
            'past' => $query->where('business_date', '<', $today)->orderByDesc('starts_at'),
            default => $query->orderBy('starts_at'),
        };

        $result = $query->orderBy('event_occurrences.id')->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
        $result->getCollection()->each(fn (EventOccurrence $date) => $date->setRelation('event', $event));

        return $result;
    }

    /**
     * Una data per l'app: i campi dei due moduli, i numeri dell'elenco, i
     * collegamenti (biglietteria se chi guarda la gestisce, locandina in PDF
     * per gli eventi pubblicati o archiviati) e i gesti possibili.
     *
     * @return array<string, mixed>
     */
    public function row(EventOccurrence $date, ManagementSubject $subject): array
    {
        $event = $date->event;
        $date->loadMissing(['venue', 'lineups']);
        $gate = Gate::forUser($subject->user);
        $update = $gate->allows('update', $date);
        $venue = $subject->isVenue();
        $today = EventOccurrenceQuery::for($event->city)->currentBusinessDate();
        $poster = in_array($event->status, [EventStatus::Published, EventStatus::Archived], true);
        $views = $date->getAttribute('analytics_views');

        return [
            'id' => (int) $date->getKey(),
            'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
            'ends_at' => $date->ends_at?->copy()->utc()->toIso8601String(),
            'doors_at' => $date->doors_at?->copy()->utc()->toIso8601String(),
            'is_all_day' => (bool) $date->is_all_day,
            'business_date' => $date->business_date->format('Y-m-d'),
            'past' => $date->business_date->format('Y-m-d') < $today,
            'status' => ['value' => $date->status->value, 'label' => $date->status->label()],
            'status_note' => $date->status_note,
            'highlight' => $date->highlight,
            'capacity' => $date->capacity,
            'capacity_left' => $date->capacity_left,
            'venue' => $date->venue === null ? null : ['id' => (int) $date->venue->getKey(), 'name' => $date->venue->name],
            'place' => $date->locationLabel(),
            'lineup' => $date->lineups->map(fn (Lineup $artist): array => [
                'id' => (int) $artist->getKey(),
                'name' => $artist->name,
                'role' => ['value' => $artist->role->value, 'label' => $artist->role->label()],
            ])->values()->all(),
            'practical_details' => self::object($date->practical_details),
            'cost_breakdown' => self::object($date->getAttribute('cost_breakdown')),
            'currency' => $event->currency ?: 'EUR',
            'series' => $date->recurrence_id !== null,
            'is_exception' => (bool) $date->is_exception,
            'stats' => $views === null ? null : [
                'views' => (int) $views,
                'clicks' => (int) $date->getAttribute('analytics_clicks'),
                'saves' => (int) $date->getAttribute('saved_events_count'),
            ],
            'ticketing_date_id' => $gate->allows('manage', [Booking::class, $date]) ? (int) $date->getKey() : null,
            'poster_pdf' => $poster,
            'poster_pdf_url' => $poster && Route::has('events.poster') ? route('events.poster', ['slug' => $event->slug, 'occurrence' => $date->url_number]) : null,
            'abilities' => [
                'update' => $update,
                'delete' => $venue && $gate->allows('delete', $date),
                'cancel' => $venue && $update && $date->status !== OccurrenceStatus::Cancelled,
                'sold_out' => $venue && $update,
            ],
        ];
    }

    /**
     * Il quadro della schermata: l'evento in breve, la ripetizione già
     * impostata (detta a parole, come nel pannello) e che cosa si può fare.
     *
     * @return array<string, mixed>
     */
    public function context(Event $event, ManagementSubject $subject): array
    {
        $gate = Gate::forUser($subject->user);
        $hasDates = $event->occurrences()->exists();

        return [
            'event' => [
                'id' => (int) $event->getKey(),
                'title' => $event->title,
                'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
                'city' => $event->city->name,
                'timezone' => $event->city->timezone,
                'currency' => $event->currency ?: 'EUR',
            ],
            'recurrence' => $subject->isVenue() ? $this->recurrence($event) : null,
            'abilities' => [
                'create' => $gate->allows('create', [EventOccurrence::class, $event]),
                'recurrence' => $subject->isVenue() && $gate->allows('update', $event),
                'recurrence_needs_dates' => ! $hasDates,
            ],
        ];
    }

    /**
     * La ripetizione dell'evento come la riapre il modulo «Ripeti»: frequenza,
     * giorni, «fino al» nella data della città, e la frase del pannello.
     *
     * @return array{frequency: string, weekdays: list<string>, until: string|null, description: string}|null
     */
    public function recurrence(Event $event): ?array
    {
        $recurrence = $event->recurrences()->orderBy('id')->first();
        if ($recurrence === null) {
            return null;
        }
        ['frequency' => $frequency, 'weekdays' => $weekdays] = RecurrenceRule::parse($recurrence->rrule);

        return [
            'frequency' => $frequency->value,
            'weekdays' => array_values($weekdays),
            'until' => $recurrence->until === null ? null : CarbonImmutable::instance($recurrence->until)->setTimezone($event->city->timezone)->format('Y-m-d'),
            'description' => RecurrenceRule::describe($recurrence->rrule),
        ];
    }

    /**
     * Aggiunge o modifica una data dal modulo del locale. `$data` è già
     * validato: le chiavi assenti non si toccano, `lineup` sostituisce gli
     * artisti della data (quelli con `id` si aggiornano, gli altri si
     * aggiungono, i mancanti si tolgono), come il ripetitore del pannello.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVenueDate(Event $event, ?EventOccurrence $date, array $data): EventOccurrence
    {
        return DB::transaction(function () use ($event, $date, $data): EventOccurrence {
            $date = $this->fill($event, $date, Arr::only($data, self::VENUE_COLUMNS));
            if (array_key_exists('lineup', $data)) {
                $this->syncLineup($date, is_array($data['lineup']) ? $data['lineup'] : []);
            }

            return $date;
        });
    }

    /**
     * Aggiunge o modifica una data dal modulo dell'organizzazione, poi
     * applica il ritorno in revisione del pannello: data nuova, o locale
     * cambiato, su un evento pubblicato.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: EventOccurrence, 1: bool} la data e se l'evento è tornato in revisione
     */
    public function saveOrganizerDate(Event $event, ?EventOccurrence $date, array $data, User $user): array
    {
        $creating = $date === null;
        $columns = Arr::only($data, self::ORGANIZER_COLUMNS);
        if (array_key_exists('status', $columns)) {
            $columns['status'] = OccurrenceStatus::from((string) $columns['status']);
        }
        $date = DB::transaction(fn (): EventOccurrence => $this->fill($event, $date, $columns));
        $sent = $this->organizerEditor->afterDateSaved($event, $user, $creating || $date->wasChanged('venue_id'));

        return [$date, $sent];
    }

    /**
     * «Annulla» (anche «questa e le successive»), «Tutto esaurito» e «Di nuovo
     * disponibile» del pannello del locale, con le stesse condizioni dei
     * pulsanti: si annulla una data non annullata; l'esaurito vale sempre per
     * la sola data; si torna disponibili solo da esaurita.
     *
     * @return int quante date sono cambiate
     */
    public function changeStatus(EventOccurrence $date, OccurrenceStatus $status, OccurrenceScope $scope): int
    {
        if ($status === OccurrenceStatus::Cancelled && $date->status === OccurrenceStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Questa data è già annullata.']);
        }
        if ($status === OccurrenceStatus::Scheduled && $date->status !== OccurrenceStatus::SoldOut) {
            throw ValidationException::withMessages(['status' => 'Si rimette in vendita solo una data segnata come tutto esaurito.']);
        }
        if (! in_array($status, [OccurrenceStatus::Cancelled, OccurrenceStatus::SoldOut, OccurrenceStatus::Scheduled], true)) {
            throw ValidationException::withMessages(['status' => 'Il locale annulla una data o la segna come tutto esaurito.']);
        }

        return $this->occurrences->changeStatus($date, $status, null, $status === OccurrenceStatus::Cancelled ? $scope : OccurrenceScope::Single);
    }

    /**
     * La prossima data dell'evento (da oggi, nella giornata della città), su
     * cui agisce «Tutto esaurito» dall'elenco degli eventi.
     */
    public function nextDate(Event $event, City $city): ?EventOccurrence
    {
        return VenueDashboardQuery::nextOccurrence($event, $city);
    }

    /**
     * «Tutto esaurito» o «Di nuovo disponibile» sulla prossima data, dalla
     * riga dell'elenco: sempre la sola data, mai la serie («stasera è
     * esaurito» non dice niente sul giovedì dopo). Lo usano l'azione del
     * pannello (`EventActions::soldOutNextDate`) e l'app.
     *
     * @return int quante date sono cambiate
     */
    public function setSoldOut(EventOccurrence $date, bool $soldOut): int
    {
        if (! $soldOut && $date->status !== OccurrenceStatus::SoldOut) {
            return 0;
        }

        return $this->occurrences->changeStatus($date, $soldOut ? OccurrenceStatus::SoldOut : OccurrenceStatus::Scheduled, null, OccurrenceScope::Single);
    }

    /**
     * Scrive le colonne su una data nuova o esistente. Gli orari arrivano con
     * il loro scarto (o nell'ora della città) e si salvano in UTC; la fine
     * deve venire dopo l'inizio anche quando arriva da sola.
     *
     * @param  array<string, mixed>  $columns
     */
    private function fill(Event $event, ?EventOccurrence $date, array $columns): EventOccurrence
    {
        $timezone = $event->city->timezone;
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $columns)) {
                $columns[$field] = filled($columns[$field]) ? CarbonImmutable::parse((string) $columns[$field], $timezone)->utc()->toMutable() : null;
            }
        }

        $date ??= new EventOccurrence(['event_id' => $event->getKey()]);
        $date->fill($columns);
        // Una data nuova senza inizio: la richiesta lo chiede già, qui resta la regola per chi chiama il servizio.
        if ($date->getAttribute('starts_at') === null) {
            throw ValidationException::withMessages(['starts_at' => __('validation.required', ['attribute' => 'inizio'])]);
        }
        if ($date->ends_at !== null && $date->ends_at->lte($date->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => __('validation.after', ['attribute' => 'fine', 'date' => 'inizio'])]);
        }
        $date->setRelation('event', $event);
        $date->save();

        return $date;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncLineup(EventOccurrence $date, array $rows): void
    {
        /** @var Collection<int, Lineup> $current */
        $current = $date->lineups()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $values = ['name' => (string) $row['name'], 'role' => LineupRole::from((string) $row['role'])];
            $existing = $id !== null ? $current->get($id) : null;
            if ($existing instanceof Lineup) {
                $existing->fill($values)->save();
                $kept[] = $id;
            } else {
                $kept[] = (int) $date->lineups()->create($values)->getKey();
            }
        }
        $date->lineups()->whereNotIn('id', $kept === [] ? [0] : $kept)->get()->each(fn (Lineup $artist) => $artist->delete());
        $date->unsetRelation('lineups');
    }

    /**
     * Un oggetto JSON anche quando è vuoto: `[]` non è un oggetto per l'app.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function object(mixed $value): array|\stdClass
    {
        return is_array($value) && $value !== [] ? $value : new \stdClass;
    }
}
