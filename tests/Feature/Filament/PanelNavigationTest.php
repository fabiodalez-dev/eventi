<?php

declare(strict_types=1);

use App\Filament\Organizer\Pages\Dashboard;
use App\Filament\Support\PanelNavigation;
use App\Filament\Venue\Pages\Statistics;
use App\Filament\Venue\Pages\VenueAccess;
use App\Models\Organizer;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Tests\Support\VenueIsolationScenario;

/*
 * Il menu laterale dei tre pannelli (`PanelNavigation`): stessi gruppi con lo
 * stesso nome, il nome di ogni voce dato dalla pagina, nessuna icona ripetuta,
 * e una voce solo per chi può aprire la pagina. Ogni prova apre prima la
 * pagina: è la richiesta HTTP che imposta pannello e locale.
 */

/** @return list<NavigationGroup> */
function panelNavigation(): array
{
    return array_values(Filament::getNavigation());
}

/** @return list<NavigationItem> */
function navigationItems(): array
{
    return collect(panelNavigation())->flatMap(fn (NavigationGroup $group): array => array_values(collect($group->getItems())->all()))->values()->all();
}

/** @return list<string> */
function navigationGroupLabels(): array
{
    return collect(panelNavigation())->map(fn (NavigationGroup $group): string => (string) $group->getLabel())->all();
}

/** Ogni voce ha il nome della sua pagina, un'icona sua e nessuna voce figlia. */
function assertWellFormedNavigation(): void
{
    $items = navigationItems();
    $icons = collect($items)->map(fn (NavigationItem $item): string => $item->getIcon() instanceof BackedEnum ? $item->getIcon()->value : (string) $item->getIcon());

    expect($icons->duplicates()->all())->toBe([]);

    foreach ($items as $item) {
        expect($item->getParentItem())->toBeNull()
            ->and($item->getChildItems())->toBeEmpty()
            ->and($item->getUrl())->not->toBeEmpty();

        if (class_exists($item->getKey())) {
            expect($item->getLabel())->toBe($item->getKey()::getNavigationLabel());
        }
    }
}

it('dà alla redazione i gruppi nell’ordine giusto, con Catalogo e Sistema chiusi', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->admin)->get('/admin')->assertOk();

    expect(navigationGroupLabels())->toBe(['', 'Eventi', 'Locali e persone', 'Community e moderazione', 'Promozione', 'Statistiche', 'Catalogo', 'Sistema']);

    foreach (panelNavigation() as $group) {
        expect($group->isCollapsed())->toBe(in_array($group->getLabel(), ['Catalogo', 'Sistema'], true));
    }

    assertWellFormedNavigation();
    expect(collect(panelNavigation()[0]->getItems())->map->getLabel()->all())->toBe(['Riepilogo']);
});

it('mostra al moderatore solo le voci che può aprire, e nessun gruppo vuoto', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->moderator)->get('/admin')->assertOk();

    $shown = collect(navigationItems())->map->getKey()->all();
    $allowed = collect(PanelNavigation::classes('admin'))
        ->filter(fn (string $class): bool => $class::canAccess())
        ->values()->all();

    expect($shown)->toBe($allowed)
        ->and(collect(panelNavigation())->every(fn (NavigationGroup $group): bool => collect($group->getItems())->isNotEmpty()))->toBeTrue();
    assertWellFormedNavigation();
});

it('dà al locale gli stessi gruppi, tutti aperti, con «Il tuo accesso» in fondo', function (string $role): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->{$role})->get('/gestione/'.$scenario->venueA->slug)->assertOk();

    expect(navigationGroupLabels())->toBe(['', 'Eventi', 'Statistiche', 'Cosa dice la gente', 'Locale e persone'])
        ->and(collect(panelNavigation())->contains(fn (NavigationGroup $group): bool => $group->isCollapsed()))->toBeFalse();
    assertWellFormedNavigation();

    $items = navigationItems();
    expect(end($items)->getKey())->toBe(VenueAccess::class)
        ->and(collect($items)->contains(fn (NavigationItem $item): bool => $item->getLabel() === __('pannelli-impianto.tickets')))->toBe($role === 'ownerA');
})->with(['ownerA', 'editorA']);

it('dà all’organizzatore gli stessi nomi di gruppo del locale e il profilo solo al responsabile', function (): void {
    $scenario = VenueIsolationScenario::make();
    $collaborator = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);
    $organizer->users()->attach($collaborator);

    $this->actingAs($scenario->ownerA)->get('/organizza/'.$organizer->slug)->assertOk()->assertDontSee('fi-tenant-menu', false);
    expect(navigationGroupLabels())->toBe(['', 'Eventi', 'Statistiche', 'Cosa dice la gente', 'Organizzazione']);
    assertWellFormedNavigation();

    $this->actingAs($collaborator)->get('/organizza/'.$organizer->slug)->assertOk();
    expect(navigationGroupLabels())->toBe(['', 'Eventi', 'Statistiche', 'Cosa dice la gente']);
    assertWellFormedNavigation();
});

it('mostra il selettore dell’organizzazione solo a chi ne gestisce più di una', function (): void {
    $scenario = VenueIsolationScenario::make();
    $first = Organizer::create(['name' => 'Collettivo Portici', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);

    $this->actingAs($scenario->ownerA)->get('/organizza/'.$first->slug)->assertOk()->assertDontSee('fi-tenant-menu', false);

    Organizer::create(['name' => 'Rassegna d’autunno', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);
    $this->actingAs($scenario->ownerA)->get('/organizza/'.$first->slug)->assertOk()->assertSee('fi-tenant-menu', false);
});

it('ha in mappa ogni pagina che i tre pannelli mettono nel menu', function (string $panel): void {
    $registered = collect([...Filament::getPanel($panel)->getPages(), ...Filament::getPanel($panel)->getResources()])
        ->filter(fn (string $class): bool => $class::shouldRegisterNavigation())
        ->values();

    expect($registered->diff(PanelNavigation::classes($panel))->values()->all())->toBe([]);
})->with(['admin', 'venue', 'organizer']);

it('dà a una pagina la stessa icona che ha nel menu', function (): void {
    expect(PanelNavigation::icon(Statistics::class)->value)->toBe('o-chart-bar')
        ->and(PanelNavigation::icon(Dashboard::class)->value)->toBe('o-home');
});
