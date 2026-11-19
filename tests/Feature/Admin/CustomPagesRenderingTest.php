<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Carpool;
use App\Filament\Admin\Pages\Community;
use App\Filament\Admin\Pages\SeoOverview;
use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Support\BeforeGoingFields;
use App\Filament\Support\DescriptionEditor;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/**
 * Le pagine personalizzate della redazione usano i componenti dei pannelli:
 * schede invece di tendine, campi con il bordo, card, paginazione, stato
 * vuoto; la motivazione si scrive nella finestra dell'azione. E i moduli
 * stanno in una colonna, con gli editor a tutta larghezza.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->city = City::factory()->padova()->create();
    freezeLocal($this->city, '2026-11-10 12:00');
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function moderationMember(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->communityProfile()->create(['handle' => 'membro_'.$user->id, 'display_name' => $name, 'visibility' => 'public']);

    return $user;
}

it('renders the community moderation page with tabs, a bordered search field and an empty state', function (): void {
    $this->get(Community::getUrl())->assertOk()
        ->assertSee(__('admin.community_page.sections.profiles'))
        ->assertSee(__('admin.community_page.sections.restrictions'))
        ->assertSee('fi-input-wrp', false)
        ->assertSee(__('admin.community_page.empty.profiles'))
        ->assertDontSee('bg-white p-3 text-gray-950', false);
});

it('asks for the reason inside the action window before suspending a member', function (): void {
    $member = moderationMember('Persona da sospendere');

    $page = Livewire::test(Community::class)
        ->assertSee('Persona da sospendere');

    $page->callAction('suspendUser', data: ['reason' => ''], arguments: ['id' => $member->id, 'enabled' => false])
        ->assertHasActionErrors(['reason' => 'required']);
    expect($member->fresh()->community_suspended_at)->toBeNull();

    Livewire::test(Community::class)
        ->callAction('suspendUser', data: ['reason' => 'Messaggi offensivi ripetuti'], arguments: ['id' => $member->id, 'enabled' => false])
        ->assertHasNoActionErrors();
    expect($member->fresh()->community_suspended_at)->not->toBeNull();
});

it('searches and paginates the community lists in Italian', function (): void {
    foreach (range(1, 27) as $index) {
        moderationMember('Membro '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
    }

    Livewire::test(Community::class)
        ->assertSee('fi-pagination', false)
        ->assertDontSee('Membro 01')
        ->set('search', 'Membro 01')
        ->assertSee('Membro 01')
        ->assertDontSee('Membro 27')
        ->set('section', 'restrictions')
        ->assertSee(__('admin.community_page.empty.restrictions'))
        ->set('section', 'qualsiasi')
        ->assertSet('section', 'profiles');
});

it('renders the carpool safety page with tabs and filters for the editorial staff', function (): void {
    $this->get(Carpool::getUrl())->assertOk()
        ->assertSee(__('carpool.admin.cases'))
        ->assertSee(__('carpool.admin.deliveries'))
        ->assertSee('fi-input-wrp', false)
        ->assertSee(__('admin.carpool_page.empty'))
        ->assertDontSee('bg-white p-3 text-gray-950', false);

    Livewire::test(Carpool::class)
        ->set('section', 'rides')
        ->assertSee(__('carpool.admin.status'))
        ->set('section', 'audit')
        ->assertSee(__('admin.carpool_page.empty'));
});

it('pages the SEO audit and shows only the events to fix by default', function (): void {
    $category = Category::factory()->create(['default_duration_minutes' => 120]);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    foreach (range(1, 25) as $index) {
        $event = Event::factory()->create([
            'city_id' => $this->city->id,
            'category_id' => $category->id,
            'venue_id' => $venue->id,
            'status' => 'published',
            'published_at' => now(),
            'title' => 'Evento senza locandina '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'description' => null,
            'short_description' => null,
        ]);
        EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => '2026-11-20 19:00:00', 'ends_at' => null]);
    }

    $page = Livewire::test(SeoOverview::class)
        ->assertSet('filter', 'issues')
        ->assertSee(__('admin.seo_page.filters.issues'))
        ->assertSee(__('seo.audit.description'))
        ->assertSee('fi-pagination', false);

    expect(substr_count($page->html(), 'data-seo-row'))->toBe(SeoOverview::PER_PAGE);

    $page->call('gotoPage', 2)
        ->assertSee('Evento senza locandina');
    expect(substr_count($page->html(), 'data-seo-row'))->toBe(5);

    $page->set('filter', 'all')->assertSet('paginators.page', 1);
});

it('keeps the event feature form in one column and the before-going editors full width', function (): void {
    $schema = EventFeatureResource::form(Schema::make());
    expect($schema->getColumns('lg'))->toBe(1);

    $fields = collect(BeforeGoingFields::make(true)->getDefaultChildComponents())
        ->filter(fn ($component): bool => $component instanceof DescriptionEditor || $component instanceof Textarea);

    expect($fields)->not->toBeEmpty()
        ->and($fields->every(fn ($field): bool => $field->getColumnSpan('default') === 'full'))->toBeTrue();
});

it('puts the venue main data first and the secondary blocks inside the tabs', function (): void {
    $components = array_values(VenueResource::form(Schema::make())->getComponents());

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(Tabs::class);

    $labels = collect($components[0]->getDefaultChildComponents())->map(fn ($tab): string => (string) $tab->getLabel())->all();
    expect($labels[0])->toBe(__('admin.form_tabs.identity'))
        ->and($labels)->toContain(__('admin.form_tabs.before_going'), __('admin.form_tabs.content_seo'));

    expect(collect($components)->filter(fn ($component): bool => $component instanceof Section))->toBeEmpty();
});
