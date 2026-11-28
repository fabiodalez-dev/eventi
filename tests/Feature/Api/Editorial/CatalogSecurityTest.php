<?php

declare(strict_types=1);

use App\Actions\MergeTagsAction;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Cities\Pages\EditCity;
use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Filament\Admin\Resources\Tags\Pages\EditTag;
use App\Filament\Admin\Resources\Tags\Pages\ListTags;
use App\Models\Category;
use App\Models\City;
use App\Models\EventFeature;
use App\Models\Page;
use App\Models\Tag;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R11 — la revisione di sicurezza della seconda ondata sul catalogo:
 * l'eliminazione definitiva resta solo sul sito (decisione 4) e lascia una
 * riga nel registro di sicurezza; creare una città chiede la password
 * recente; modifiche delle città e unioni dei tag vanno nel registro (dal
 * pannello e dall'app); due unioni incrociate non perdono entrambi i tag; il
 * corpo delle pagine ha un tetto e il Markdown un limite di annidamento.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

/** Le righe del registro di sicurezza con quel nome. */
function catalogSecurityRows(string $event): Collection
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $event)->orderBy('id')->get();
}

it('non ha più rotte per eliminare le voci del catalogo dall\'app e manda a eliminarle sul sito', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route): ?string => $route->getName())->filter()
        ->filter(fn (string $name): bool => str_starts_with($name, 'api.v1.editorial.catalog.') && str_ends_with($name, '.destroy'));
    expect($names->all())->toBe([]);

    $page = Page::factory()->create(['slug' => 'chi-siamo']);
    $feature = EventFeature::query()->create(['name' => 'Guardaroba', 'group' => 'Servizi', 'icon' => 'shopping-bag']);
    $tag = Tag::factory()->create(['name' => 'Jazz', 'slug' => 'jazz']);
    $records = [
        'categories' => [$this->s->category, 'admin/categories/'.$this->s->category->slug.'/edit'],
        'tags' => [$tag, 'admin/tags/jazz/edit'],
        'event-features' => [$feature, 'admin/event-features/'.$feature->id.'/edit'],
        'cities' => [$this->s->city, 'admin/cities/'.$this->s->city->slug.'/edit'],
        'pages' => [$page, 'admin/pages/chi-siamo/edit'],
    ];
    $super = EditorialSession::as($this, EditorialSession::token($this->superAdmin));
    foreach ($records as $path => [$record, $webPath]) {
        $super->deleteJson('/api/v1/editorial/'.$path.'/'.$record->id)->assertStatus(405);
        expect($record::query()->whereKey($record->id)->exists())->toBeTrue();
        $row = collect($super->getJson('/api/v1/editorial/'.$path.'?per_page=50')->assertOk()->json('data'))->firstWhere('id', $record->id);
        expect($row['abilities'])->not->toHaveKey('destroy')
            ->and($row['abilities']['delete_on_web'])->toBeTrue()
            ->and($row['web_path'])->toBe($webPath);
    }
});

it('scrive nel registro l\'eliminazione di una voce dal pannello, anche in gruppo, con gli eventi del tag', function (): void {
    $this->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $tag = Tag::factory()->create(['name' => 'Jazz', 'slug' => 'jazz']);
    $this->s->publishedEventA->tags()->attach($tag);

    $this->get('/admin/tags/jazz/edit')->assertOk();
    Livewire::test(EditTag::class, ['record' => 'jazz'])->callAction('delete');

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeFalse();
    $row = catalogSecurityRows('catalogo_voce_eliminata')->sole();
    expect($row->properties->get('sezione'))->toBe('tags')
        ->and($row->properties->get('id'))->toBe($tag->id)
        ->and($row->properties->get('nome'))->toBe('Jazz')
        ->and($row->properties->get('slug'))->toBe('jazz')
        ->and($row->properties->get('eventi_toccati'))->toBe(1)
        ->and($row->properties->get('eventi'))->toBe([$this->s->publishedEventA->id])
        ->and($row->causer_id)->toBe($this->s->admin->id);

    $pages = Page::factory()->count(2)->create();
    $this->get('/admin/pages')->assertOk();
    Livewire::test(ListPages::class)->callTableBulkAction('delete', $pages->all());
    expect(Page::query()->whereKey($pages->modelKeys())->count())->toBe(0)
        ->and(catalogSecurityRows('catalogo_voce_eliminata')->where('properties.sezione', 'pages')->pluck('properties.id')->sort()->values()->all())
        ->toBe(collect($pages->modelKeys())->sort()->values()->all());
});

it('non scrive l\'eliminazione se la voce è ancora usata e non si elimina', function (): void {
    expect(fn () => $this->s->category->delete())->toThrow(QueryException::class);
    expect(Category::query()->whereKey($this->s->category->id)->exists())->toBeTrue()
        ->and(catalogSecurityRows('catalogo_voce_eliminata'))->toHaveCount(0);
});

it('chiede la password recente anche per creare una città dall\'app', function (): void {
    $body = [
        'name' => 'Vicenza', 'province_code' => 'VI', 'province_name' => 'Vicenza', 'region' => 'Veneto', 'country_code' => 'IT',
        'center_lat' => 45.55, 'center_lng' => 11.55, 'default_zoom' => 13, 'radius_km' => 15,
        'timezone' => 'Europe/Rome', 'night_cutoff_time' => '05:00', 'starting_soon_minutes' => 60, 'locale' => 'it', 'is_active' => true,
    ];
    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->postJson('/api/v1/editorial/cities', $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect(City::query()->where('name', 'Vicenza')->exists())->toBeFalse();

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/cities', $body)->assertCreated();
    expect(City::query()->where('name', 'Vicenza')->exists())->toBeTrue();
});

it('scrive nel registro la modifica di una città, dall\'app e dal pannello, con il valore di prima e quello nuovo', function (): void {
    $city = $this->s->city;
    $before = substr((string) $city->night_cutoff_time, 0, 5);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson('/api/v1/editorial/cities/'.$city->id, [
        'name' => $city->name, 'slug' => $city->slug, 'province_code' => 'PD', 'province_name' => 'Padova', 'region' => 'Veneto', 'country_code' => 'IT',
        'center_lat' => 45.4064, 'center_lng' => 11.8768, 'default_zoom' => 12, 'radius_km' => 30,
        'bounds' => ['north' => 45.7, 'south' => 45.4, 'east' => 11.7, 'west' => 11.3],
        'timezone' => 'Europe/Rome', 'night_cutoff_time' => '03:30', 'starting_soon_minutes' => 120, 'locale' => 'it', 'is_active' => true,
        'settings' => [['key' => 'community', 'enabled' => true], ['key' => 'carpool', 'enabled' => false]],
    ])->assertOk();

    $row = catalogSecurityRows('catalogo_citta_modificata')->sole();
    expect($row->properties->get('citta_id'))->toBe($city->id)
        ->and($row->properties->get('canale'))->toBe('app')
        ->and($row->causer_id)->toBe($this->s->admin->id)
        ->and(substr((string) $row->properties->get('campi')['night_cutoff_time']['prima'], 0, 5))->toBe($before)
        ->and(substr((string) $row->properties->get('campi')['night_cutoff_time']['dopo'], 0, 5))->toBe('03:30')
        ->and($row->properties->get('campi'))->not->toHaveKey('updated_at');

    $this->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/cities/'.$city->slug.'/edit')->assertOk();
    Livewire::test(EditCity::class, ['record' => $city->slug])
        ->fillForm(['starting_soon_minutes' => 45])
        ->call('save')
        ->assertHasNoFormErrors();

    $panel = catalogSecurityRows('catalogo_citta_modificata')->last();
    expect(catalogSecurityRows('catalogo_citta_modificata'))->toHaveCount(2)
        ->and($panel->properties->get('campi')['starting_soon_minutes']['dopo'])->toBe(45)
        ->and($panel->properties->get('canale'))->toBeNull()
        ->and($panel->causer_id)->toBe($this->s->admin->id);
});

it('scrive nel registro l\'unione di due tag, dall\'app e dal pannello, senza una riga di eliminazione in più', function (): void {
    $keep = Tag::factory()->create(['name' => 'Musica dal vivo']);
    $absorbed = Tag::factory()->create(['name' => 'Live music']);
    $this->s->publishedEventA->tags()->attach($absorbed);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/tags/'.$keep->id.'/merge', ['absorbed_id' => $absorbed->id])->assertOk();

    $row = catalogSecurityRows('tag_uniti')->sole();
    expect($row->properties->get('tag_id'))->toBe($keep->id)
        ->and($row->properties->get('assorbito_id'))->toBe($absorbed->id)
        ->and($row->properties->get('assorbito'))->toBe('Live music')
        ->and($row->properties->get('eventi_spostati'))->toBe(1)
        ->and($row->properties->get('eventi'))->toBe([$this->s->publishedEventA->id])
        ->and($row->properties->get('canale'))->toBe('app')
        ->and(catalogSecurityRows('catalogo_voce_eliminata'))->toHaveCount(0);

    $this->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $other = Tag::factory()->create(['name' => 'Concerto']);
    $this->get('/admin/tags')->assertOk();
    Livewire::test(ListTags::class)->callTableAction('merge', $keep, ['absorbed' => $other->id])->assertHasNoTableActionErrors();
    expect(catalogSecurityRows('tag_uniti'))->toHaveCount(2)
        ->and(catalogSecurityRows('tag_uniti')->last()->causer_id)->toBe($this->s->admin->id);
});

it('non perde i due tag quando «A in B» e «B in A» arrivano insieme', function (): void {
    $a = Tag::factory()->create(['name' => 'Teatro']);
    $b = Tag::factory()->create(['name' => 'Prosa']);
    $this->s->publishedEventA->tags()->attach($b);
    // La seconda richiesta ha letto i due tag prima che la prima finisse.
    $staleA = Tag::query()->findOrFail($a->id);
    $staleB = Tag::query()->findOrFail($b->id);

    app(MergeTagsAction::class)->execute($a, $b);

    expect(fn () => app(MergeTagsAction::class)->execute($staleB, $staleA))->toThrow(ValidationException::class);
    expect(Tag::query()->whereKey($a->id)->exists())->toBeTrue()
        ->and($this->s->publishedEventA->tags()->pluck('tags.id')->all())->toBe([$a->id]);

    // Dall'app l'unione con un tag che non c'è più è un 422, non un 500.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/tags/'.$a->id.'/merge', ['absorbed_id' => $b->id])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['absorbed_id']]]);
});

it('mette un tetto al corpo delle pagine, dall\'app e dal pannello', function (): void {
    $long = str_repeat('a', Page::BODY_MAX + 1);
    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $admin->postJson('/api/v1/editorial/pages', ['title' => 'Lunga', 'body' => $long])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['body']]]);
    expect(Page::query()->where('title', 'Lunga')->exists())->toBeFalse();
    $admin->postJson('/api/v1/editorial/pages', ['title' => 'Giusta', 'body' => str_repeat('a', Page::BODY_MAX)])->assertCreated();

    $this->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/pages/create')->assertOk();
    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'Lunga dal pannello', 'body' => $long])
        ->call('create')
        ->assertHasFormErrors(['body' => 'max']);
});

it('non annida il Markdown delle pagine oltre il limite', function (): void {
    $body = str_repeat('> ', 80).'fondo';
    $html = (new Page(['body' => $body]))->renderedBody()->toHtml();

    expect(substr_count($html, '<blockquote>'))->toBeLessThanOrEqual(Page::MAX_NESTING)
        ->and($html)->toContain('fondo');
    // L'anteprima dell'app passa dallo stesso convertitore.
    $preview = EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/pages/preview', ['body' => $body])->assertOk()->json('data.html');
    expect(substr_count($preview, '<blockquote>'))->toBeLessThanOrEqual(Page::MAX_NESTING);
});
