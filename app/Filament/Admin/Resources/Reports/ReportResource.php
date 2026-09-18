<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Filament\Admin\Resources\Reports\Pages\EditReport;
use App\Filament\Admin\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use App\Queries\EditorialDashboardQuery;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * §14.6 — le segnalazioni. Non si creano dal pannello: arrivano dal pubblico,
 * e qui si leggono e si chiudono.
 *
 * La parte modificabile è **solo l'esito**: stato, nota di risoluzione e chi
 * ha deciso. Motivo, testo e contatto di chi ha segnalato restano com'erano —
 * una segnalazione che il moderatore può riscrivere non è più una prova di
 * quello che è stato detto.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.moderation');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.report.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.report.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Report::query()->tap(EditorialDashboardQuery::openReportScope(...))->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('admin.sections.review'))
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label(__('admin.fields.status'))
                            ->options(ReportStatus::options())
                            ->required(),

                        Textarea::make('resolution_note')
                            ->label(__('admin.fields.resolution_note'))
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

                TextColumn::make('reason')
                    ->label(__('admin.fields.reason'))
                    ->badge()
                    ->formatStateUsing(fn (ReportReason $state): string => $state->label()),

                TextColumn::make('reportable_type')
                    ->label(__('admin.fields.reportable'))
                    ->formatStateUsing(fn (Report $record): string => self::describeSubject($record))
                    ->wrap(),

                TextColumn::make('note')
                    ->label(__('admin.fields.note'))
                    ->limit(80)
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ReportStatus $state): string => $state->label())
                    ->color(fn (ReportStatus $state): string => match ($state) {
                        ReportStatus::Pending => 'danger',
                        ReportStatus::Reviewing => 'warning',
                        ReportStatus::Resolved => 'success',
                        ReportStatus::Dismissed => 'gray',
                    }),

                TextColumn::make('reviewer.name')
                    ->label(__('admin.fields.reviewed_by'))
                    ->placeholder(__('admin.placeholders.none')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(ReportStatus::options()),

                SelectFilter::make('reason')
                    ->label(__('admin.fields.reason'))
                    ->options(ReportReason::options()),

                Filter::make('open')
                    ->label(__('admin.dashboard.open_reports'))
                    ->query(fn (Builder $query) => EditorialDashboardQuery::openReportScope($query)),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Il tipo morph contiene un alias breve (`event`, `venue`, …) e non un
     * nome di classe: è la scelta di §3.13 dello schema, e va tradotta qui
     * invece di mostrare all'utente una stringa da programmatori.
     */
    private static function describeSubject(Report $report): string
    {
        $label = __('admin.resources.'.$report->reportable_type.'.label');
        $label = $label === 'admin.resources.'.$report->reportable_type.'.label'
            ? $report->reportable_type
            : $label;

        $subject = $report->reportable;
        $title = $subject instanceof Model
            ? (string) ($subject->getAttribute('title') ?? $subject->getAttribute('name') ?? $subject->getKey())
            : (string) $report->reportable_id;

        return $label.' '.__('common.separator').' '.$title;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'edit' => EditReport::route('/{record}/edit'),
        ];
    }
}
