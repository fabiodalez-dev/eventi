<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\ScheduleShortcut;
use App\Filament\Support\BeforeGoingFields;
use App\Filament\Support\PosterColumn;
use App\Filament\Support\SameNightNearbyField;
use App\Filament\Support\VenueClosureWarning;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\CurrentVenue;
use App\Filament\Venue\Support\EventFields;
use App\Filament\Venue\Support\EventPublication;
use App\Filament\Venue\Support\RecurrenceForm;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Events\VenueEventEditor;
use App\Services\Import\FacebookEventImport;
use App\Services\Import\FacebookImportThrottle;
use App\Support\VenueEventDefaults;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Il nuovo evento, con un obiettivo dichiarato: **90 secondi** (§2.4).
 *
 * Una pagina sola, non più cinque passi: in cima il blocco «L'essenziale» —
 * locandina, titolo, giorno e ora (con le scorciatoie e «Si ripete»),
 * categoria, prezzo, descrizione — che basta da solo per pubblicare. Sotto
 * l'anteprima della card come la vedrà il pubblico, e le sezioni facoltative
 * (import da Facebook, orario di fine, tag e link, un altro luogo, «Prima di
 * andare»), chiuse finché non servono.
 *
 * **La bozza.** «Salva come bozza» salva quello che c'è, anche senza data:
 * non è una comodità, è la differenza fra perdere il lavoro e non perderlo
 * quando arriva un cliente al bancone a metà modulo. La pubblicazione riusa
 * la stessa riga, e per questo `handleRecordCreation()` non crea nulla di
 * nuovo se una bozza c'è già.
 *
 * **`$draftId` è `#[Locked]`**, e non per eleganza: senza, il numero della
 * bozza viaggerebbe nello stato del componente e chiunque potrebbe
 * sostituirlo con l'id di un evento altrui, facendoselo riscrivere dal
 * proprio modulo. Il blocco è la prima difesa; la seconda è che la bozza
 * viene ricaricata filtrando sempre sul locale corrente.
 *
 * **Le scorciatoie della data** valgono i 90 secondi da sole: quattro
 * pulsanti al posto di un calendario. Propongono un valore per il campo data,
 * niente di più — la definizione di "stasera" resta di `EventOccurrenceQuery`
 * (§3 delle convenzioni).
 */
class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * La bozza salvata passo dopo passo. Bloccata: è un identificatore che il
     * client non deve poter cambiare.
     */
    #[Locked]
    public ?int $draftId = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $facebookImport = [];

    #[Locked]
    public int $facebookPhotoRevision = 0;

    private function sourceLabels(string $collection, string $field): string
    {
        $rows = $this->facebookImport[$collection] ?? [];
        if (! is_array($rows)) {
            return '';
        }
        $labels = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row[$field] ?? null)) {
                $labels[] = $row[$field];
            }
        }

        return implode(', ', $labels);
    }

    public function importFacebook(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('create', [Event::class, CurrentVenue::get()]), 403);
        // Gli stessi secchi del pannello dell'organizzazione e dell'app.
        $limit = app(FacebookImportThrottle::class)->attempt($user);
        if ($limit !== null) {
            Notification::make()->warning()->title(__('facebook_import.'.$limit))->send();

            return;
        }
        $import = app(FacebookEventImport::class);
        $photo = null;
        try {
            $source = $import->fetch((string) ($this->data['facebook_url'] ?? ''));
            $fields = $import->formData($source, CurrentVenue::timezone());
            $photo = null;
            $photoWarning = null;
            if (! empty($source['cover']['url'])) {
                try {
                    $photo = $import->photo($source['cover']['url']);
                } catch (\Throwable $error) {
                    report($error);
                    $photoWarning = __('facebook_import.photo_failed');
                }
            }
            $this->form->fill(array_replace($this->data ?? [], $fields));
            if ($photo !== null) {
                $this->data['poster_media'] = [(string) Str::uuid() => $photo];
                $this->facebookPhotoRevision++;
            }
            $this->facebookImport = $source;
            Notification::make()->success()->title(__('facebook_import.loaded'))
                ->body($photoWarning ?? __('facebook_import.review'))->send();
        } catch (\Throwable $error) {
            $photo?->delete();
            report($error);
            Notification::make()->danger()->title(__('facebook_import.failed'))
                ->body(__('facebook_import.failed_hint'))->send();
        }
    }

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
     * Il modulo. Le abitudini del locale non arrivano da un `fill()` in
     * `mount()` — che azzererebbe i valori predefiniti dichiarati dai campi —
     * ma dal `->default()` di ciascun campo, che è il posto in cui Filament
     * se li aspetta.
     */
    public function form(Schema $schema): Schema
    {
        $defaults = VenueEventDefaults::formDefaults(CurrentVenue::get());

        return $schema->columns(1)->components([
            Section::make(__('manage.quick.facebook'))
                ->description(__('manage.quick.facebook_hint'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                // In cima e aperta: per un locale incollare il link dell'evento Facebook è il modo più veloce di compilare tutto il resto.
                ->collapsible()
                ->schema([
                    TextInput::make('facebook_url')
                        ->label(__('facebook_import.url_label'))
                        ->placeholder('https://www.facebook.com/events/…')
                        ->helperText(__('facebook_import.url_hint'))
                        ->maxLength(2048)->dehydrated(false),
                    ActionsComponent::make([
                        Action::make('importFacebook')->label(__('facebook_import.load'))
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->action(fn () => $this->importFacebook()),
                    ]),
                    TextEntry::make('facebook_import_details')
                        ->label(__('facebook_import.source'))
                        ->state(fn (): string => collect([
                            $this->facebookImport['venue']['name'] ?? null,
                            $this->facebookImport['venue']['address'] ?? null,
                            $this->facebookImport['date_label'] ?? null,
                            $this->sourceLabels('hosts', 'name') !== '' ? __('facebook_import.hosts', ['names' => $this->sourceLabels('hosts', 'name')]) : null,
                            $this->sourceLabels('categories', 'label'),
                            isset($this->facebookImport['responded_count']) ? __('facebook_import.responses', ['count' => $this->facebookImport['responded_count']]) : null,
                        ])->filter()->implode(' · '))
                        ->visible(fn (): bool => $this->facebookImport !== []),
                    TextEntry::make('facebook_location_warning')
                        ->label(__('facebook_import.location_warning'))
                        ->state(fn (): string => __('facebook_import.location_warning_hint', ['address' => FacebookEventImport::locationAddress($this->facebookImport) ?? '']))
                        ->visible(fn (): bool => filled(FacebookEventImport::locationAddress($this->facebookImport)) && ($this->data['custom_location']['address'] ?? null) === FacebookEventImport::locationAddress($this->facebookImport)),
                ]),

            Section::make(__('manage.quick.essentials'))
                ->description(__('manage.quick.essentials_hint'))
                ->icon(Heroicon::OutlinedSparkles)
                ->schema([
                    EventFields::poster(previewTemporary: true)->key('imported-poster'),
                    EventFields::title()->live(onBlur: true),

                    ActionsComponent::make($this->shortcutActions())
                        ->label(__('manage.quick.when'))
                        ->fullWidth(),

                    EventFields::startsAt()->live(onBlur: true),
                    SameNightNearbyField::make(fn (Get $get): ?array => $this->sameNightContext($get)),
                    // Un evento con un indirizzo proprio non si tiene nel locale: le sue chiusure non contano.
                    VenueClosureWarning::make(fn (Get $get): array => ['venue' => filled($get('custom_location.address')) ? null : CurrentVenue::get(), 'start' => SameNightNearbyField::time($get('starts_at'), 'UTC')]),

                    // Le serate fisse sono la regola, non l'eccezione (§10.3): la ripetizione sta qui, non in fondo.
                    Toggle::make('repeat')
                        ->label(__('manage.recurrence.toggle'))
                        ->helperText(__('manage.recurrence.toggle_hint'))
                        ->live(),

                    Group::make(RecurrenceForm::fields('repeat_'))
                        ->visible(fn (Get $get): bool => (bool) $get('repeat')),

                    EventFields::category()->default($defaults['category_id'] ?? null)->live(),
                    EventResource::priceTypeInline()->default($defaults['price_type'] ?? PriceType::Free->value),
                    EventFields::priceMin()->default($defaults['price_min'] ?? null)->live(onBlur: true),
                    EventFields::priceMax()->live(onBlur: true),
                    EventFields::description(),
                ]),

            Section::make(__('manage.quick.preview'))
                ->description(__('manage.quick.preview_hint'))
                ->icon(Heroicon::OutlinedEye)
                ->schema([
                    View::make('filament.venue.widgets.event-card')
                        ->viewData(fn (Get $get): array => $this->previewData($get)),
                ]),

            Section::make(__('manage.quick.more'))
                ->description(__('manage.quick.more_hint'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->collapsed()
                ->schema([
                    EventFields::endsAt()->live(onBlur: true),
                    EventFields::tags(),
                    EventFields::ticketUrl(),
                    EventFields::bookingUrl(),
                    EventFields::externalLinks(),
                ]),

            EventResource::locationCollapsed(),

            BeforeGoingFields::make()->icon(Heroicon::OutlinedInformationCircle)->columns(1)->collapsed(),
        ]);
    }

    /**
     * I dati della card d'anteprima, presi dal modulo mentre lo si compila:
     * la stessa card dei riquadri del riepilogo.
     *
     * @return array<string, mixed>
     */
    private function previewData(Get $get): array
    {
        $timezone = CurrentVenue::timezone();
        $start = SameNightNearbyField::time($get('starts_at'), $timezone);
        $category = filled($get('category_id')) ? Category::query()->whereKey((int) $get('category_id'))->value('name') : null;
        $type = $get('price_type');
        $type = $type instanceof PriceType ? $type : PriceType::tryFrom((string) $type);
        $min = $get('price_min');
        $price = match (true) {
            $type === PriceType::Free => __('manage.quick.preview_free'),
            $type !== null && is_numeric($min) => $type->label().' · '.__('manage.event_view.price_from', ['min' => number_format((float) $min, 2, ',', '.')]),
            default => $type?->label(),
        };

        $when = ['heroicon-o-clock', $start === null ? __('manage.quick.preview_no_date') : $start->translatedFormat('D d/m/Y, H:i')];
        $where = ['heroicon-o-map-pin', filled($get('custom_location.address')) ? (string) ($get('custom_location.name') ?: $get('custom_location.address')) : CurrentVenue::get()->name];

        return [
            'poster' => $this->previewPoster($get('poster_media')),
            'title' => filled($get('title')) ? (string) $get('title') : __('manage.quick.preview_untitled'),
            'lines' => $price === null ? [$when, $where] : [$when, $where, ['heroicon-o-banknotes', $price]],
            'badges' => $category === null ? [] : [[(string) $category, 'gray']],
            'preview' => true,
        ];
    }

    /**
     * La locandina appena caricata, se il browser la può già mostrare; poi
     * quella salvata con la bozza; altrimenti il segnaposto.
     */
    private function previewPoster(mixed $state): string
    {
        foreach (is_array($state) ? $state : [] as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                try {
                    return $file->isPreviewable() ? $file->temporaryUrl() : PosterColumn::url(null);
                } catch (\Throwable) {
                    return PosterColumn::url(null);
                }
            }
        }

        $draft = $this->draft();

        return PosterColumn::url($draft);
    }

    /**
     * Il luogo è quello indicato a mano, se ha le coordinate, altrimenti il
     * locale. L'orario arriva dal selettore già in UTC.
     *
     * @return array<string, mixed>|null
     */
    private function sameNightContext(Get $get): ?array
    {
        $venue = CurrentVenue::get();

        return SameNightNearbyField::context($venue->city, [(array) $get('custom_location'), $venue], $get('starts_at'), $get('ends_at'), 'UTC', (int) $venue->id, $get('category_id'));
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('manage.wizard.finish'))
            ->icon(Heroicon::OutlinedRocketLaunch);
    }

    /**
     * Pubblica, salva come bozza, annulla: la bozza non chiede né la data né
     * la categoria, solo un titolo.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('saveDraftAction')
                ->label(__('manage.quick.save_draft'))
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('gray')
                ->action(fn () => $this->saveDraftNow()),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Il pulsante «Salva come bozza»: salva e lo dice, oppure spiega perché
     * non può (manca il titolo).
     */
    public function saveDraftNow(): void
    {
        if (blank($this->data['title'] ?? null)) {
            Notification::make()->warning()->title(__('manage.quick.draft_needs_title'))->send();

            return;
        }

        $this->saveDraft();

        if ($this->draftId !== null) {
            Notification::make()->success()->title(__('manage.quick.draft_saved'))->body(__('manage.quick.draft_saved_body'))->send();
        }
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

    /**
     * Dopo la pubblicazione si arriva sulla scheda dell'evento: da lì sono a
     * un tocco modifica, statistiche, grafiche social, sponsorizzazione e
     * biglietteria.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * Il salvataggio della bozza. È pubblico perché è il comportamento che i
     * test devono poter verificare direttamente: salvare due volte non deve
     * produrre due eventi.
     */
    public function saveDraft(): void
    {
        $data = is_array($this->data) ? $this->data : [];

        if (blank($data['title'] ?? null)) {
            return;
        }

        // `events.category_id` è obbligatoria nello schema, ma una bozza può
        // nascere prima che la categoria sia scelta: senza un ripiego non
        // sarebbe salvabile, e si perderebbe proprio il lavoro di chi ha
        // appena caricato la locandina e scritto il titolo. Il ripiego è la
        // categoria abituale del locale (o la prima del catalogo) e vive fino
        // alla pubblicazione, dove il campo è obbligatorio e la sovrascrive.
        if (blank($data['category_id'] ?? null)) {
            $data['category_id'] = $this->fallbackCategoryId();
        }

        if (blank($data['category_id'])) {
            return;
        }

        $this->persist($data);
    }

    /**
     * La categoria con cui tenere in piedi una bozza incompleta: la regola
     * sta in `VenueEventEditor`, la stessa che usa l'API di gestione.
     */
    private function fallbackCategoryId(): ?int
    {
        return app(VenueEventEditor::class)->fallbackCategoryId(CurrentVenue::get());
    }

    /**
     * Validazione, colonne e provenienza sono di `VenueEventEditor`: il
     * modulo e l'app salvano l'evento allo stesso modo.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data): Event
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $event = app(VenueEventEditor::class)->persist(CurrentVenue::get(), $user, $this->draft(), $data, $this->facebookImport);

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

    /** La prima data, scritta da `VenueEventEditor` come per l'app. */
    private function createOccurrence(Event $event): ?EventOccurrence
    {
        return app(VenueEventEditor::class)->createFirstDate($event, $this->wizardData['starts_at'] ?? null, $this->wizardData['ends_at'] ?? null);
    }

    /**
     * Il locale impara le proprie abitudini: il prossimo evento parte già
     * compilato (§10.2).
     */
    private function rememberDefaults(Event $event, ?EventOccurrence $occurrence): void
    {
        app(VenueEventEditor::class)->rememberDefaults(CurrentVenue::get(), $event, $occurrence);
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
