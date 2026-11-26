<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueApplications;

use App\Actions\ReviewVenueApplication;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\Pages\EditVenueApplication;
use App\Filament\Admin\Resources\VenueApplications\Pages\ListVenueApplications;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Models\Organizer;
use App\Models\User;
use App\Models\VenueApplication;
use App\Services\Editorial\EditorialApplications;
use App\Support\Description;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * §7.3 e §9.2 — le richieste di iscrizione dei locali.
 *
 * Ciò che ha scritto chi si candida si **legge** e non si corregge: è la sua
 * dichiarazione, e riscriverla renderebbe impossibile capire, mesi dopo, su
 * quali informazioni era stata presa la decisione.
 *
 * **Si decide dalla pagina che si legge.** L'esito non è un campo del modulo:
 * una select «Stato» messa ad «Approvata» salvava la richiesta senza creare il
 * locale né consegnare l'accesso, cioè una richiesta accolta per finta. Le
 * sole porte sono «Approva» e «Rifiuta», le stesse nella riga dell'elenco e
 * nell'intestazione della pagina, e passano entrambe sotto lock. Nella pagina
 * si scrivono soltanto le note interne.
 */
class VenueApplicationResource extends Resource
{
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = VenueApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?int $navigationSort = 2;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.places');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.venue_application.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.venue_application.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Cache::remember('nav-badge:venue-applications-pending', 60, fn (): int => VenueApplication::query()->where('status', ApplicationStatus::Pending)->count());

        return $pending > 0 ? (string) $pending : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Le richieste ancora da confermare restano fuori grazie allo scope
     * globale del modello. Qui si aggiunge, in una sola lettura, il segnale
     * «possibile doppione» che l'elenco mostra accanto al nome: la regola sta
     * in `EditorialApplications`, la stessa dell'app della redazione.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['reviewer'])
            ->select('venue_applications.*')
            ->selectRaw('('.EditorialApplications::duplicateSql().') as possible_duplicate');
    }

    /**
     * I doppioni probabili di una richiesta, per la pagina di lettura, con i
     * collegamenti del pannello. I criteri sono in `EditorialApplications`.
     *
     * @return list<array{label: string, url: string|null}>
     */
    public static function duplicates(VenueApplication $record): array
    {
        return array_map(static fn (array $item): array => [
            'label' => $item['label'],
            'url' => match ($item['kind']) {
                'application' => self::getUrl('edit', ['record' => $item['id']]),
                'venue' => VenueResource::getUrl('edit', ['record' => $item['id']]),
                default => $item['active'] && ($organizer = Organizer::query()->find($item['id'])) instanceof Organizer ? route('organizers.show', $organizer) : null,
            },
        ], EditorialApplications::duplicates($record, FilamentTimezone::get()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('venue_applications.admin.sections.outcome'))
                    ->description(fn (VenueApplication $record): string => self::outcomeHint($record))
                    ->columns(1)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('admin.fields.status'))
                            ->inlineLabel()
                            ->badge()
                            ->formatStateUsing(fn (ApplicationStatus $state): string => $state->label())
                            ->color(fn (ApplicationStatus $state): string => self::statusColor($state)),
                        TextEntry::make('kind')
                            ->label(__('admin.fields.kind'))
                            ->inlineLabel()
                            ->badge()
                            ->color('gray')
                            ->state(fn (VenueApplication $record): string => $record->kind()->label()),
                        TextEntry::make('created_at')
                            ->label(__('venue_applications.admin.fields.received_at'))
                            ->inlineLabel()
                            ->panelMoment(),
                        TextEntry::make('email_confirmed_at')
                            ->label(__('venue_applications.admin.fields.queued_at'))
                            ->inlineLabel()
                            ->panelMoment()
                            ->placeholder(__('admin.placeholders.none')),
                        TextEntry::make('reviewed')
                            ->label(__('admin.fields.reviewed_by'))
                            ->inlineLabel()
                            ->state(fn (VenueApplication $record): ?string => $record->reviewed_at === null ? null : __('venue_applications.admin.reviewed', [
                                'name' => $record->reviewer->name ?? __('venue_applications.admin.someone'),
                                'date' => $record->reviewed_at->copy()->timezone(FilamentTimezone::get())->format('d/m/Y H:i'),
                            ]))
                            ->placeholder(__('venue_applications.admin.not_reviewed')),
                        TextEntry::make('linked')
                            ->label(fn (VenueApplication $record): string => $record->kind() === ApplicationKind::Organizer ? __('venue_applications.admin.fields.organizer') : __('admin.fields.venue'))
                            ->inlineLabel()
                            ->state(fn (VenueApplication $record): ?string => $record->kind() === ApplicationKind::Organizer ? $record->organizer?->name : $record->venue?->name)
                            ->url(fn (VenueApplication $record): ?string => self::linkedUrl($record))
                            ->color('primary')
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->iconPosition('after')
                            ->placeholder(__('venue_applications.admin.not_linked')),
                        TextEntry::make('duplicates')
                            ->label(__('venue_applications.admin.possible_duplicate'))
                            ->inlineLabel()
                            ->visible(fn (VenueApplication $record): bool => $record->status === ApplicationStatus::Pending && self::duplicates($record) !== [])
                            ->state(fn (VenueApplication $record): HtmlString => self::duplicatesHtml($record))
                            ->html()
                            ->helperText(__('venue_applications.admin.duplicate_hint')),
                    ]),

                Section::make(fn (VenueApplication $record): string => $record->kind() === ApplicationKind::Organizer ? __('venue_applications.admin.sections.organizer') : __('venue_applications.admin.sections.venue'))
                    ->columns(1)
                    ->schema([
                        TextEntry::make('venue_name')->label(__('admin.fields.venue_name'))->inlineLabel(),
                        TextEntry::make('type')
                            ->label(__('admin.fields.type'))
                            ->inlineLabel()
                            ->visible(fn (VenueApplication $record): bool => $record->kind() !== ApplicationKind::Organizer)
                            ->formatStateUsing(fn (VenueType $state): string => $state->label()),
                        TextEntry::make('address')
                            ->label(__('admin.fields.address'))
                            ->inlineLabel()
                            ->visible(fn (VenueApplication $record): bool => $record->kind() !== ApplicationKind::Organizer)
                            ->placeholder(__('admin.placeholders.none')),
                        self::datum('postal_code'),
                        self::datum('municipality'),
                        self::datum('capacity'),
                        TextEntry::make('venue_description')
                            ->label(__('venue_applications.admin.fields.description'))
                            ->inlineLabel()
                            ->visible(fn (VenueApplication $record): bool => filled($record->venueDatum('description')))
                            ->state(fn (VenueApplication $record): HtmlString => Description::render((string) $record->venueDatum('description'))),
                        TextEntry::make('message')
                            ->formatStateUsing(fn (?string $state) => Description::render($state))
                            ->label(fn (VenueApplication $record): string => $record->kind() === ApplicationKind::Claim ? __('venue_applications.admin.fields.proof') : __('admin.fields.message'))
                            ->inlineLabel()
                            ->placeholder(__('admin.placeholders.none')),
                    ]),

                Section::make(__('venue_applications.admin.sections.public_contacts'))
                    ->description(__('venue_applications.admin.public_contacts_hint'))
                    ->columns(1)
                    ->visible(fn (VenueApplication $record): bool => $record->kind() !== ApplicationKind::Claim)
                    ->schema([
                        TextEntry::make('website')
                            ->label(__('venue_applications.admin.fields.website'))
                            ->inlineLabel()
                            ->state(fn (VenueApplication $record): ?string => self::website($record))
                            ->url(fn (VenueApplication $record): ?string => self::website($record), shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder(__('admin.placeholders.none')),
                        self::datum('public_email'),
                        self::datum('public_phone'),
                        self::datum('instagram', link: true),
                        self::datum('facebook', link: true),
                    ]),

                Section::make(__('venue_applications.admin.sections.image'))
                    ->description(__('venue_applications.admin.image_hint'))
                    ->columns(1)
                    ->visible(fn (VenueApplication $record): bool => filled($record->image_path))
                    ->schema([
                        ImageEntry::make('image_preview')
                            ->hiddenLabel()
                            ->state(fn (VenueApplication $record): string => route('venue-applications.image', $record->getKey()))
                            ->url(fn (VenueApplication $record): string => route('venue-applications.image', $record->getKey()), shouldOpenInNewTab: true)
                            ->imageHeight(220)
                            ->extraImgAttributes(['alt' => __('venue_applications.admin.image_alt'), 'class' => 'object-cover']),
                    ]),

                Section::make(__('venue_applications.admin.sections.contact'))
                    ->description(__('venue_applications.admin.contact_hint'))
                    ->columns(1)
                    ->schema([
                        TextEntry::make('contact_name')->label(__('admin.fields.contact_name'))->inlineLabel(),
                        TextEntry::make('contact_role')
                            ->label(__('admin.fields.contact_role'))
                            ->inlineLabel()
                            ->placeholder(__('admin.placeholders.none')),
                        TextEntry::make('contact_email')->label(__('admin.fields.contact_email'))->inlineLabel()->copyable(),
                        TextEntry::make('contact_phone')
                            ->label(__('admin.fields.contact_phone'))
                            ->inlineLabel()
                            ->placeholder(__('admin.placeholders.none')),
                        TextEntry::make('account')
                            ->label(__('venue_applications.admin.fields.account'))
                            ->inlineLabel()
                            ->state(fn (VenueApplication $record): ?string => $record->user === null ? null : $record->user->name.' · '.$record->user->email)
                            ->url(fn (VenueApplication $record): ?string => $record->user === null ? null : UserResource::getUrl('edit', ['record' => $record->user]))
                            ->color('primary')
                            ->placeholder(__('venue_applications.admin.no_account')),
                        TextEntry::make('account_state')
                            ->label(__('venue_applications.admin.fields.account_state'))
                            ->inlineLabel()
                            ->visible(fn (VenueApplication $record): bool => $record->user !== null)
                            ->badge()
                            ->state(fn (VenueApplication $record): string => $record->user?->hasVerifiedEmail() ? __('venue_applications.admin.account_verified') : __('venue_applications.admin.account_unverified'))
                            ->color(fn (VenueApplication $record): string => $record->user?->hasVerifiedEmail() ? 'success' : 'warning'),
                    ]),

                Section::make(__('venue_applications.admin.sections.notes'))
                    ->description(__('venue_applications.admin.notes_hint'))
                    ->columns(1)
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('admin.fields.notes'))
                            ->hiddenLabel()
                            ->rows(4)
                            ->maxLength(5000),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(__('pannelli-redazione.richieste.empty_heading'))
            ->emptyStateDescription(__('pannelli-redazione.richieste.empty_description'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('venue_applications.admin.fields.received_at'))
                    ->panelMoment()
                    ->visibleFrom('md')
                    ->sortable(),

                TextColumn::make('venue_name')
                    ->label(__('admin.fields.venue_name'))
                    ->weight('semibold')
                    /* Sul telefono la colonna dei segnali non c'è: l'avviso del
                       doppione sta sotto il nome, così l'elenco non scorre di lato. */
                    ->description(fn (VenueApplication $record): HtmlString => new HtmlString(e($record->kind()->label()).(
                        $record->status === ApplicationStatus::Pending && (bool) $record->getAttribute('possible_duplicate')
                            ? ' <span class="font-semibold text-warning-700 md:hidden dark:text-warning-400">· '.e(__('venue_applications.admin.possible_duplicate')).'</span>'
                            : ''
                    )))
                    ->wrap()
                    ->searchable(),

                TextColumn::make('possible_duplicate')
                    ->label(__('venue_applications.admin.signals'))
                    ->badge()
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visibleFrom('md')
                    ->state(fn (VenueApplication $record): ?string => $record->status === ApplicationStatus::Pending && (bool) $record->getAttribute('possible_duplicate') ? __('venue_applications.admin.possible_duplicate') : null),

                TextColumn::make('contact_name')
                    ->label(__('admin.fields.contact_name'))
                    ->visibleFrom('lg')
                    ->description(fn (VenueApplication $record): string => (string) $record->contact_email)
                    ->wrap()
                    ->searchable(['contact_name', 'contact_email']),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->visibleFrom('sm')
                    ->formatStateUsing(fn (ApplicationStatus $state): string => $state->label())
                    ->color(fn (ApplicationStatus $state): string => self::statusColor($state)),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (VenueType $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reviewer.name')
                    ->label(__('admin.fields.reviewed_by'))
                    ->placeholder(__('admin.placeholders.none'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (VenueApplication $record): string => self::getUrl('edit', ['record' => $record]))
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('admin.fields.kind'))
                    ->options(ApplicationKind::options()),

                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(VenueType::options()),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    /**
     * **Accettare una richiesta crea il locale** (o l'organizzatore, o
     * consegna le chiavi del locale rivendicato). La stessa azione sta nella
     * riga dell'elenco e nell'intestazione della pagina.
     *
     * Passa sempre da `ReviewVenueApplication` (la stessa regola dell'app della
     * redazione), dentro una transazione che blocca la riga della richiesta: due clic, o due persone nello stesso
     * minuto, non creano due locali. Lo stato si rilegge sotto lock, perché
     * la pagina da cui si clicca l'ha letto prima.
     */
    public static function approveAction(): Action
    {
        return Action::make('approva')
            ->label(__('admin.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            // Non sulla propria richiesta (`VenueApplicationPolicy::approve`).
            ->authorize('approve')
            ->requiresConfirmation()
            ->modalHeading(fn (VenueApplication $record): string => __('venue_applications.admin.approve_heading', ['venue' => $record->venue_name]))
            ->modalDescription(fn (VenueApplication $record): string => match ($record->kind()) {
                ApplicationKind::Claim => __('venue_applications.approve_claim_confirm'),
                ApplicationKind::Organizer => __('venue_applications.approve_organizer_confirm'),
                ApplicationKind::Venue => __('venue_applications.approve_confirm'),
            })
            ->modalSubmitActionLabel(__('admin.actions.approve'))
            ->visible(fn (VenueApplication $record): bool => $record->status !== ApplicationStatus::Approved)
            ->action(function (VenueApplication $record): void {
                $moderatore = Auth::user();

                if (! $moderatore instanceof User) {
                    return;
                }

                $kind = $record->kind();
                try {
                    $soggetto = app(ReviewVenueApplication::class)->approve($record, $moderatore);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                    $record->refresh();

                    return;
                }

                $record->refresh();

                [$titolo, $corpo] = match (true) {
                    $soggetto instanceof Organizer => [__('venue_applications.approved_organizer', ['organizer' => $soggetto->name]), __('venue_applications.approved_organizer_body')],
                    $kind === ApplicationKind::Claim => [__('venue_applications.approved_claim', ['venue' => $soggetto->name]), __('venue_applications.approved_claim_body')],
                    default => [__('venue_applications.approved', ['venue' => $soggetto->name]), __('venue_applications.approved_body')],
                };

                Notification::make()->title($titolo)->body($corpo)->success()->send();
            });
    }

    /**
     * Rifiutare chiede un motivo, che resta nella scheda: fra un mese, quando
     * la stessa persona riscriverà chiedendo perché, la risposta dev'essere
     * qui e non nella memoria di chi decise. Avvisare chi ha scritto è una
     * scelta, e l'avviso va solo all'account che ha mandato la richiesta.
     */
    public static function rejectAction(): Action
    {
        return Action::make('rifiuta')
            ->label(__('admin.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('update')
            ->modalHeading(fn (VenueApplication $record): string => __('venue_applications.admin.reject_heading', ['venue' => $record->venue_name]))
            ->modalSubmitActionLabel(__('admin.actions.reject'))
            ->visible(fn (VenueApplication $record): bool => $record->status === ApplicationStatus::Pending)
            ->schema(fn (VenueApplication $record): array => [
                Textarea::make('notes')
                    ->label(__('venue_applications.reject_reason'))
                    ->helperText(__('venue_applications.admin.reject_reason_hint'))
                    ->required()
                    ->maxLength(5000)
                    ->rows(3),
                Toggle::make('notify')
                    ->label(__('venue_applications.admin.reject_notify'))
                    ->helperText($record->user === null ? __('venue_applications.admin.reject_notify_unavailable') : __('venue_applications.admin.reject_notify_hint', ['email' => $record->user->email]))
                    ->disabled($record->user === null)
                    ->default(false)
                    ->live(),
                Textarea::make('message')
                    ->label(__('venue_applications.admin.reject_message'))
                    ->helperText(__('venue_applications.admin.reject_message_hint'))
                    ->visible(fn (Get $get): bool => (bool) $get('notify'))
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->action(function (VenueApplication $record, array $data): void {
                $moderatore = Auth::user();

                if (! $moderatore instanceof User) {
                    return;
                }

                $esito = app(ReviewVenueApplication::class)->reject(
                    $record,
                    $moderatore,
                    (string) $data['notes'],
                    (bool) ($data['notify'] ?? false),
                    isset($data['message']) ? (string) $data['message'] : null,
                );

                $record->refresh();

                if ($esito === null) {
                    Notification::make()->title(__('venue_applications.admin.already_decided'))->danger()->send();

                    return;
                }

                $avvisa = $esito['notified'];

                Notification::make()
                    ->title(__('venue_applications.rejected'))
                    ->body($avvisa ? __('venue_applications.admin.rejected_notified') : null)
                    ->success()
                    ->send();
            });
    }

    public static function statusColor(ApplicationStatus $state): string
    {
        return match ($state) {
            ApplicationStatus::Pending => 'warning',
            ApplicationStatus::Approved => 'success',
            ApplicationStatus::Rejected => 'danger',
        };
    }

    private static function outcomeHint(VenueApplication $record): string
    {
        return match ($record->status) {
            ApplicationStatus::Pending => __('venue_applications.admin.outcome_pending'),
            ApplicationStatus::Approved => __('venue_applications.admin.outcome_approved'),
            ApplicationStatus::Rejected => __('venue_applications.admin.outcome_rejected'),
        };
    }

    private static function linkedUrl(VenueApplication $record): ?string
    {
        if ($record->kind() === ApplicationKind::Organizer) {
            return $record->organizer?->is_active ? route('organizers.show', $record->organizer) : null;
        }

        return $record->venue === null ? null : VenueResource::getUrl('edit', ['record' => $record->venue]);
    }

    private static function website(VenueApplication $record): ?string
    {
        return EditorialApplications::website($record);
    }

    /** Un dato pubblico del locale scritto nella richiesta (`venue_data`). */
    private static function datum(string $key, bool $link = false): TextEntry
    {
        $entry = TextEntry::make('venue_data_'.$key)
            ->label(__('venue_applications.admin.fields.'.$key))
            ->inlineLabel()
            ->state(fn (VenueApplication $record): ?string => ($value = $record->venueDatum($key)) === null ? null : (string) $value)
            ->placeholder(__('admin.placeholders.none'));

        if ($link) {
            $entry->url(fn (VenueApplication $record): ?string => is_string($url = $record->venueDatum($key)) && str_starts_with($url, 'http') ? $url : null, shouldOpenInNewTab: true)
                ->color('primary');
        }

        return $entry;
    }

    private static function duplicatesHtml(VenueApplication $record): HtmlString
    {
        $items = array_map(static fn (array $item): string => $item['url'] === null
            ? '<li>'.e($item['label']).'</li>'
            : '<li><a href="'.e($item['url']).'" class="text-primary-600 underline dark:text-primary-400">'.e($item['label']).'</a></li>', self::duplicates($record));

        return new HtmlString('<ul class="flex list-disc flex-col gap-1 ps-5">'.implode('', $items).'</ul>');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVenueApplications::route('/'),
            'edit' => EditVenueApplication::route('/{record}/edit'),
        ];
    }
}
