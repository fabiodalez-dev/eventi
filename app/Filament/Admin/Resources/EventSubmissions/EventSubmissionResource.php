<?php

namespace App\Filament\Admin\Resources\EventSubmissions;

use App\Enums\PriceType;
use App\Enums\SubmissionStatus;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\EventSubmissions\Pages\EditEventSubmission;
use App\Filament\Admin\Resources\EventSubmissions\Pages\ListEventSubmissions;
use App\Models\Category;
use App\Models\EventSubmission;
use App\Models\Venue;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EventSubmissionResource extends Resource
{
    protected static ?string $model = EventSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $modelLabel = 'Proposta evento';

    protected static ?string $pluralModelLabel = 'Proposte eventi';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.moderation');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = EventSubmission::query()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Proposta ricevuta')->columns(2)->schema([
                TextEntry::make('contact_name')->label('Proposta da'),
                TextEntry::make('contact_email')->label('Email di contatto'),
                TextEntry::make('venue_hint')->label('Luogo indicato'),
                TextEntry::make('status')->label('Stato')->formatStateUsing(fn (SubmissionStatus $state): string => $state->label()),
                TextEntry::make('raw_text')->label('Testo originale')->columnSpanFull(),
                TextEntry::make('notes')->label('Note della revisione')->columnSpanFull(),
            ]),
            Section::make('Evento da pubblicare')->description('Controlla i dati, scegli il locale e la categoria. Crea e pubblica rende subito visibile l’evento nel catalogo. I contatti del proponente restano privati.')
                ->visible(fn (EventSubmission $record): bool => $record->status === SubmissionStatus::Pending)
                ->columns(2)->schema([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255)->columnSpanFull(),
                    Textarea::make('raw_text')->label('Descrizione pubblica')->rows(6)->maxLength(50000)->columnSpanFull(),
                    Select::make('venue_id')->label('Locale')->required()->searchable()->options(fn (EventSubmission $record): array => Venue::query()->where('city_id', $record->city_id)->where('status', VenueStatus::Approved)->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('category_id')->label('Categoria')->required()->searchable()->options(fn (): array => Category::query()->active()->ordered()->pluck('name', 'id')->all()),
                    DateTimePicker::make('starts_at_hint')->label('Inizio')->seconds(false)->required()->timezone(fn (EventSubmission $record): string => $record->city->timezone),
                    DateTimePicker::make('ends_at')->label('Fine, se nota')->seconds(false)->after('starts_at_hint')->timezone(fn (EventSubmission $record): string => $record->city->timezone),
                    Select::make('price_type')->label('Ingresso')->options(PriceType::options())->required(),
                    TextInput::make('price_min')->label('Prezzo da (€), se noto')->numeric()->minValue(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('title')->label('Proposta')->searchable()->wrap(),
            TextColumn::make('venue_hint')->label('Luogo indicato')->wrap(),
            TextColumn::make('city.name')->label('Città'),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (SubmissionStatus $state): string => $state->label()),
            TextColumn::make('created_at')->label('Ricevuta')->dateTime('d/m/Y H:i'),
        ])->filters([SelectFilter::make('status')->label('Stato')->options(SubmissionStatus::options())])
            ->recordActions([EditAction::make()->label('Esamina')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEventSubmissions::route('/'), 'edit' => EditEventSubmission::route('/{record}/edit')];
    }
}
