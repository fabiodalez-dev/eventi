<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\MembershipRequirement;
use App\Models\EventOccurrence;
use App\Support\DeclaredCosts;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

final class OccurrenceDecisionFields
{
    /** @return list<Section> */
    public static function make(): array
    {
        return [
            Section::make(__('decision.practical'))->description(__('decision.inherit'))->schema([
                Select::make('practical_details.accessibility')->label(__('decision.accessibility'))->options(['yes' => __('decision.accessible'), 'no' => __('decision.not_accessible'), 'unknown' => __('decision.unknown')])->in(['yes', 'no', 'unknown']),
                Select::make('practical_details.membership')->label(__('decision.membership'))->options(MembershipRequirement::options()),
                ...array_map(fn (string $field) => Textarea::make('practical_details.'.$field)->label(__('decision.'.$field))->maxLength(2000), ['entrance_notes', 'membership_notes', 'parking_notes', 'transit_notes', 'food_notes', 'start_notes']),
            ]),
            Section::make(__('decision.costs'))->description(__('decision.cost_hint'))->schema(array_map(fn (string $field) => TextInput::make('cost_breakdown.'.$field)->label(__('decision.'.$field))->numeric()->minValue(0)->maxValue(100000)->step('0.01')->rules(['decimal:0,2'])->suffix(fn (?EventOccurrence $record) => $record?->event?->currency ?: 'EUR'), DeclaredCosts::FIELDS)),
        ];
    }
}
