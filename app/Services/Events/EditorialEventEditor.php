<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\SeoIndexing;
use App\Enums\VerificationStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\User;
use App\Models\Venue;
use App\Rules\ExternalLinks;
use App\Rules\FactRows;
use App\Support\BeforeGoingDefaults;
use App\Support\CurrentCity;
use App\Support\EditorContent;
use App\Support\VideoEmbed;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Il modulo evento della redazione (`/admin/events/create` e `…/edit`,
 * `EventResource::form`) per l'app: `POST` e `PATCH E/events`.
 *
 * Il pannello disegna i campi con i componenti di Filament e li salva con
 * `CreateRecord`/`EditRecord`; qui ci sono le stesse regole scritte come
 * regole di validazione (l'app non passa da Livewire) e lo stesso
 * salvataggio. Quello che i due hanno in comune sta qui una volta sola:
 *
 * - l'autore della scheda è chi la crea (`prepareCreate`, chiamato anche da
 *   `CreateEvent::mutateFormDataBeforeCreate`);
 * - «Prima di andare» eredita dal locale: i valori uguali a quelli del locale
 *   non si copiano sull'evento (`VenueEventEditor::withoutVenueDefaults`, la
 *   regola di `BeforeGoingFields`), e cambiando locale si ricalcolano
 *   (`rebaseBeforeGoing`, come `afterStateUpdated` del campo «Locale»);
 * - l'evidenza si scrive solo con `EventPolicy::feature`, indicizzazione e
 *   contenuto dimostrativo solo da admin e super admin (i campi del pannello
 *   sono spenti e non si salvano per gli altri);
 * - alla creazione le date arrivano insieme alla scheda (il ripetitore
 *   «Date»); in modifica si governano dall'elenco delle date
 *   (`EditorialEventDates`), come nel pannello.
 *
 * Il `PATCH` salva solo le chiavi ricevute: l'app manda il modulo intero, ma
 * una sezione alla volta funziona allo stesso modo.
 */
final class EditorialEventEditor
{
    /** Le colonne dell'evento che il modulo scrive così come arrivano. */
    public const COLUMNS = [
        'status', 'title', 'subtitle', 'slug', 'short_description', 'description', 'category_id', 'age_restriction',
        'language', 'video_url', 'city_id', 'venue_id', 'custom_location', 'is_outdoor', 'organizer_id', 'organizer_name',
        'organizer_url', 'price_type', 'price_min', 'price_max', 'price_notes', 'currency', 'ticket_url', 'booking_required',
        'booking_url', 'booking_phone', 'facts', 'external_links', 'source', 'verification_status', 'editorial_score',
        'rejection_reason',
    ];

    /** I tipi di prezzo che mostrano importi, valuta e listino (`EventResource::form`). */
    public const AMOUNT_TYPES = [PriceType::Ticket->value, PriceType::Membership->value];

    /** I campi di «Prima di andare» che il modulo mostra con i valori del locale (`BeforeGoingFields::make()`). */
    public const BEFORE_GOING = [...BeforeGoingDefaults::FIELDS, 'food_notes', 'start_notes'];

    /** Il tetto del ripetitore delle date alla creazione: lo stesso dell'elenco delle date. */
    public const MAX_NEW_DATES = 100;

    public function __construct(private readonly VenueEventEditor $venueEditor) {}

    /**
     * L'autore non è un campo del modulo: è chi ha premuto il pulsante.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareCreate(array $data, ?int $userId): array
    {
        $data['created_by'] = $userId;

        return $data;
    }

    /**
     * Le regole dei campi, una per componente del modulo del pannello. In
     * creazione sono obbligatori gli stessi del pannello (stato, titolo,
     * categoria, città, tipo di prezzo, provenienza, verifica e almeno una
     * data); in modifica ogni chiave è facoltativa ma, se c'è, vale la stessa
     * regola.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public function rules(array $input, ?Event $event): array
    {
        $creating = $event === null;
        $presence = $creating ? 'required' : 'sometimes';
        $cityId = $input['city_id'] ?? $event?->city_id;
        $priceType = $input['price_type'] ?? $event?->price_type?->value;
        $priceType = $priceType instanceof PriceType ? $priceType->value : $priceType;
        $amount = in_array($priceType, self::AMOUNT_TYPES, true);

        $rules = [
            'status' => [$presence, 'required', Rule::enum(EventStatus::class)],
            'title' => [$presence, 'required', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'category_id' => [$presence, 'required', 'integer', Rule::exists('categories', 'id')],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['integer', 'distinct', Rule::exists('tags', 'id')],
            'age_restriction' => ['sometimes', 'nullable', 'string', 'max:40'],
            'language' => ['sometimes', 'nullable', 'string', 'max:5'],
            'video_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048', self::videoRule()],
            'city_id' => [$presence, 'required', 'integer', Rule::exists('cities', 'id')],
            // Il campo «Locale» del pannello elenca i locali della città scelta.
            'venue_id' => ['sometimes', 'nullable', 'integer', Rule::exists('venues', 'id')->where('city_id', is_numeric($cityId) ? (int) $cityId : 0)],
            'custom_location' => ['sometimes', 'nullable', 'array:name,address,lat,lng'],
            'custom_location.name' => ['nullable', 'string', 'max:255'],
            'custom_location.address' => ['nullable', 'string', 'max:255'],
            'custom_location.lat' => ['nullable', 'numeric'],
            'custom_location.lng' => ['nullable', 'numeric'],
            'is_outdoor' => ['sometimes', 'boolean'],
            'organizer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('organizers', 'id')->where('is_active', true)],
            'organizers' => ['sometimes', 'nullable', 'array', 'max:50'],
            'organizers.*' => ['integer', 'distinct', Rule::exists('organizers', 'id')->where('is_active', true)],
            'organizer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'organizer_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'price_type' => [$presence, 'required', Rule::enum(PriceType::class)],
            'price_min' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'price_max' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'price_notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            // La valuta compare (ed è obbligatoria) solo con biglietto o tessera.
            'currency' => $amount ? [$presence, 'required', 'string', 'max:3'] : ['sometimes', 'nullable', 'string', 'max:3'],
            'ticket_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'booking_required' => ['sometimes', 'boolean'],
            'booking_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'booking_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'facts' => ['sometimes', 'nullable', 'array', new FactRows],
            'external_links' => ['sometimes', 'nullable', 'array', new ExternalLinks],
            'seo' => ['sometimes', 'nullable', 'array:title,description,image,indexing'],
            'seo.title' => ['nullable', 'string', 'max:180'],
            'seo.description' => ['nullable', 'string', 'max:400'],
            'seo.image' => ['nullable', 'string', 'url', 'max:2048'],
            'seo.indexing' => ['nullable', Rule::enum(SeoIndexing::class)],
            'is_demo' => ['sometimes', 'boolean'],
            'source' => [$presence, 'required', Rule::enum(EventSource::class)],
            'verification_status' => [$presence, 'required', Rule::enum(VerificationStatus::class)],
            'editorial_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'is_featured' => ['sometimes', 'boolean'],
            'featured_until' => ['sometimes', 'nullable', 'date'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'recurrences' => ['sometimes', 'nullable', 'array', 'max:20'],
            'recurrences.*' => ['array:id,rrule,until,exdates'],
            'recurrences.*.id' => ['nullable', 'integer'],
            'recurrences.*.rrule' => ['required', 'string', 'max:500'],
            'recurrences.*.until' => ['nullable', 'date'],
            'recurrences.*.exdates' => ['nullable', 'array', 'max:366'],
            'recurrences.*.exdates.*' => ['string', 'max:64'],
            ...EventFormRules::beforeGoing('', onlyBeforeGoing: false),
            ...EventFormRules::editorial($event),
        ];
        // Il listino dell'evento: le regole di `TicketTiersField` (le stesse del modulo del locale).
        foreach (EventFormRules::venueEditOnly() as $key => $rule) {
            if (str_starts_with($key, 'ticket_tiers')) {
                $rules[$key] = $key === 'ticket_tiers' ? ['sometimes', ...$rule] : $rule;
            }
        }
        if ($creating) {
            // Il ripetitore «Date» della creazione: almeno una, con l'inizio.
            $rules += [
                'occurrences' => ['required', 'array', 'min:1', 'max:'.self::MAX_NEW_DATES],
                'occurrences.*' => ['array:starts_at,ends_at,doors_at,is_all_day'],
                'occurrences.*.starts_at' => ['required', 'date'],
                'occurrences.*.ends_at' => ['nullable', 'date', 'after:occurrences.*.starts_at'],
                'occurrences.*.doors_at' => ['nullable', 'date'],
                'occurrences.*.is_all_day' => ['nullable', 'boolean'],
            ];
        }

        return $rules;
    }

    /**
     * Crea l'evento con le sue date (`CreateEvent`): stessa convalida, stesso
     * autore, stessa eredità dal locale.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor): Event
    {
        Gate::forUser($actor)->authorize('create', Event::class);
        $data = $this->validate($input, null);
        $data = self::prepareCreate($data, (int) $actor->getKey());

        return DB::transaction(function () use ($data, $actor): Event {
            $event = new Event;
            $event->created_by = $data['created_by'];
            $this->fill($event, $data, $actor);
            $event->save();
            $this->saveRelations($event, $data);
            $city = $event->city;
            foreach ($data['occurrences'] as $row) {
                $date = new EventOccurrence([
                    'event_id' => $event->getKey(),
                    'starts_at' => self::instant($row['starts_at'], $city),
                    'ends_at' => filled($row['ends_at'] ?? null) ? self::instant($row['ends_at'], $city) : null,
                    'doors_at' => filled($row['doors_at'] ?? null) ? self::instant($row['doors_at'], $city) : null,
                    'is_all_day' => (bool) ($row['is_all_day'] ?? false),
                    'status' => OccurrenceStatus::Scheduled,
                ]);
                $date->setRelation('event', $event);
                $date->save();
            }

            return $event->refresh();
        });
    }

    /**
     * Le chiavi ricevute (`EditEvent`). Le date non passano di qui.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Event $event, array $input, User $actor): Event
    {
        Gate::forUser($actor)->authorize('update', $event);
        unset($input['occurrences']);
        $data = $this->validate($input, $event);

        return DB::transaction(function () use ($event, $data, $actor): Event {
            $this->fill($event, $data, $actor);
            $event->save();
            $this->saveRelations($event, $data);

            return $event->refresh();
        });
    }

    /**
     * «Prima di andare» quando cambia il locale, come il campo «Locale» del
     * pannello: si tolgono i valori ereditati dal locale di prima e si
     * aggiungono quelli del nuovo, tenendo quelli propri dell'evento.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function rebaseBeforeGoing(array $details, ?Venue $previous, ?Venue $next): array
    {
        $before = BeforeGoingDefaults::forVenue($previous);
        $after = BeforeGoingDefaults::forVenue($next);
        foreach (BeforeGoingDefaults::FIELDS as $field) {
            $own = BeforeGoingDefaults::override($field, $details[$field] ?? null, $before[$field] ?? null);
            $merged = BeforeGoingDefaults::merge($after, [$field => $own]);
            $details[$field] = $merged[$field] ?? null;
        }

        return $details;
    }

    /**
     * Le informazioni «Prima di andare» come le mostra il modulo: quelle
     * dell'evento sopra quelle del locale (`BeforeGoingFields::afterStateHydrated`).
     *
     * @return array<string, mixed>
     */
    public function details(Event $event): array
    {
        $details = is_array($event->getAttribute('content_details')) ? $event->getAttribute('content_details') : [];
        $defaults = BeforeGoingDefaults::forVenue($event->venue);
        foreach ([...BeforeGoingDefaults::FIELDS, 'food_notes', 'start_notes'] as $field) {
            $merged = BeforeGoingDefaults::merge($defaults, [$field => $details[$field] ?? null]);
            $details[$field] = $merged[$field] ?? null;
        }

        return $details;
    }

    /**
     * La città predefinita di un evento nuovo: quella di lavoro, altrimenti
     * la prima (`EventResource::defaultCityId`).
     */
    public static function defaultCity(): ?City
    {
        return app(CurrentCity::class)->get() ?? City::query()->orderBy('id')->first();
    }

    /**
     * Un orario del modulo: con lo scarto vale da sé, senza è l'ora della
     * città dell'evento (i campi del pannello hanno il fuso della città).
     * Si salva nel fuso dell'applicazione, come fa il modello.
     */
    public static function instant(mixed $value, ?City $city): Carbon
    {
        $timezone = $city->timezone ?? config()->string('app.timezone');

        return Carbon::instance(CarbonImmutable::parse((string) $value, $timezone)->setTimezone(config()->string('app.timezone')));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input, ?Event $event): array
    {
        $input = self::withoutHiddenFields($input, $event);
        // Il testo formattato si pulisce prima di contarne la lunghezza, come il salvataggio del modello.
        if (array_key_exists('description', $input)) {
            $input['description'] = EditorContent::clean('description', $input['description']);
        }
        if (array_key_exists('content_details', $input)) {
            $input['content_details'] = EditorContent::clean('content_details', $input['content_details']);
        }

        $validator = Validator::make($input, $this->rules($input, $event), [], self::attributes());

        /** @var array<string, mixed> $data */
        $data = $validator->validate();

        return $data;
    }

    /**
     * Toglie i campi che il modulo del pannello nasconde, e che quindi non
     * convalida e non salva: importi, valuta e listino senza biglietto o
     * tessera; link e telefono di prenotazione senza «Prenotazione
     * obbligatoria»; il luogo libero quando c'è un locale; la scadenza
     * dell'evidenza senza evidenza. Lo stato che decide è quello che arriva,
     * altrimenti quello salvato.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function withoutHiddenFields(array $input, ?Event $event): array
    {
        $value = fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : $event?->getAttribute($key);
        $priceType = $value('price_type');
        $priceType = $priceType instanceof PriceType ? $priceType->value : $priceType;
        $hidden = [];
        if (! in_array($priceType, self::AMOUNT_TYPES, true)) {
            $hidden = [...$hidden, 'price_min', 'price_max', 'currency', 'ticket_tiers'];
        }
        if (! filter_var($value('booking_required'), FILTER_VALIDATE_BOOLEAN)) {
            $hidden = [...$hidden, 'booking_url', 'booking_phone'];
        }
        if (filled($value('venue_id'))) {
            $hidden[] = 'custom_location';
        }
        if (! filter_var($value('is_featured'), FILTER_VALIDATE_BOOLEAN)) {
            $hidden[] = 'featured_until';
        }

        return Arr::except($input, $hidden);
    }

    /**
     * I campi sul modello. L'evidenza solo con `EventPolicy::feature`,
     * indicizzazione e contenuto dimostrativo solo da admin e super admin.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Event $event, array $data, User $actor): void
    {
        foreach (self::COLUMNS as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column];
            if (in_array($column, ['is_outdoor', 'booking_required'], true)) {
                $value = (bool) $value;
            } elseif ($column === 'editorial_score') {
                $value = $value === null || $value === '' ? 0 : $value;
            } elseif ($column === 'custom_location') {
                $value = self::location($value);
            } elseif ($value === '') {
                $value = null;
            }
            $event->setAttribute($column, $value);
        }

        $gate = Gate::forUser($actor);
        if ($gate->allows('feature', Event::class)) {
            if (array_key_exists('is_featured', $data)) {
                $event->is_featured = (bool) $data['is_featured'];
            }
            if (array_key_exists('featured_until', $data)) {
                $event->featured_until = filled($data['featured_until']) ? self::instant($data['featured_until'], null) : null;
            }
        }

        $admin = $actor->hasAnyRole(['admin', 'super_admin']);
        if (array_key_exists('seo', $data)) {
            $current = is_array($event->getAttribute('seo')) ? $event->getAttribute('seo') : [];
            $seo = is_array($data['seo']) ? Arr::only($data['seo'], ['title', 'description', 'image']) : [];
            if ($admin && is_array($data['seo']) && array_key_exists('indexing', $data['seo'])) {
                $seo['indexing'] = $data['seo']['indexing'] ?? SeoIndexing::Automatic->value;
            }
            $event->setAttribute('seo', [...$current, ...$seo]);
        }
        if ($admin && array_key_exists('is_demo', $data)) {
            $event->setAttribute('is_demo', (bool) $data['is_demo']);
        }

        if (array_key_exists('content_details', $data) || array_key_exists('venue_id', $data)) {
            $current = is_array($event->getAttribute('content_details')) ? $event->getAttribute('content_details') : [];
            $own = array_key_exists('content_details', $data) && is_array($data['content_details']) ? $data['content_details'] : [];
            $details = array_replace($current, $own);
            $venue = $event->venue_id === null ? null : Venue::query()->find($event->venue_id);
            $event->setAttribute('content_details', $this->withoutVenueDefaults($venue, $details));
        }
    }

    /**
     * I valori uguali a quelli del locale non si copiano sull'evento, lo
     * ereditano: tutti i campi di `BeforeGoingFields` (anche cibo e note
     * sull'inizio, che il modulo della redazione mostra già compilati).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function withoutVenueDefaults(?Venue $venue, array $details): array
    {
        $defaults = BeforeGoingDefaults::forVenue($venue);
        foreach (self::BEFORE_GOING as $field) {
            if (array_key_exists($field, $details)) {
                $details[$field] = BeforeGoingDefaults::override($field, $details[$field], $defaults[$field] ?? null);
            }
        }

        return $details;
    }

    /**
     * Tag, co-organizzatori, listino e ricorrenze: le relazioni che il
     * pannello salva con i suoi campi `relationship()`.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveRelations(Event $event, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            $event->tags()->sync(array_map('intval', is_array($data['tags']) ? $data['tags'] : []));
        }
        if (array_key_exists('organizers', $data)) {
            $event->organizers()->sync(array_map('intval', is_array($data['organizers']) ? $data['organizers'] : []));
        }
        if (array_key_exists('ticket_tiers', $data)) {
            $this->venueEditor->syncTicketTiers($event, is_array($data['ticket_tiers']) ? array_values($data['ticket_tiers']) : []);
        }
        if (array_key_exists('recurrences', $data)) {
            $this->syncRecurrences($event, is_array($data['recurrences']) ? array_values($data['recurrences']) : []);
        }
    }

    /**
     * Il ripetitore «Ricorrenze»: le righe con `id` si aggiornano, le nuove
     * si creano, quelle sparite si tolgono. «Fino al» è nell'ora della città;
     * le date escluse restano un elenco di testi, come le scrive il pannello
     * una per riga.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncRecurrences(Event $event, array $rows): void
    {
        $existing = $event->recurrences()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $row) {
            $exdates = array_values(array_filter(array_map(fn (mixed $date): string => trim((string) $date), is_array($row['exdates'] ?? null) ? $row['exdates'] : [])));
            $values = [
                'rrule' => (string) $row['rrule'],
                'until' => filled($row['until'] ?? null) ? self::instant($row['until'], $event->city) : null,
                'exdates' => $exdates === [] ? null : $exdates,
            ];
            $recurrence = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($recurrence instanceof EventRecurrence) {
                $recurrence->fill($values)->save();
                $kept[] = (int) $recurrence->getKey();

                continue;
            }
            $kept[] = (int) $event->recurrences()->create($values)->getKey();
        }
        $existing->reject(fn (EventRecurrence $recurrence): bool => in_array((int) $recurrence->getKey(), $kept, true))
            ->each(fn (EventRecurrence $recurrence) => $recurrence->delete());
    }

    /**
     * Il luogo che non è un locale: `null` se non c'è niente, le coordinate
     * come numeri.
     *
     * @return array<string, mixed>|null
     */
    private static function location(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $place = [
            'name' => filled($value['name'] ?? null) ? (string) $value['name'] : null,
            'address' => filled($value['address'] ?? null) ? (string) $value['address'] : null,
            'lat' => is_numeric($value['lat'] ?? null) ? (float) $value['lat'] : null,
            'lng' => is_numeric($value['lng'] ?? null) ? (float) $value['lng'] : null,
        ];

        return array_filter($place, fn (mixed $item): bool => $item !== null) === [] ? null : $place;
    }

    /**
     * «Video» accetta solo un indirizzo che il sito sa incorporare.
     */
    private static function videoRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && VideoEmbed::from((string) $value) === null) {
                $fail(__('admin.hints.video_url_invalid'));
            }
        };
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $names = [];
        foreach ([
            'status', 'title', 'subtitle', 'slug', 'short_description', 'description', 'age_restriction', 'language', 'video_url',
            'is_outdoor', 'organizer_name', 'organizer_url', 'price_type', 'price_min', 'price_max', 'price_notes', 'currency',
            'booking_required', 'booking_url', 'booking_phone', 'source', 'verification_status', 'editorial_score', 'is_featured',
            'featured_until', 'rejection_reason', 'rrule', 'until',
        ] as $field) {
            $names[$field] = (string) __('admin.fields.'.$field);
        }
        $names['category_id'] = (string) __('admin.fields.category');
        $names['city_id'] = (string) __('admin.fields.city');
        $names['venue_id'] = (string) __('admin.fields.venue');
        $names['ticket_url'] = 'Acquisto biglietti su sito esterno';
        $names['tags'] = (string) __('admin.fields.tags');
        $names['organizer_id'] = 'Organizzatore registrato';
        $names['organizers'] = (string) __('admin.fields.co_organizers');
        $names['occurrences'] = (string) __('admin.resources.occurrence.plural');
        $names['occurrences.*.starts_at'] = (string) __('admin.fields.starts_at');
        $names['occurrences.*.ends_at'] = (string) __('admin.fields.ends_at');
        $names['occurrences.*.doors_at'] = (string) __('admin.fields.doors_at');
        $names['recurrences.*.rrule'] = (string) __('admin.fields.rrule');
        $names['recurrences.*.until'] = (string) __('admin.fields.until');

        return $names;
    }
}
