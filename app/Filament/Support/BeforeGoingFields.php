<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\MembershipRequirement;
use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use App\Models\EventFeature;
use App\Support\PracticalIcons;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BeforeGoingFields
{
    public static function make(): Section
    {
        return Section::make('Prima di andare')->description('Seleziona solo informazioni confermate. Le voci lasciate vuote non appaiono sul sito.')->columns(2)->schema([
            Select::make('content_details.membership')->label('Tessera')->options(MembershipRequirement::options())->placeholder('Non specificato')->rules([Rule::enum(MembershipRequirement::class)]),
            Select::make('content_details.accessibility')->label('Accesso in sedia a rotelle')->options(['yes' => 'Accessibile', 'no' => 'Non accessibile'])->placeholder('Non specificato'),
            Textarea::make('content_details.membership_notes')->label('Dettagli tessera')->placeholder('Tipo di tessera, costo e modalità di rilascio')->maxLength(2000)->rows(2),
            Textarea::make('content_details.accessibility_notes')->label('Dettagli accessibilità')->placeholder('Percorso di ingresso, accompagnatori, contatto per assistenza')->maxLength(2000)->rows(2),
            Select::make('content_details.feature_ids')->label('Caratteristiche e servizi')->multiple()->searchable()->preload()->options(fn (): array => EventFeature::choices())->columnSpanFull()
                ->helperText('Cerca per nome: per esempio coppia, bagno, interprete, guardaroba. Il catalogo e le icone si gestiscono in Contenuti → Prima di andare.')
                ->nestedRecursiveRules([Rule::exists('event_features', 'id')->where('is_active', true)->where('is_system', false)])
                ->createOptionForm(EventFeatureResource::fields())
                ->createOptionAction(fn (Action $action) => $action->label('Crea caratteristica')->visible(fn (): bool => auth()->user()?->can('create', EventFeature::class) === true))
                ->createOptionUsing(function (array $data): int {
                    Gate::authorize('create', EventFeature::class);

                    return (int) EventFeature::create($data)->getKey();
                }),
            Repeater::make('content_details.practical_custom')->label('Altre informazioni per questo evento')->defaultItems(0)->maxItems(12)->addActionLabel('Aggiungi una voce libera')->columnSpanFull()->columns(2)->schema([
                TextInput::make('label')->label('Titolo')->required()->maxLength(120),
                Select::make('icon')->label('Icona')->options(PracticalIcons::previews())->allowHtml()->searchable()->required()->default('check-circle')->rules([Rule::in(array_keys(PracticalIcons::options()))]),
                Textarea::make('text')->label('Dettagli')->maxLength(1000)->rows(2)->columnSpanFull(),
            ])->helperText('Usa queste voci per indicazioni specifiche di questo evento. Per caratteristiche riutilizzabili usa il catalogo qui sopra.')
                ->collapseAllAction(fn (Action $action) => $action->button()->icon('heroicon-o-chevron-up'))
                ->expandAllAction(fn (Action $action) => $action->button()->icon('heroicon-o-chevron-down'))
                ->collapsible()->itemLabel(fn (array $state): ?string => $state['label'] ?? null),
        ]);
    }
}
