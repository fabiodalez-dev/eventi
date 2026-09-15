<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\EventOccurrence;
use App\Services\Analytics\OccurrenceAnalytics;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;

final class OccurrenceAnalyticsFields
{
    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('analytics_views')->label(__('analytics.metrics.views'))->numeric()->sortable(),
            TextColumn::make('analytics_clicks')->label(__('analytics.occurrence_clicks'))->numeric()->sortable(),
            TextColumn::make('saved_events_count')->label(__('analytics.occurrence_saves'))->numeric(),
        ];
    }

    public static function action(): Action
    {
        return Action::make('analytics')->label(__('analytics.title'))->icon('heroicon-o-chart-bar')->slideOver()
            ->authorize(fn (EventOccurrence $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading(fn (EventOccurrence $record): string => __('analytics.occurrence_heading', ['date' => $record->starts_at->setTimezone($record->event->city->timezone)->format('d/m/Y H:i')]))
            ->modalContent(fn (EventOccurrence $record) => view('filament.occurrence-analytics', ['report' => app(OccurrenceAnalytics::class)->report($record)]))
            ->modalSubmitAction(false)->modalCancelActionLabel(__('analytics.close'));
    }
}
