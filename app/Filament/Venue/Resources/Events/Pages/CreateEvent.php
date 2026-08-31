<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\ScheduleShortcut;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\CurrentVenue;
use App\Filament\Venue\Support\EventFields;
use App\Filament\Venue\Support\EventPublication;
use App\Filament\Venue\Support\RecurrenceForm;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Support\VenueEventDefaults;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Il wizard di §10.2, con un obiettivo dichiarato: **90 secondi** (§2.4).
 *
 * Cinque passi, nell'ordine in cui un gestore ha le informazioni sotto mano:
 * la locandina (che di solito è già nel telefono), quando, che genere di
 * serata è, quanto costa, e — solo alla fine, perché è l'unico passo che si
 * può saltare — la descrizione.
 *
 * **La bozza si salva da sola a ogni passo.** Non è una comodità: è la
 * differenza fra perdere il lavoro e non perderlo quando arriva un cliente al
 * bancone a metà del terzo passo. L'evento nasce alla fine del primo passo e
 * viene aggiornato dagli altri; l'ultimo salvataggio riusa la stessa riga, e
 * per questo `handleRecordCreation()` non crea nulla di nuovo se una bozza
 * c'è già.
 *
 * **`$draftId` è `#[Locked]`**, e non per eleganza: senza, il numero della
 * bozza viaggerebbe nello stato del componente e chiunque potrebbe
 * sostituirlo con l'id di un evento altrui, facendoselo riscrivere dal
 * proprio wizard. Il blocco è la prima difesa; la seconda è che la bozza
 * viene ricaricata filtrando sempre sul locale corrente.
 *
 * **Le scorciatoie del secondo passo** valgono i 90 secondi da sole: quattro
 * pulsanti al posto di un calendario. Propongono un valore per il campo data,
 * niente di più — la definizione di "stasera" resta di `EventOccurrenceQuery`
 * (§3 delle convenzioni).
 */
class CreateEvent extends CreateRecord
{
    use HasWizard;

    protected static string $resource = EventResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * La bozza salvata passo dopo passo. Bloccata: è un identificatore che il
     * client non deve poter cambiare.
     */
    #[Locked]
    public ?int $draftId = null;

    /**
     * Le colonne dell'evento che il wizard scrive. Tutto il resto — verifica,
     * evidenza, punteggio, provenienza — è della redazione.
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'title',
        'description',
        'category_id',
        'price_type',
        'price_min',
        'price_max',
        'ticket_url',
        'booking_url',
        'external_links',
    ];

    /**
     * I dati validati dell'ultimo passo, che servono anche dopo il
     * salvataggio: le date e la ripetizione non sono colonne dell'evento.
     *
     * @var array<string, mixed>
     */
    protected array $wizardData = [];

    public function getTitle(): string
    {
        return __('manage.wizard.title');
    }

    /**
     * I passi. Le abitudini del locale non arrivano da un `fill()` in
     * `mount()` — che azzererebbe i valori predefiniti dichiarati dai campi —
     * ma dal `->default()` di ciascun campo, che è il posto in cui Filament
     * se li aspetta.
     *
     * @return array<int, Step>
     */
    public function getSteps(): array
    {
        $defaults = VenueEventDefaults::formDefaults(CurrentVenue::get());

        return [
            Step::make(__('manage.wizard.step_poster'))
                ->description(__('manage.wizard.step_poster_hint'))
                ->icon(Heroicon::OutlinedPhoto)
                ->schema([
                    EventFields::poster(),
                    EventFields::title(),
                ])
                ->afterValidation(fn () => $this->saveDraft()),

            Step::make(__('manage.wizard.step_when'))
                ->description(__('manage.wizard.step_when_hint'))
                ->icon(Heroicon::OutlinedClock)
                ->schema([
                    ActionsComponent::make($this->shortcutActions())
                        ->label(__('manage.wizard.shortcuts'))
                        ->fullWidth(),

                    EventFields::startsAt(),
                    EventFields::endsAt(),

                    Toggle::make('repeat')
                        ->label(__('manage.recurrence.toggle'))
                        ->helperText(__('manage.recurrence.toggle_hint'))
                        ->live(),

                    Group::make(RecurrenceForm::fields('repeat_'))
                        ->visible(fn (Get $get): bool => (bool) $get('repeat')),
                ])
                ->afterValidation(fn () => $this->saveDraft()),

            Step::make(__('manage.wizard.step_category'))
                ->description(__('manage.wizard.step_category_hint'))
                ->icon(Heroicon::OutlinedTag)
                ->schema([
                    EventFields::category()->default($defaults['category_id'] ?? null),
                    EventFields::tags(),
                ])
                ->afterValidation(fn () => $this->saveDraft()),

            Step::make(__('manage.wizard.step_price'))
                ->description(__('manage.wizard.step_price_hint'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema([
                    EventFields::priceType()->default($defaults['price_type'] ?? PriceType::Free->value),
                    EventFields::priceMin()->default($defaults['price_min'] ?? null),
                    EventFields::priceMax(),
                ])
                ->afterValidation(fn () => $this->saveDraft()),

            Step::make(__('manage.wizard.step_details'))
                ->description(__('manage.wizard.step_details_hint'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    EventFields::description(),
                    EventFields::ticketUrl(),
                    EventFields::bookingUrl(),
                    EventFields::externalLinks(),
                ]),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('manage.wizard.finish'))
            ->icon(Heroicon::OutlinedRocketLaunch);
    }

    /**
     * L'ultimo salvataggio non crea una riga nuova: aggiorna la bozza che i
     * passi precedenti hanno già scritto.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $this->wizardData = $data;

        return $this->persist($data);
    }

    protected function afterCreate(): void
    {
        $event = $this->getRecord();

        if (! $event instanceof Event) {
            return;
        }

        $occurrence = $this->createOccurrence($event);

        if (($this->wizardData['repeat'] ?? false) === true) {
            RecurrenceForm::apply($event, $this->wizardData, 'repeat_');
        }

        $this->rememberDefaults($event, $occurrence);

        EventPublication::notify(EventPublication::submit($event));
    }

    protected function getCreatedNotification(): ?Notification
    {
        // Il messaggio lo manda `EventPublication`, che sa se l'evento è
        // finito online o in coda alla redazione: un "creato" generico
        // nasconderebbe proprio la differenza che interessa al gestore.
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * Salvataggio automatico di fine passo. È pubblico perché è ciò che
     * chiama il wizard alla fine di ogni passo, e perché è il comportamento
     * che i test devono poter verificare direttamente: salvare due volte non
     * deve produrre due eventi.
     */
    public function saveDraft(): void
    {
        $data = is_array($this->data) ? $this->data : [];

        if (blank($data['title'] ?? null)) {
            return;
        }

        // `events.category_id` è obbligatoria nello schema, ma la categoria
        // si chiede al terzo passo: senza un ripiego la bozza dei primi due
        // passi non sarebbe salvabile, e si perderebbe proprio il lavoro di
        // chi ha appena caricato la locandina e scritto il titolo. Il ripiego
        // è la categoria abituale del locale (o la prima del catalogo) e vive
        // il tempo di arrivare al terzo passo, dove il campo è obbligatorio e
        // la sovrascrive.
        if (blank($data['category_id'] ?? null)) {
            $data['category_id'] = $this->fallbackCategoryId();
        }

        if (blank($data['category_id'])) {
            return;
        }

        $this->persist($data);
    }

    /**
     * La categoria con cui tenere in piedi una bozza incompleta: quella
     * abituale del locale, altrimenti la prima del catalogo.
     */
    private function fallbackCategoryId(): ?int
    {
        $habit = VenueEventDefaults::formDefaults(CurrentVenue::get())['category_id'] ?? null;

        if ($habit !== null) {
            return (int) $habit;
        }

        $first = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');

        return $first === null ? null : (int) $first;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data): Event
    {
        $venue = CurrentVenue::get();
        $event = $this->draft() ?? new Event;

        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $event->{$column} = $data[$column] === '' ? null : $data[$column];
            }
        }

        if (! $event->exists) {
            $event->city_id = $venue->city_id;
            $event->venue_id = $venue->getKey();
            $event->created_by = auth()->id();
            $event->source = EventSource::Venue;
            $event->status = EventStatus::Draft;
        }

        $event->save();

        $this->draftId = (int) $event->getKey();
        $this->record = $event;

        return $event;
    }

    /**
     * La bozza si ricarica **sempre** filtrando sul locale corrente: è la
     * seconda serratura dopo `#[Locked]`.
     */
    private function draft(): ?Event
    {
        if ($this->draftId === null) {
            return null;
        }

        return Event::query()
            ->whereKey($this->draftId)
            ->where('venue_id', CurrentVenue::get()->getKey())
            ->first();
    }

    /**
     * La prima data. `business_date` ed `effective_ends_at` non si scrivono
     * qui: le calcola l'observer (§5 delle convenzioni).
     */
    private function createOccurrence(Event $event): ?EventOccurrence
    {
        $startsAt = $this->wizardData['starts_at'] ?? null;

        if (blank($startsAt)) {
            return null;
        }

        $endsAt = $this->wizardData['ends_at'] ?? null;

        $occurrence = new EventOccurrence([
            'event_id' => $event->getKey(),
            'starts_at' => Carbon::parse((string) $startsAt),
            'ends_at' => blank($endsAt) ? null : Carbon::parse((string) $endsAt),
            'is_all_day' => false,
            'status' => OccurrenceStatus::Scheduled,
            'is_exception' => false,
        ]);

        $occurrence->setRelation('event', $event);
        $occurrence->save();

        return $occurrence;
    }

    /**
     * Il locale impara le proprie abitudini: il prossimo evento parte già
     * compilato (§10.2).
     */
    private function rememberDefaults(Event $event, ?EventOccurrence $occurrence): void
    {
        $local = $occurrence === null
            ? null
            : CarbonImmutable::instance($occurrence->starts_at)->setTimezone(CurrentVenue::timezone());

        VenueEventDefaults::remember(
            CurrentVenue::get(),
            [
                'category_id' => $event->category_id,
                'price_type' => $event->price_type,
                'price_min' => $event->price_min,
            ],
            $local?->hour,
            $local?->minute,
        );
    }

    /**
     * @return array<int, Action>
     */
    private function shortcutActions(): array
    {
        $venue = CurrentVenue::get();
        $timezone = CurrentVenue::timezone();
        [$hour, $minute] = VenueEventDefaults::startTime($venue);

        $actions = [];

        foreach (ScheduleShortcut::cases() as $shortcut) {
            $actions[] = Action::make('shortcut_'.$shortcut->value)
                ->label($shortcut->label())
                ->color('gray')
                ->action(function (Set $set) use ($shortcut, $timezone, $hour, $minute): void {
                    $set('starts_at', $shortcut->startsAt(CarbonImmutable::now($timezone), $hour, $minute));

                    if (! $shortcut->repeats()) {
                        return;
                    }

                    $set('repeat', true);
                    $set('repeat_frequency', RecurrenceFrequency::Weekly->value);
                    $set('repeat_weekdays', [$shortcut->weekday()?->value]);
                });
        }

        return $actions;
    }
}
