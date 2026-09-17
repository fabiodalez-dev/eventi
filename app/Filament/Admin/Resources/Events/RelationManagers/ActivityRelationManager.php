<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Models\SponsorshipGrant;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * La cronologia delle modifiche (§9.2), letta da `activity_log`.
 *
 * È **sola lettura**: un registro che si possa correggere non è un registro.
 * Le righe le scrive il trait `LogsActivity` del modello: decisioni editoriali
 * per eventi e locali, modifiche amministrative per le abilitazioni sponsor.
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
                    ->dateTime('d/m/Y H:i', 'Europe/Rome')
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
                    ->placeholder(fn (Activity $record): string => $record->causer_id === null
                        ? __('admin.placeholders.system')
                        : __('admin.activity.unavailable_causer')),

                TextColumn::make('changes')
                    ->label(__('admin.fields.activity_changes'))
                    ->state(fn (Activity $record): string => self::describe($record))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    private static function describe(Activity $activity): string
    {
        // Current activitylog stores changes separately; keep older records readable.
        $changes = $activity->attribute_changes?->toArray() ?: ($activity->properties?->toArray() ?? []);
        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];
        $parts = [];
        $isGrant = $activity->subject_type === (new SponsorshipGrant)->getMorphClass();
        $paymentLabels = ['amount_cents' => 'amount', 'paid_at' => 'paid',
            'payment_method' => 'method', 'payment_reference' => 'reference', 'complimentary' => 'complimentary'];

        foreach (array_unique([...array_keys($old), ...array_keys($attributes)]) as $key) {
            $label = $isGrant && isset($paymentLabels[$key]) ? __('promotions.'.$paymentLabels[$key]) : __('admin.fields.'.$key);
            $label = $label === 'admin.fields.'.$key ? $key : $label;
            $before = array_key_exists($key, $old) ? self::stringifyAttribute($key, $old[$key], $isGrant).' → ' : '';
            $parts[] = $label.': '.$before.self::stringifyAttribute($key, $attributes[$key] ?? null, $isGrant);
        }

        return implode(' · ', $parts);
    }

    private static function stringifyAttribute(string $key, mixed $value, bool $isGrant): string
    {
        return $isGrant && $key === 'amount_cents' && is_numeric($value)
            ? number_format(((float) $value) / 100, 2, ',', '.').' €'
            : self::stringify($value);
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
