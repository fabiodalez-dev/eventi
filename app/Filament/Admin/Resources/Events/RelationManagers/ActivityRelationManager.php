<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * La cronologia delle modifiche (§9.2), letta da `activity_log`.
 *
 * È **sola lettura**: un registro che si possa correggere non è un registro.
 * Le righe le scrive il trait `LogsActivity` dichiarato sul model, che
 * osserva `status`, `verification_status`, `published_at`,
 * `rejection_reason`, `is_featured` ed `editorial_score` — cioè le decisioni,
 * non i ritocchi al testo.
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fields.activity_changes');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.fields.activity_date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('event')
                    ->label(__('admin.fields.activity_event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => __('admin.activity.created'),
                        'updated' => __('admin.activity.updated'),
                        'deleted' => __('admin.activity.deleted'),
                        default => (string) $state,
                    }),

                TextColumn::make('causer.name')
                    ->label(__('admin.fields.activity_causer'))
                    ->placeholder(__('admin.placeholders.system')),

                TextColumn::make('properties')
                    ->label(__('admin.fields.activity_changes'))
                    ->formatStateUsing(fn (Activity $record): string => self::describe($record))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    private static function describe(Activity $activity): string
    {
        /** @var array<string, mixed> $properties */
        $properties = $activity->properties->toArray();

        /** @var array<string, mixed> $attributes */
        $attributes = $properties['attributes'] ?? [];

        $parts = [];

        foreach ($attributes as $key => $value) {
            $label = __('admin.fields.'.$key);
            $parts[] = ($label === 'admin.fields.'.$key ? $key : $label).': '.self::stringify($value);
        }

        return implode(' · ', $parts);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => __('admin.placeholders.none'),
            is_bool($value) => $value ? __('common.yes') : __('common.no'),
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
        };
    }
}
