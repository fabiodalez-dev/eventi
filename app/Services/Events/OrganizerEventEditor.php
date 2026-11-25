<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Import\FacebookEventImport;
use App\Support\BeforeGoingDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Il salvataggio di un evento dell'organizzazione, per il pannello
 * (`/organizza`) e per l'app.
 *
 * Le regole stavano nelle pagine «Nuovo evento» e «Modifica evento» del
 * pannello; qui restano identiche:
 *
 * - l'evento nasce **sempre in bozza**, a nome dell'organizzazione, con
 *   provenienza `manual`; la prima data è facoltativa ed è scritta nell'ora
 *   della città scelta;
 * - un evento importato da Facebook porta con sé la provenienza e i link
 *   letti là;
 * - **qualunque** salvataggio di un evento pubblicato lo rimanda alla
 *   redazione: l'organizzazione non pubblica mai da sé (`EventReviewRule`);
 * - città e locale principale li cambia **solo l'organizzazione principale**
 *   (`organizer_id`): spostare l'evento in un altro locale dà al referente di
 *   quel locale la scheda e l'elenco di chi ha prenotato, e un
 *   co-organizzatore non può decidere per gli altri dove si tiene.
 */
final class OrganizerEventEditor
{
    /** Le colonne che il modulo dell'organizzazione scrive. */
    public const COLUMNS = [
        'title', 'description', 'city_id', 'venue_id', 'category_id', 'price_type', 'price_min', 'price_max',
        'ticket_url', 'custom_location', 'content_details',
    ];

    /**
     * Le colonne che dicono dove si tiene l'evento. Decidono anche chi lo
     * vede nel proprio pannello (`ManagedContent`: `events.venue_id`), e per
     * questo le cambia solo l'organizzazione principale.
     */
    public const PLACE_COLUMNS = ['city_id', 'venue_id'];

    public function __construct(private readonly EventReviewRule $review) {}

    /**
     * Vero se questa organizzazione può spostare l'evento (città e locale
     * principale): solo quella di `organizer_id`, non i co-organizzatori.
     */
    public function canMove(Event $event, Organizer $organizer): bool
    {
        return $event->organizer_id !== null && (int) $event->organizer_id === (int) $organizer->getKey();
    }

    /**
     * Gli attributi di creazione aggiunti a quelli del modulo: autore,
     * organizzazione, stato e provenienza.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $facebookImport
     * @return array<string, mixed>
     */
    public function creationAttributes(Organizer $organizer, User $user, array $data, array $facebookImport = []): array
    {
        $data['created_by'] = $user->getKey();
        $data['organizer_id'] = $organizer->getKey();
        $data['status'] = 'draft';
        $data['source'] = 'manual';
        if ($facebookImport !== []) {
            $data['source_metadata'] = ['facebook' => $facebookImport];
            $data['source_ref'] = 'facebook:'.$facebookImport['id'];
            $data['external_links'] = app(FacebookEventImport::class)->formData($facebookImport, 'UTC')['external_links'];
        }

        return $data;
    }

    /**
     * La creazione fatta dall'app: evento in bozza, tag, prima data
     * facoltativa. `$data` è già validato con le regole del modulo.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $facebookImport
     */
    public function create(Organizer $organizer, User $user, array $data, array $facebookImport = []): Event
    {
        Gate::forUser($user)->authorize('create', [Event::class, $organizer]);

        return DB::transaction(function () use ($organizer, $user, $data, $facebookImport): Event {
            $attributes = Arr::only($data, self::COLUMNS);
            if (isset($attributes['content_details']) && is_array($attributes['content_details'])) {
                $attributes['content_details'] = $this->withoutVenueDefaults(Venue::find($attributes['venue_id'] ?? null), $attributes['content_details']);
            }
            $attributes = $this->creationAttributes($organizer, $user, $attributes, $facebookImport);

            $event = new Event;
            $event->forceFill(Arr::only($attributes, [...self::COLUMNS, 'created_by', 'organizer_id', 'status', 'source', 'source_metadata', 'source_ref', 'external_links']));
            $event->source = EventSource::Manual;
            $event->status = EventStatus::Draft;
            $event->save();

            if (isset($data['tags']) && is_array($data['tags'])) {
                $event->tags()->sync(array_map('intval', $data['tags']));
            }
            $this->createFirstDate($event, $data['starts_at'] ?? null, $data['ends_at'] ?? null);

            return $event;
        });
    }

    /**
     * La prima data, se il modulo ne ha una: l'orario è quello della città
     * dell'evento (il pannello lo scrive così; l'app manda l'istante con lo
     * scarto, che vale da sé).
     */
    public function createFirstDate(Event $event, mixed $startsAt, mixed $endsAt): ?EventOccurrence
    {
        if (empty($startsAt)) {
            return null;
        }
        $timezone = $event->city->timezone;

        return $event->occurrences()->create([
            'starts_at' => CarbonImmutable::parse((string) $startsAt, $timezone)->utc(),
            'ends_at' => ! empty($endsAt) ? CarbonImmutable::parse((string) $endsAt, $timezone)->utc() : null,
        ]);
    }

    /**
     * I dati della modifica con lo stato `pending` se l'evento era
     * pubblicato, e se è successo.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function prepareUpdate(Event $event, Organizer $organizer, User $user, array $data): array
    {
        $this->guardPlace($event, $organizer, $data);

        if ($this->review->sendsToReview($event, $organizer, $user)) {
            $data['status'] = 'pending';

            return [$data, true];
        }

        return [$data, false];
    }

    /**
     * La modifica fatta dall'app: le colonne del modulo (senza luogo e senza
     * date, che in modifica il pannello non mostra), i tag e il ritorno in
     * revisione.
     *
     * @param  array<string, mixed>  $data  i dati validati (chiavi assenti: non si toccano)
     * @return bool vero se l'evento è tornato in revisione
     */
    public function update(Event $event, Organizer $organizer, User $user, array $data): bool
    {
        Gate::forUser($user)->authorize('update', $event);

        $attributes = Arr::only($data, array_diff(self::COLUMNS, ['custom_location']));
        if (array_key_exists('content_details', $attributes)) {
            $own = is_array($attributes['content_details']) ? $attributes['content_details'] : [];
            $merged = array_replace(is_array($event->getAttribute('content_details')) ? $event->getAttribute('content_details') : [], $own);
            $venue = Venue::find($attributes['venue_id'] ?? $event->venue_id);
            $attributes['content_details'] = $this->withoutVenueDefaults($venue, $merged);
        }
        [$attributes, $sent] = $this->prepareUpdate($event, $organizer, $user, $attributes);

        DB::transaction(function () use ($event, $attributes, $data): void {
            $event->fill($attributes)->save();
            if (array_key_exists('tags', $data) && is_array($data['tags'])) {
                $event->tags()->sync(array_map('intval', $data['tags']));
            }
        });

        return $sent;
    }

    /**
     * Rifiuta lo spostamento chiesto da un co-organizzatore. Il valore già
     * salvato passa: l'app può rimandare tutto il modulo, e il pannello lascia
     * il campo spento ma presente.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function guardPlace(Event $event, Organizer $organizer, array $data): void
    {
        if ($this->canMove($event, $organizer)) {
            return;
        }

        $errors = [];
        foreach (self::PLACE_COLUMNS as $column) {
            if (array_key_exists($column, $data) && (string) ($data[$column] ?? '') !== (string) ($event->getAttribute($column) ?? '')) {
                $errors[$column] = 'Città e locale principale li cambia solo l’organizzazione che ha creato l’evento.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * «Prima di andare»: i valori del locale principale si ereditano, non si
     * copiano (come `BeforeGoingFields` nel pannello).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function withoutVenueDefaults(?Venue $venue, array $details): array
    {
        $defaults = BeforeGoingDefaults::forVenue($venue);
        foreach (BeforeGoingDefaults::FIELDS as $field) {
            if (array_key_exists($field, $details)) {
                $details[$field] = BeforeGoingDefaults::override($field, $details[$field], $defaults[$field] ?? null);
            }
        }

        return $details;
    }
}
