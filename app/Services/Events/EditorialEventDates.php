<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Actions\GenerateOccurrencesAction;
use App\Actions\UpdateOccurrencesAction;
use App\Enums\BookingStatus;
use App\Enums\LineupRole;
use App\Enums\MembershipRequirement;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Enums\TicketTierStatus;
use App\Enums\VenueStatus;
use App\Exceptions\InvalidRecurrenceRule;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Lineup;
use App\Models\TicketTier;
use App\Models\User;
use App\Support\DeclaredCosts;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le date di un evento nella redazione: la sezione «Date» della pagina
 * «Modifica evento» (`OccurrencesRelationManager`) e «Genera date»
 * (`EditEvent`), per il pannello e per l'app.
 *
 * Il modulo di una data è quello del pannello: informazioni pratiche e costi
 * (`OccurrenceDecisionFields`), locale della data, inizio, fine, apertura
 * porte, tutto il giorno, stato fra i cinque, posti totali e rimasti,
 * evidenza, avviso al pubblico, listino della sola data e lineup. Gli orari
 * sono nell'ora della città dell'evento.
 *
 * I gesti della riga chiamano le stesse azioni del pannello (che passa di
 * qui): «Sposta» e «Annulla» chiedono «questa data soltanto o tutta la
 * serie?» solo se la data viene da una ricorrenza; «Tutto esaurito» e «Di
 * nuovo disponibile» valgono per la sola data.
 */
final class EditorialEventDates
{
    public const PER_PAGE = 50;

    /** Le colonne del modulo della data. */
    private const COLUMNS = [
        'venue_id', 'starts_at', 'ends_at', 'doors_at', 'is_all_day', 'status', 'capacity', 'capacity_left',
        'highlight', 'status_note', 'practical_details', 'cost_breakdown',
    ];

    public function __construct(
        private readonly UpdateOccurrencesAction $occurrences,
        private readonly GenerateOccurrencesAction $generator,
    ) {}

    /**
     * Le date in ordine di inizio, come la tabella del pannello.
     *
     * @return LengthAwarePaginator<int, EventOccurrence>
     */
    public function page(Event $event, int $page): LengthAwarePaginator
    {
        $result = EventOccurrence::query()->where('event_id', $event->getKey())
            ->with(['venue', 'lineups', 'ticketTiers'])->withCount('lineups')
            ->orderBy('starts_at')->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
        $result->getCollection()->each(fn (EventOccurrence $date) => $date->setRelation('event', $event));

        return $result;
    }

    /**
     * Una data con i campi del modulo e i gesti permessi.
     *
     * @return array<string, mixed>
     */
    public function row(EventOccurrence $date, User $user): array
    {
        $event = $date->event;
        $date->loadMissing(['venue', 'lineups', 'ticketTiers']);
        $gate = Gate::forUser($user);
        $update = $gate->allows('update', $date);
        $timezone = $event->city->timezone;
        $utc = fn (mixed $value): ?string => $value instanceof CarbonInterface ? $value->copy()->utc()->toIso8601String() : null;

        return [
            'id' => (int) $date->getKey(),
            'starts_at' => $utc($date->starts_at),
            'ends_at' => $utc($date->ends_at),
            'doors_at' => $utc($date->doors_at),
            'effective_ends_at' => $utc($date->effective_ends_at),
            'business_date' => $date->business_date->format('Y-m-d'),
            'timezone' => $timezone,
            'is_all_day' => (bool) $date->is_all_day,
            'status' => ['value' => $date->status->value, 'label' => $date->status->label()],
            'status_note' => $date->status_note,
            'highlight' => $date->highlight,
            'capacity' => $date->capacity,
            'capacity_left' => $date->capacity_left,
            'venue' => $date->venue === null ? null : ['id' => (int) $date->venue->getKey(), 'name' => (string) $date->venue->name],
            'practical_details' => self::object($date->practical_details),
            'cost_breakdown' => self::object($date->getAttribute('cost_breakdown')),
            'currency' => $event->currency ?: 'EUR',
            'series' => $date->recurrence_id !== null,
            'is_exception' => (bool) $date->is_exception,
            'lineups_count' => $date->lineups->count(),
            'lineup' => $date->lineups->sortBy('id')->map(fn (Lineup $artist): array => [
                'id' => (int) $artist->getKey(),
                'name' => (string) $artist->name,
                'role' => $artist->role->value,
                'starts_at' => $utc($artist->starts_at),
                'url' => $artist->url,
            ])->values()->all(),
            'ticket_tiers' => $date->ticketTiers->map(fn (TicketTier $tier): array => [
                'id' => (int) $tier->getKey(),
                'name' => (string) $tier->name,
                'price' => $tier->price === null ? null : (float) $tier->price,
                'status' => $tier->status->value,
                'url' => $tier->url,
                'note' => $tier->note,
            ])->values()->all(),
            // «Gestisci biglietti» (R-EV-24): la biglietteria della data, nativa nell'app.
            'ticketing_path' => $gate->allows('manage', [Booking::class, $date]) ? 'gestione-biglietti/'.$date->getKey() : null,
            'abilities' => [
                'update' => $update,
                'shift' => $update,
                'cancel' => $update && $date->status !== OccurrenceStatus::Cancelled,
                'sold_out' => $update,
                'destroy' => $gate->allows('delete', $date),
                'ticketing' => $gate->allows('manage', [Booking::class, $date]),
            ],
        ];
    }

    /**
     * Le regole del modulo della data (`OccurrencesRelationManager::form`).
     * In creazione l'inizio è obbligatorio; in modifica le chiavi assenti non
     * si toccano. `$cityId` è la città dell'evento, l'unica dei locali scelti.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $creating, ?int $cityId = null): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $rules = [
            // Un locale approvato, non nel cestino, della città dell'evento (le scelte del campo del pannello).
            'venue_id' => ['sometimes', 'nullable', 'integer', Rule::exists('venues', 'id')->where('status', VenueStatus::Approved->value)
                ->where('city_id', $cityId ?? 0)->withoutTrashed()],
            'starts_at' => [$presence, 'required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'doors_at' => ['sometimes', 'nullable', 'date'],
            'is_all_day' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'required', Rule::enum(OccurrenceStatus::class)],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'capacity_left' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'highlight' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'practical_details' => ['sometimes', 'nullable', 'array:accessibility,membership,entrance_notes,membership_notes,parking_notes,transit_notes,food_notes,start_notes'],
            'practical_details.accessibility' => ['nullable', Rule::in(['yes', 'no', 'unknown'])],
            'practical_details.membership' => ['nullable', Rule::enum(MembershipRequirement::class)],
            'cost_breakdown' => ['sometimes', 'nullable', 'array:'.implode(',', DeclaredCosts::FIELDS)],
            'cost_breakdown.*' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'ticket_tiers' => ['sometimes', 'nullable', 'array', 'max:'.VenueEventEditor::MAX_TIERS],
            'ticket_tiers.*' => ['array:id,name,price,status,url,note'],
            'ticket_tiers.*.id' => ['nullable', 'integer'],
            'ticket_tiers.*.name' => ['required', 'string', 'max:255'],
            'ticket_tiers.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'ticket_tiers.*.status' => ['required', Rule::enum(TicketTierStatus::class)],
            'ticket_tiers.*.url' => ['nullable', 'string', 'url', 'max:255'],
            'ticket_tiers.*.note' => ['nullable', 'string', 'max:255'],
            'lineup' => ['sometimes', 'nullable', 'array', 'max:100'],
            'lineup.*' => ['array:id,name,role,starts_at,url'],
            'lineup.*.id' => ['nullable', 'integer'],
            'lineup.*.name' => ['required', 'string', 'max:255'],
            'lineup.*.role' => ['required', Rule::enum(LineupRole::class)],
            'lineup.*.starts_at' => ['nullable', 'date'],
            'lineup.*.url' => ['nullable', 'string', 'url', 'max:255'],
        ];
        foreach (['entrance_notes', 'membership_notes', 'parking_notes', 'transit_notes', 'food_notes', 'start_notes'] as $field) {
            $rules['practical_details.'.$field] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    /**
     * Aggiunge o modifica una data: colonne, listino della data e lineup in
     * una transazione. La fine deve venire dopo l'inizio anche quando arriva
     * da sola.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Event $event, ?EventOccurrence $date, array $input, User $actor): EventOccurrence
    {
        $creating = $date === null;
        $creating
            ? Gate::forUser($actor)->authorize('create', [EventOccurrence::class, $event])
            : Gate::forUser($actor)->authorize('update', $date);
        $data = Validator::make($input, self::rules($creating, (int) $event->city_id), [], self::attributes())->validate();

        return DB::transaction(function () use ($event, $date, $data): EventOccurrence {
            $columns = Arr::only($data, self::COLUMNS);
            foreach (['starts_at', 'ends_at', 'doors_at'] as $field) {
                if (array_key_exists($field, $columns)) {
                    $columns[$field] = filled($columns[$field]) ? EditorialEventEditor::instant($columns[$field], $event->city) : null;
                }
            }
            if (array_key_exists('is_all_day', $columns)) {
                $columns['is_all_day'] = (bool) $columns['is_all_day'];
            }
            foreach (['practical_details', 'cost_breakdown'] as $part) {
                if (array_key_exists($part, $columns) && is_array($columns[$part])) {
                    $columns[$part] = array_filter($columns[$part], fn (mixed $value): bool => $value !== null && $value !== '');
                    $columns[$part] = $columns[$part] === [] ? null : $columns[$part];
                }
            }

            $date ??= new EventOccurrence(['event_id' => $event->getKey(), 'status' => OccurrenceStatus::Scheduled]);
            $date->fill($columns);
            if ($date->ends_at !== null && $date->ends_at->lte($date->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => __('validation.after', ['attribute' => __('admin.fields.ends_at'), 'date' => __('admin.fields.starts_at')])]);
            }
            $date->setRelation('event', $event);
            $date->save();

            if (array_key_exists('ticket_tiers', $data)) {
                $this->syncTiers($event, $date, is_array($data['ticket_tiers']) ? array_values($data['ticket_tiers']) : []);
            }
            if (array_key_exists('lineup', $data)) {
                $this->syncLineup($event, $date, is_array($data['lineup']) ? array_values($data['lineup']) : []);
            }

            return $date->refresh();
        });
    }

    /**
     * «Sposta»: inizio, porte e fine di tanti minuti, la sola data o la
     * serie da qui in avanti.
     *
     * @return int quante date sono cambiate
     */
    public function shift(EventOccurrence $date, int $minutes, OccurrenceScope $scope): int
    {
        return $this->occurrences->shift($date, $minutes, $scope);
    }

    /**
     * «Annulla la data» con l'avviso al pubblico, la sola data o la serie.
     *
     * @return int quante date sono cambiate
     */
    public function cancel(EventOccurrence $date, ?string $note, OccurrenceScope $scope): int
    {
        return $this->occurrences->changeStatus($date, OccurrenceStatus::Cancelled, $note, $scope);
    }

    /**
     * La data ha prenotazioni non annullate: annullarla le annullerebbe
     * (`TicketingService::cancelDate` prende le stesse righe).
     */
    public function hasActiveBookings(EventOccurrence $date): bool
    {
        return Booking::query()->where('occurrence_id', $date->getKey())->where('status', '!=', BookingStatus::Cancelled)->exists();
    }

    /**
     * «Tutto esaurito» o, se lo è già, «Rimetti in programma»: sempre la
     * sola data.
     *
     * @return int quante date sono cambiate
     */
    public function toggleSoldOut(EventOccurrence $date): int
    {
        return $this->occurrences->changeStatus(
            $date,
            $date->status === OccurrenceStatus::SoldOut ? OccurrenceStatus::Scheduled : OccurrenceStatus::SoldOut,
            null,
            OccurrenceScope::Single,
        );
    }

    /**
     * «Genera date»: materializza le date di ogni ricorrenza dell'evento.
     * Idempotente (D20, D22): rieseguirla non aggiunge doppioni e non tocca
     * le date modificate a mano. Una regola che il generatore rifiuta
     * (illeggibile, più fitta di una volta al giorno, troppe date) ferma il
     * gesto con una `ValidationException` su `recurrences`.
     *
     * @return int quante date sono nate
     */
    public function generate(Event $event): int
    {
        $created = 0;
        foreach ($event->recurrences()->get() as $recurrence) {
            /** @var EventRecurrence $recurrence */
            $recurrence->setRelation('event', $event);
            try {
                $created += ($this->generator)($recurrence);
            } catch (InvalidRecurrenceRule $exception) {
                // Una regola salvata prima dei controlli (o arrivata da un import): il motivo, non un 500.
                throw ValidationException::withMessages(['recurrences' => $exception->getMessage().' ('.$recurrence->rrule.')']);
            }
        }

        return $created;
    }

    /**
     * Il listino della sola data: sostituisce quello dell'evento per quella
     * sera. Le voci appartengono anche all'evento (`event_id`).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncTiers(Event $event, EventOccurrence $date, array $rows): void
    {
        $existing = $date->ticketTiers()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $position => $row) {
            $values = [
                'name' => (string) $row['name'],
                'price' => isset($row['price']) && $row['price'] !== '' ? $row['price'] : null,
                'status' => $row['status'],
                'url' => filled($row['url'] ?? null) ? $row['url'] : null,
                'note' => filled($row['note'] ?? null) ? $row['note'] : null,
                'sort_order' => $position + 1,
            ];
            $tier = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($tier instanceof TicketTier) {
                $tier->fill($values)->save();
                $kept[] = (int) $tier->getKey();

                continue;
            }
            $kept[] = (int) $date->ticketTiers()->create([...$values, 'event_id' => $event->getKey()])->getKey();
        }
        $existing->reject(fn (TicketTier $tier): bool => in_array((int) $tier->getKey(), $kept, true))
            ->each(fn (TicketTier $tier) => $tier->delete());
    }

    /**
     * La lineup della data: nome, ruolo, ora d'inizio (nell'ora della città)
     * e collegamento.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncLineup(Event $event, EventOccurrence $date, array $rows): void
    {
        $existing = $date->lineups()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $row) {
            $values = [
                'name' => (string) $row['name'],
                'role' => LineupRole::from((string) $row['role']),
                'starts_at' => filled($row['starts_at'] ?? null) ? EditorialEventEditor::instant($row['starts_at'], $event->city) : null,
                'url' => filled($row['url'] ?? null) ? $row['url'] : null,
            ];
            $artist = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($artist instanceof Lineup) {
                $artist->fill($values)->save();
                $kept[] = (int) $artist->getKey();

                continue;
            }
            $kept[] = (int) $date->lineups()->create($values)->getKey();
        }
        $existing->reject(fn (Lineup $artist): bool => in_array((int) $artist->getKey(), $kept, true))
            ->each(fn (Lineup $artist) => $artist->delete());
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $names = ['venue_id' => 'Locale di questa data'];
        foreach (['starts_at', 'ends_at', 'doors_at', 'is_all_day', 'capacity_left', 'status_note'] as $field) {
            $names[$field] = (string) __('admin.fields.'.$field);
        }
        $names['status'] = (string) __('admin.fields.occurrence_status');
        $names['capacity'] = (string) __('admin.fields.occurrence_capacity');
        $names['highlight'] = (string) __('admin.fields.occurrence_highlight');
        $names['ticket_tiers.*.name'] = (string) __('admin.fields.tier_name');
        $names['ticket_tiers.*.price'] = (string) __('admin.fields.tier_price');
        $names['ticket_tiers.*.url'] = (string) __('admin.fields.tier_url');
        $names['lineup.*.name'] = (string) __('admin.fields.name');
        $names['lineup.*.url'] = (string) __('admin.fields.url');

        return $names;
    }

    /** @return array<string, mixed>|\stdClass */
    private static function object(mixed $value): array|\stdClass
    {
        return is_array($value) && $value !== [] ? $value : new \stdClass;
    }
}
