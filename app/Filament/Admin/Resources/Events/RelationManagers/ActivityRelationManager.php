<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Models\Event;
use App\Models\SponsorshipGrant;
use App\Models\Venue;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
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
                        'merged' => __('admin.activity.merged'),
                        'merged_into' => __('admin.activity.merged_into'),
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
            ->recordActions([
                /*
                 * «Ripristina»: rimette i valori di prima per i campi toccati
                 * da questa riga. Il registro resta intatto — il ripristino è
                 * una modifica come le altre e lascia la sua riga — ma chi ha
                 * rovinato una scheda per sbaglio non deve riscriverla a mano
                 * leggendo la cronologia.
                 */
                Action::make('restore')
                    ->label(__('admin.activity.restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.activity.restore_heading'))
                    ->modalDescription(fn (Activity $record): string => __('admin.activity.restore_description', ['fields' => implode(', ', array_keys(self::previous($record)))]))
                    ->visible(fn (Activity $record): bool => $record->event === 'updated' && self::previous($record) !== []
                        && ($this->getOwnerRecord() instanceof Event || $this->getOwnerRecord() instanceof Venue))
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->action(function (Activity $record): void {
                        $owner = $this->getOwnerRecord();
                        $previous = self::previous($record);
                        if ($previous === [] || ! $owner instanceof Model) {
                            Notification::make()->title(__('admin.activity.restore_empty'))->warning()->send();

                            return;
                        }
                        foreach ($previous as $key => $value) {
                            $owner->setAttribute($key, $value);
                        }
                        $owner->save();
                        Notification::make()->title(__('admin.activity.restored'))->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50]);
    }

    /**
     * I valori «prima» di una riga, limitati ai campi che il modello registra:
     * lo stato e la data di pubblicazione non si ripristinano da qui, perché
     * passano dalla moderazione e dalle sue notifiche.
     *
     * @return array<string, mixed>
     */
    private static function previous(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?: ($activity->properties?->toArray() ?? []);
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        unset($old['status'], $old['verification_status'], $old['published_at'], $old['rejection_reason'], $old['editorial_score']);

        return $old;
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
