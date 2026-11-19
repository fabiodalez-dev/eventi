<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Tags\Pages\ListTags;
use App\Models\EventOccurrence;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\View\Components\ModalComponent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * I valori di partenza di tabelle, filtri e finestre dei pannelli
 * (`FilamentDefaultsServiceProvider`): valgono per ogni elenco che non
 * dichiara i suoi.
 */

/** Una tabella di prova delle date con una colonna data e ora senza formato. */
class PanelDefaultsProbeTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public static bool $ownEmptyState = false;

    public function table(Table $table): Table
    {
        $table = $table
            ->query(EventOccurrence::query())
            ->columns([TextColumn::make('starts_at')->dateTime()]);

        return self::$ownEmptyState ? $table->emptyStateHeading('Ancora nessuna data in calendario') : $table;
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}

it('dà agli elenchi «Filtri» con la parola, uno stato vuoto che parla e nessuna briciola «Lista»', function (): void {
    $scenario = VenueIsolationScenario::make();
    Tag::factory()->create(['name' => 'Jazz']);

    $page = $this->actingAs($scenario->admin)->get('/admin/tags')->assertOk();
    $page->assertSee('Cerca per titolo o nome')
        ->assertDontSee('placeholder="Ricerca"', false);
    expect($page->getContent())->not->toMatch('/fi-breadcrumbs-item-label[^>]*>\s*Lista\s*</');

    Livewire::test(ListTags::class)
        ->assertSee('Filtri')
        ->assertSeeHtml('data-active-filters="0"')
        ->searchTable('nessuna-parola-cosi')
        ->assertSee("Qui non c'è ancora niente")
        ->assertSee('Se hai messo un filtro o una ricerca, prova a toglierli.')
        ->assertDontSee('Nessun risultato');
});

it('scrive in italiano le date senza formato dichiarato, nel fuso di Roma', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin)->get('/admin')->assertOk();
    EventOccurrence::query()->delete();
    EventOccurrence::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-19 00:34:00', 'UTC')]);

    PanelDefaultsProbeTable::$ownEmptyState = false;
    Livewire::test(PanelDefaultsProbeTable::class)->assertSee('gio 19 nov 2026, 01:34');
});

it('lascia a una tabella il suo stato vuoto', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin)->get('/admin')->assertOk();
    EventOccurrence::query()->delete();

    PanelDefaultsProbeTable::$ownEmptyState = true;
    Livewire::test(PanelDefaultsProbeTable::class)
        ->assertSee('Ancora nessuna data in calendario')
        ->assertDontSee("Qui non c'è ancora niente");
    PanelDefaultsProbeTable::$ownEmptyState = false;
});

it('dice «Conferma» e non «Invia», e una finestra non si chiude con un clic fuori', function (): void {
    expect(__('filament-actions::modal.actions.submit.label'))->toBe('Conferma')
        ->and(__('filament-actions::modal.confirmation'))->toBe('Vuoi davvero farlo?')
        ->and(__('filament-panels::resources/pages/list-records.breadcrumb'))->toBe('Elenco')
        ->and(__('filament-panels::global-search.field.placeholder'))->toBe('Cerca per titolo o nome')
        ->and(__('filament-panels::global-search.no_results_message'))->toBe('Non ho trovato niente. Prova con un\'altra parola.')
        ->and(__('filament-tables::table.filters.actions.reset.label'))->toBe('Togli i filtri')
        ->and(ModalComponent::$isClosedByClickingAway)->toBeFalse();
});

it('apre i filtri in un pannello laterale', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin)->get('/admin/tags')->assertOk();
    Filament::setCurrentPanel('admin');

    $table = Livewire::test(ListTags::class)->instance()->getTable();

    expect($table->getFiltersLayout())->toBe(FiltersLayout::Modal)
        ->and($table->getFiltersTriggerAction()->isModalSlideOver())->toBeTrue()
        ->and($table->getFiltersTriggerAction()->getLabel())->toBe('Filtri');
});
