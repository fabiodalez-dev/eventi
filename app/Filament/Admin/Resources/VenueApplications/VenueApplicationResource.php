<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueApplications;

use App\Actions\ApproveVenueApplication;
use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Filament\Admin\Resources\VenueApplications\Pages\EditVenueApplication;
use App\Filament\Admin\Resources\VenueApplications\Pages\ListVenueApplications;
use App\Models\User;
use App\Models\VenueApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * §7.3 e §9.2 — le richieste di iscrizione dei locali.
 *
 * Ciò che ha scritto chi si candida si **legge** e non si corregge: è la sua
 * dichiarazione, e riscriverla renderebbe impossibile capire, mesi dopo, su
 * quali informazioni era stata presa la decisione. Modificabili sono soltanto
 * l'esito, le note interne e il collegamento al locale creato.
 */
class VenueApplicationResource extends Resource
{
    protected static ?string $model = VenueApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.moderation');
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
        $pending = VenueApplication::query()->where('status', ApplicationStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.sections.general'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('venue_name')->label(__('admin.fields.venue_name')),
                        TextEntry::make('type')
                            ->label(__('admin.fields.type'))
                            ->formatStateUsing(fn (VenueType $state): string => $state->label()),
                        TextEntry::make('address')
                            ->label(__('admin.fields.address'))
                            ->placeholder(__('admin.placeholders.none')),
                        TextEntry::make('message')
                            ->label(__('admin.fields.message'))
                            ->placeholder(__('admin.placeholders.none'))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.sections.contacts'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('contact_name')->label(__('admin.fields.contact_name')),
                        TextEntry::make('contact_role')
                            ->label(__('admin.fields.contact_role'))
                            ->placeholder(__('admin.placeholders.none')),
                        TextEntry::make('contact_email')->label(__('admin.fields.contact_email')),
                        TextEntry::make('contact_phone')
                            ->label(__('admin.fields.contact_phone'))
                            ->placeholder(__('admin.placeholders.none')),
                    ]),

                Section::make(__('admin.sections.review'))
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label(__('admin.fields.status'))
                            ->options(ApplicationStatus::options())
                            ->required(),

                        Select::make('venue_id')
                            ->label(__('admin.fields.venue'))
                            ->relationship('venue', 'name')
                            ->searchable()
                            ->preload(),

                        Textarea::make('notes')
                            ->label(__('admin.fields.notes'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('venue_name')
                    ->label(__('admin.fields.venue_name'))
                    ->searchable(),

                TextColumn::make('contact_name')
                    ->label(__('admin.fields.contact_name'))
                    ->searchable(),

                TextColumn::make('contact_email')
                    ->label(__('admin.fields.contact_email'))
                    ->searchable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (VenueType $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state): string => $state->label())
                    ->color(fn (ApplicationStatus $state): string => match ($state) {
                        ApplicationStatus::Pending => 'warning',
                        ApplicationStatus::Approved => 'success',
                        ApplicationStatus::Rejected => 'danger',
                    }),

                TextColumn::make('reviewer.name')
                    ->label(__('admin.fields.reviewed_by'))
                    ->placeholder(__('admin.placeholders.none')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(ApplicationStatus::options()),

                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(VenueType::options()),
            ])
            ->recordActions([
                /*
                 * **Accettare una richiesta crea il locale.**
                 *
                 * Fin qui c'era solo «Modifica»: la coda sapeva contare le
                 * richieste ma non c'era modo di accoglierne una. Chi voleva
                 * farlo apriva la richiesta, andava in un'altra pagina e
                 * ricopiava i dati a mano — col rischio di ricopiarli male e
                 * la certezza di perdere il filo fra il locale nuovo e la
                 * richiesta che l'aveva chiesto.
                 */
                Action::make('approva')
                    ->label(__('admin.actions.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('venue_applications.approve_confirm'))
                    ->visible(fn (VenueApplication $record): bool => $record->status !== ApplicationStatus::Approved)
                    ->action(function (VenueApplication $record): void {
                        $moderatore = Auth::user();

                        if (! $moderatore instanceof User) {
                            return;
                        }

                        $locale = app(ApproveVenueApplication::class)->handle($record, $moderatore);

                        Notification::make()
                            ->title(__('venue_applications.approved', ['venue' => $locale->name]))
                            ->body(__('venue_applications.approved_body'))
                            ->success()
                            ->send();
                    }),

                Action::make('rifiuta')
                    ->label(__('admin.actions.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (VenueApplication $record): bool => $record->status !== ApplicationStatus::Rejected)
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('venue_applications.reject_reason'))
                            ->required()
                            ->rows(3),
                    ])
                    /* Il motivo si scrive e si conserva: fra un mese, quando
                       la stessa persona riscrivera' chiedendo perche', la
                       risposta dev'essere nella scheda e non nella memoria di
                       chi decise. */
                    ->action(function (VenueApplication $record, array $data): void {
                        $record->forceFill([
                            'status' => ApplicationStatus::Rejected,
                            'reviewed_by' => Auth::id(),
                            'reviewed_at' => now(),
                            'notes' => (string) $data['notes'],
                        ])->save();

                        Notification::make()->title(__('venue_applications.rejected'))->success()->send();
                    }),

                EditAction::make(),
            ]);
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
