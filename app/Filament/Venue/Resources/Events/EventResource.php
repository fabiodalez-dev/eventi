<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events;

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Filament\Organizer\Pages\EventAnalyticsDetail as OrganizerEventAnalyticsDetail;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Organizer\Resources\Events\EventResource as OrganizerEventResource;
use App\Filament\Support\BeforeGoingFields;
use App\Filament\Support\EditorialFields;
use App\Filament\Support\EventStatusPresentation;
use App\Filament\Support\EventTicketingActions;
use App\Filament\Support\PanelDate;
use App\Filament\Support\PosterColumn;
use App\Filament\Venue\Pages\EventAnalyticsDetail;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Pages\Sponsorships;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Filament\Venue\Support\CurrentVenue;
use App\Filament\Venue\Support\EventActions;
use App\Filament\Venue\Support\EventFields;
use App\Models\Booking;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Queries\VenueDashboardQuery;
use App\Services\Analytics\ManagementAnalytics;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Gli eventi del locale (§10).
 *
 * **Tenancy.** La risorsa è agganciata a `Venue` attraverso la relazione
 * `venue` dell'evento: Filament aggiunge da sé il filtro sul locale corrente a
 * ogni query e associa il locale a ogni riga creata. Non è però l'unica
 * difesa, e non è nemmeno la principale — `EventPolicy` verifica il
 * `venue_id` riga per riga, e nemmeno un ID scritto a mano nell'indirizzo
 * apre la scheda di un altro locale (§18 scenario F).
 *
 * **`canCreate()` è riscritto di proposito.** `EventPolicy::create()` accetta
 * il locale come secondo argomento e senza di esso risponde "solo staff
 * globale" (D24, punto 2). Filament chiama l'abilità con la sola classe, e la
 * risposta sarebbe no per chiunque gestisca un locale: qui la domanda viene
 * riformulata come va posta in questo pannello — *può creare eventi **per
 * questo locale**?*
 *
 * **La tabella è pensata per un pollice.** Tre informazioni per riga —
 * locandina, titolo con la prossima data, stato — impilate in verticale sul
 * telefono, affiancate sullo schermo grande. Niente colonne che costringano
 * la pagina a scorrere di lato.
 *
 * @extends resource<Event>
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $slug = 'eventi';

    protected static ?string $tenantOwnershipRelationshipName = 'venue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('manage.resources.event.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('manage.resources.event.plural');
    }

    /**
     * La voce di menu si scrive come si scrive in italiano. Senza questo
     * metodo Filament passa l'etichetta plurale per una funzione di
     * capitalizzazione pensata per l'inglese, e «I tuoi eventi» diventa
     * «I Tuoi Eventi».
     */
    public static function getNavigationLabel(): string
    {
        return __('manage.resources.event.plural');
    }

    /**
     * Il permesso di creare, riformulato come va posto in questo pannello.
     *
     * `EventPolicy::create()` accetta il locale come secondo argomento e senza
     * di esso risponde "solo staff globale" (D24, punto 2). Filament chiede
     * l'abilità con la sola classe, quindi la risposta sarebbe no per chiunque
     * gestisca un locale — e il pulsante "Nuovo evento" sparirebbe proprio a
     * chi il pannello è dedicato.
     *
     * Si riscrive **la risposta di autorizzazione**, non `canCreate()`: è da
     * questo metodo che passano tutti e due i controlli, quello del pulsante
     * (`CreateAction`) e quello della pagina (`CreateRecord::authorizeAccess()`).
     * Riscrivere solo `canCreate()` lascerebbe il pulsante invisibile e la
     * pagina raggiungibile — cioè il difetto peggiore dei due.
     */
    public static function getCreateAuthorizationResponse(): Response
    {
        return auth()->user()?->can('create', [Event::class, CurrentVenue::get()]) === true
            ? Response::allow()
            : Response::deny();
    }

    /**
     * La scheda di modifica, nello stesso ordine del modulo rapido di
     * creazione: in cima lo stato di pubblicazione e l'essenziale (locandina,
     * titolo, categoria, prezzo, descrizione), sotto le sezioni facoltative,
     * chiuse finché non servono. Le date stanno nella sezione «Date» sotto il
     * modulo (vedi `EditEvent`).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('manage.quick.publishing'))->schema([View::make('filament.events.publication-status')]),

                Section::make(__('manage.quick.essentials'))
                    ->description(__('manage.quick.edit_essentials_hint'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        EventFields::poster(),
                        EventFields::title(),
                        EventFields::category(),
                        self::priceTypeInline(),
                        EventFields::priceMin(),
                        EventFields::priceMax(),
                        EventFields::description(),
                    ]),

                /*
                 * Le fasce di prezzo (§ «Biglietti e fasce di prezzo»): lo
                 * stato è per fascia, non per serata. Il prezzo minimo che
                 * appare sulla card viene ricalcolato da queste righe, quindi
                 * qui non c'è niente da tenere allineato a mano.
                 */
                Section::make('Ticketing inCittà')
                    ->description(fn (Get $get): string => in_array($get('price_type'), ['free', PriceType::Free], true) ? 'Biglietti gratuiti: prenotazione e QR per l’ingresso.' : 'Prenotazione gratuita con pagamento al locale, all’ingresso.')
                    ->icon(Heroicon::OutlinedTicket)
                    ->collapsed()
                    ->visible(fn (Get $get): bool => in_array($get('price_type') instanceof PriceType ? $get('price_type')->value : $get('price_type'), ['free', 'ticket', 'membership'], true))->schema([View::make('filament.events.ticketing-links'), Actions::make(fn (?Event $record): array => EventTicketingActions::forEvent($record))]),

                Section::make(__('manage.quick.more'))
                    ->description(__('manage.quick.more_hint'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->collapsed()
                    ->schema([
                        EventFields::tags(),
                        EventFields::ticketUrl(),
                        EventFields::bookingUrl(),
                        EventFields::externalLinks(),
                    ]),

                self::locationCollapsed(),

                BeforeGoingFields::make()->icon(Heroicon::OutlinedInformationCircle)->columns(1)->collapsed(),

                Section::make(__('manage.sections.ticket_tiers'))
                    ->collapsed()
                    ->schema([
                        EventFields::ticketTiers(),
                    ]),

                Section::make(__('manage.sections.facts'))
                    ->collapsed()
                    ->schema([
                        EventFields::facts(),
                    ]),

                EditorialFields::content(true, false),
                EditorialFields::seo(),
            ]);
    }

    /**
     * Il tipo di prezzo come una riga di scelte che va a capo, non una
     * colonna di cinque pulsanti: lo stesso nel modulo rapido e in modifica.
     */
    public static function priceTypeInline(): Component
    {
        $field = EventFields::priceType();

        if ($field instanceof ToggleButtons) {
            $field->inline();
        }

        return $field;
    }

    /** «Luogo dell'evento», chiuso: quasi sempre vale la sede del locale. */
    public static function locationCollapsed(): Component
    {
        $section = EventFields::location();

        if ($section instanceof Section) {
            $section->icon(Heroicon::OutlinedMapPin)->collapsed();
        }

        return $section;
    }

    /**
     * Dove si può andare partendo da un evento: modifica, statistiche,
     * grafiche social, sponsorizzazione, biglietteria della prossima data e
     * scheda pubblica. Serve alla scheda in lettura, alla riga dell'elenco e
     * al riepilogo, nei due pannelli (`/gestione` e `/organizza`).
     *
     * Ogni voce compare solo se chi guarda può aprire la destinazione: la
     * pagina stessa ricontrolla, qui si evita di mostrare un pulsante che
     * finirebbe in un 403.
     *
     * @return list<array{key: string, label: string, icon: string, url: string, external: bool}>
     */
    public static function eventLinks(Event $event, bool $withEdit = true, bool $withView = false): array
    {
        $organizer = Filament::getCurrentPanel()?->getId() === 'organizer';
        $resource = $organizer ? OrganizerEventResource::class : static::class;
        $detail = $organizer ? OrganizerEventAnalyticsDetail::class : EventAnalyticsDetail::class;
        $sponsorships = $organizer ? OrganizerSponsorships::class : Sponsorships::class;
        $published = $event->status === EventStatus::Published;
        $links = [];

        if ($withView) {
            $links[] = self::link('view', 'heroicon-o-eye', $resource::getUrl('view', ['record' => $event]));
        }
        if ($withEdit && $resource::canEdit($event)) {
            $links[] = self::link('edit', 'heroicon-o-pencil-square', $resource::getUrl('edit', ['record' => $event]));
        }
        // Le statistiche di un evento solo co-organizzato sono di chi lo organizza (`ManagementAnalytics`).
        $tenant = Filament::getTenant();
        if ($detail::canAccess() && ($tenant instanceof Venue || $tenant instanceof Organizer) && ManagementAnalytics::covers($tenant, $event)) {
            $links[] = self::link('statistics', 'heroicon-o-chart-bar', $detail::getUrl(['subjectType' => 'event', 'subjectId' => $event->getKey()]));
        }
        if (! $organizer && Social::canAccess()) {
            $links[] = self::link('social', 'heroicon-o-photo', Social::getUrl(['event' => $event->getKey()]));
        }
        if ($published && $sponsorships::canAccess()) {
            $links[] = self::link('sponsor', 'heroicon-o-megaphone', $sponsorships::getUrl(['event' => $event->getKey()]));
        }
        $next = self::ticketDate($event);
        if ($next !== null) {
            $links[] = self::link('tickets', 'heroicon-o-ticket', route('ticketing.manage.show', $next));
        }
        if ($published && Route::has('events.show')) {
            $links[] = self::link('site', 'heroicon-o-arrow-top-right-on-square', route('events.show', $event), true);
        }

        return $links;
    }

    /**
     * La prossima data dell'evento, se chi guarda ne gestisce le prenotazioni
     * (`BookingPolicy::manage`). Il permesso si ricorda per locale e
     * organizzatore (`managesBookings()`): la riga dell'elenco lo chiede più
     * volte, e tutte le righe sono dello stesso locale.
     */
    public static function ticketDate(Event $event): ?EventOccurrence
    {
        $city = $event->relationLoaded('city') ? $event->city : City::query()->find($event->city_id);
        $next = $event->relationLoaded('occurrences')
            ? $event->occurrences->first()
            : ($city === null ? null : VenueDashboardQuery::nextOccurrence($event, $city));

        if (! $next instanceof EventOccurrence) {
            return null;
        }

        // Nei pannelli lo scope del tenant può nascondere l'evento alla relazione: lo si dà già pronto.
        $next->setRelation('event', $event);

        return self::managesBookings($next) ? $next : null;
    }

    /**
     * `BookingPolicy::manage` dipende solo da chi guarda, dall'organizzatore e
     * dal locale effettivo della data: la risposta si ricorda per la durata
     * della richiesta con questa chiave, così le righe dell'elenco (tutte
     * dello stesso locale) costano una sola verifica e non una per evento.
     */
    private static function managesBookings(EventOccurrence $date): bool
    {
        $memo = request()->attributes;
        $key = 'booking-manage:'.(auth()->id() ?? '-').':'.($date->event->organizer_id ?? '-').':'.($date->effectiveVenue()?->getKey() ?? '-');

        if (! $memo->has($key)) {
            $memo->set($key, Gate::allows('manage', [Booking::class, $date]));
        }

        return (bool) $memo->get($key);
    }

    /** @return array{key: string, label: string, icon: string, url: string, external: bool} */
    private static function link(string $key, string $icon, string $url, bool $external = false): array
    {
        return ['key' => $key, 'label' => __('manage.event_links.'.$key), 'icon' => $icon, 'url' => $url, 'external' => $external];
    }

    public static function table(Table $table): Table
    {
        // La prossima data la aggiunge già `getEloquentQuery()`, che serve
        // anche all'azione «tutto esaurito».
        return static::listTable($table, CurrentVenue::city(), withNextDate: false)
            ->recordActions([
                EditAction::make()->label(__('manage.actions.edit'))->button(),
                ActionGroup::make([
                    ViewAction::make()->label(__('analytics-dashboard.view_event')),
                    // Le pagine collegate all'evento, già filtrate su di lui.
                    Action::make('statistics')
                        ->label(__('manage.event_links.statistics'))
                        ->icon(Heroicon::OutlinedChartBar)
                        ->url(fn (Event $record): string => EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $record->getKey()]))
                        ->visible(fn (): bool => EventAnalyticsDetail::canAccess()),
                    Action::make('social')
                        ->label(__('manage.event_links.social'))
                        ->icon(Heroicon::OutlinedPhoto)
                        ->url(fn (Event $record): string => Social::getUrl(['event' => $record->getKey()]))
                        ->visible(fn (): bool => Social::canAccess()),
                    Action::make('sponsor')
                        ->label(__('manage.event_links.sponsor'))
                        ->icon(Heroicon::OutlinedMegaphone)
                        ->url(fn (Event $record): string => Sponsorships::getUrl(['event' => $record->getKey()]))
                        ->visible(fn (Event $record): bool => $record->status === EventStatus::Published && Sponsorships::canAccess()),
                    Action::make('tickets')
                        ->label(__('manage.event_links.tickets'))
                        ->icon(Heroicon::OutlinedTicket)
                        ->url(fn (Event $record): ?string => ($date = self::ticketDate($record)) === null ? null : route('ticketing.manage.show', $date))
                        ->visible(fn (Event $record): bool => self::ticketDate($record) !== null),
                    // Il gesto della sera stessa, a portata di pollice: segnare
                    // esaurita la prossima data senza aprire il modulo.
                    EventActions::soldOutNextDate(),
                    EventActions::viewOnSite(),
                    EventActions::duplicate(),
                ])->label(__('manage.event_list.more_actions'))->tooltip(__('manage.event_list.more_actions')),
            ]);
    }

    /**
     * L'elenco eventi come lo vedono i due pannelli, `/gestione` e
     * `/organizza`: una card per evento con la locandina (o il segnaposto),
     * il titolo, la prossima data — o l'ultima, con il distintivo «Passato»,
     * se l'evento è già andato — e lo stato scritto in italiano con il suo
     * colore. Gli eventi in arrivo vengono prima, in ordine di data; quelli
     * passati dopo, dal più recente. Le azioni le aggiunge ogni pannello.
     *
     * Sul telefono la card resta una riga sola, locandina a sinistra e testo a
     * destra: niente colonne che fanno scorrere la pagina di lato.
     */
    public static function listTable(Table $table, City $city, bool $showVenue = false, bool $withNextDate = true): Table
    {
        $timezone = $city->timezone;

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($city, $withNextDate): void {
                if ($withNextDate) {
                    VenueDashboardQuery::applyNextOccurrence($query, $city);
                }
                VenueDashboardQuery::applyLastOccurrence($query);
                $query->with(['media', 'venue']);
            })
            ->columns([
                Split::make([
                    PosterColumn::make('poster_media'),

                    Stack::make([
                        TextColumn::make('title')
                            ->label(__('manage.fields.title'))
                            ->searchable()
                            ->weight(FontWeight::Bold)
                            ->wrap(),

                        /* «sab 22 nov, 21:00», e per l'organizzatore « · Officina dei Portici». */
                        TextColumn::make('when')
                            ->label(__('manage.fields.next_date'))
                            ->state(fn (Event $record): string => collect([
                                self::whenLabel($record, $timezone),
                                $showVenue ? $record->venue?->name : null,
                            ])->filter()->implode(' · '))
                            ->icon(Heroicon::OutlinedCalendar)
                            ->color('gray')
                            ->wrap(),

                        /*
                         * I distintivi della riga: lo stato, «Passato» per
                         * gli eventi già andati e la provenienza per le date
                         * arrivate dal calendario collegato — quelle che
                         * cambieranno da sole alla prossima lettura, e che
                         * modificare qui a mano serve a poco.
                         */
                        TextColumn::make('badges')
                            ->label(__('manage.fields.status'))
                            ->state(fn (Event $record): array => array_values(array_filter([
                                'status',
                                self::isPast($record) ? 'past' : null,
                                $record->source->isImported() ? 'source' : null,
                            ])))
                            ->badge()
                            ->formatStateUsing(fn (string $state, Event $record): string => match ($state) {
                                'past' => __('manage.event_list.past'),
                                'source' => $record->source->label(),
                                default => $record->status->label(),
                            })
                            ->color(fn (string $state, Event $record): string => match ($state) {
                                'past', 'source' => 'gray',
                                default => EventStatusPresentation::color($record->status),
                            }),
                    ])->space(1),
                ])->extraAttributes(['class' => 'items-start! gap-4!']),
            ])
            ->contentGrid(['md' => 2])
            ->recordUrl(fn (Event $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('next_starts_at is null')
                ->orderBy('next_starts_at')
                ->orderByDesc('last_starts_at'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('manage.fields.status'))
                    ->options(EventStatus::options()),
            ])
            ->emptyStateHeading(__('pannelli-eventi.list.empty_heading'))
            ->emptyStateDescription(__('pannelli-eventi.list.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays);
    }

    private static function isPast(Event $record): bool
    {
        return $record->getAttribute('next_starts_at') === null && $record->getAttribute('last_starts_at') !== null;
    }

    private static function whenLabel(Event $record, string $timezone): string
    {
        return match (true) {
            $record->getAttribute('next_starts_at') !== null => PanelDate::event((string) $record->getAttribute('next_starts_at'), $timezone),
            $record->getAttribute('last_starts_at') !== null => __('pannelli-eventi.list.was', ['date' => PanelDate::event((string) $record->getAttribute('last_starts_at'), $timezone)]),
            default => __('pannelli-eventi.list.no_dates'),
        };
    }

    public static function getEloquentQuery(): Builder
    {
        $city = CurrentVenue::city();
        $query = parent::getEloquentQuery();

        VenueDashboardQuery::applyNextOccurrence($query, $city);

        /*
         * La prossima data di ogni riga, precaricata in una interrogazione
         * sola: è ciò su cui agisce l'azione rapida «tutto esaurito», e
         * senza il precaricamento ogni riga dell'elenco ne farebbe quattro
         * per conto proprio — etichetta, colore, permesso, conferma.
         *
         * La giornata evento corrente la dà il motore temporale, come per la
         * colonna «prossima data» che si legge accanto (§3 delle convenzioni).
         */
        $today = EventOccurrenceQuery::for($city)->currentBusinessDate();

        $query->with([
            'occurrences' => function (Relation $occurrences) use ($today): void {
                $occurrences
                    ->where('business_date', '>=', $today)
                    ->orderBy('starts_at')
                    ->limit(1);
            },
        ]);

        return $query;
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            OccurrencesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/nuovo'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/modifica'),
        ];
    }
}
