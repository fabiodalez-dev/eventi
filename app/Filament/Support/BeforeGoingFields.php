<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AgeGroup;
use App\Enums\MembershipRequirement;
use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Event;
use App\Models\EventFeature;
use App\Models\Venue;
use App\Support\BeforeGoingDefaults;
use App\Support\PracticalIcons;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BeforeGoingFields
{
    public static function make(bool $venue = false): Section
    {
        $fields = [
            Select::make('content_details.age_groups')->label(__('family.title'))->multiple()->options(AgeGroup::options())->helperText(__('family.guidance'))->nestedRecursiveRules([Rule::enum(AgeGroup::class)])->columnSpanFull(),
            ...array_map(fn (string $field) => Select::make('content_details.'.$field)->label(__('family.'.$field))->options(['yes' => __('family.yes'), 'no' => __('family.no')])->placeholder(__('family.unknown'))->rules([Rule::in(['yes', 'no'])]), ['stroller', 'changing_table', 'kids_area']),
            Select::make('content_details.membership')->label('Tessera')->options(MembershipRequirement::options())->placeholder('Non specificato')->rules([Rule::enum(MembershipRequirement::class)]),
            Select::make('content_details.accessibility')->label('Accesso in sedia a rotelle')->options(['yes' => 'Accessibile', 'no' => 'Non accessibile'])->placeholder('Non specificato'),
            DescriptionEditor::make('content_details.membership_notes')->label('Dettagli tessera')->placeholder('Tipo di tessera, costo e modalità di rilascio')->maxLength(2000),
            DescriptionEditor::make('content_details.accessibility_notes')->label('Dettagli accessibilità')->placeholder('Percorso di ingresso, accompagnatori, contatto per assistenza')->maxLength(2000),
            Select::make('content_details.feature_ids')->label('Caratteristiche e servizi')->multiple()->searchable()->preload()->options(fn (): array => EventFeature::choices())->columnSpanFull()
                ->helperText('Cerca per nome: per esempio coppia, bagno, interprete, guardaroba. Il catalogo e le icone si gestiscono in Contenuti → Prima di andare.')
                ->nestedRecursiveRules([Rule::exists('event_features', 'id')->where('is_active', true)->where('is_system', false)])
                ->createOptionForm(EventFeatureResource::fields())
                ->createOptionAction(fn (Action $action) => $action->label('Crea caratteristica')->visible(fn (): bool => auth()->user()?->can('create', EventFeature::class) === true))
                ->createOptionUsing(function (array $data): int {
                    Gate::authorize('create', EventFeature::class);

                    return (int) EventFeature::create($data)->getKey();
                }),
            Repeater::make('content_details.practical_custom')->label($venue ? 'Altre informazioni del locale' : 'Altre informazioni per questo evento')->defaultItems(0)->maxItems($venue ? 12 : 24)->addActionLabel('Aggiungi una voce libera')->columnSpanFull()->columns(2)->schema([
                TextInput::make('label')->label('Titolo')->required()->maxLength(120),
                Select::make('icon')->label('Icona')->options(PracticalIcons::previews())->allowHtml()->searchable()->required()->default('check-circle')->rules([Rule::in(array_keys(PracticalIcons::options()))]),
                DescriptionEditor::make('text')->label('Dettagli')->maxLength(1000)->columnSpanFull(),
            ])->helperText($venue ? 'Queste indicazioni si aggiungono automaticamente a ogni evento del locale.' : 'Le informazioni del locale sono già incluse. Puoi aggiungere qui le indicazioni specifiche dell’evento.')
                ->collapseAllAction(fn (Action $action) => $action->button()->icon('heroicon-o-chevron-up'))
                ->expandAllAction(fn (Action $action) => $action->button()->icon('heroicon-o-chevron-down'))
                ->collapsible()->itemLabel(fn (array $state): ?string => $state['label'] ?? null),
        ];
        if (! $venue) {
            foreach ($fields as $field) {
                $key = substr($field->getName(), strlen('content_details.'));
                $field->afterStateHydrated(function ($component, $state, Get $get, ?Model $record) use ($key): void {
                    $defaults = self::defaults($get, $record);
                    $merged = BeforeGoingDefaults::merge($defaults, [$key => $state]);
                    $component->state($merged[$key] ?? null);
                })->dehydrateStateUsing(fn ($state, Get $get, ?Model $record) => BeforeGoingDefaults::override($key, $state, self::defaults($get, $record)[$key] ?? null));
            }
        }

        return Section::make('Prima di andare')
            ->description($venue ? 'Informazioni predefinite per tutti gli eventi di questo locale. Usa anche le caratteristiche del catalogo: compariranno già compilate nelle schede degli eventi.' : 'Le informazioni del locale sono già compilate e si aggiungono a quelle dell’evento. Per tessera e accessibilità puoi indicare un’eccezione valida solo per questo evento.')
            ->columns(2)->schema($fields);
    }

    /** @return array<string, mixed> */
    private static function defaults(Get $get, ?Model $record): array
    {
        $venue = Filament::getCurrentPanel()?->getId() === 'venue'
            ? CurrentVenue::get()
            : Venue::find($get('venue_id') ?? ($record instanceof Event ? $record->venue_id : null));

        return BeforeGoingDefaults::forVenue($venue);
    }
}
