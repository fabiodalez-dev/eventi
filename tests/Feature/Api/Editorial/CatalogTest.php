<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\City;
use App\Models\EventFeature;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Tag;
use App\Services\Editorial\Catalog\CatalogPages;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R11 — il catalogo nell'app della redazione (R-CT-01…07): categorie, tag
 * (con l'unione dei doppioni), caratteristiche degli eventi, città e pagine,
 * con gli stessi permessi, le stesse regole e gli stessi tetti delle cinque
 * risorse del gruppo «Contenuti» del pannello. La griglia: moderatore,
 * admin, super admin, referente di un locale, utente comune, token non
 * sbloccato (423), password non recente sulle azioni pericolose (423).
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->staff = [$this->s->moderator, $this->s->admin, $this->superAdmin];
    $this->writers = [$this->s->admin, $this->superAdmin];
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
});

/** Lo stesso controllo per ogni indirizzo: fuori la gente comune (404), chiuso senza sblocco o con lo sblocco scaduto (423). */
function r11Gate(mixed $test, string $method, string $url, array $body = []): void
{
    foreach ($test->outsiders as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->moderator, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->s->admin, unlockedHoursAgo: 12))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

/** Le rotte pericolose: senza la password degli ultimi 15 minuti 423 `PASSWORD_REQUIRED`, anche per il super admin. */
function r11NeedsPassword(mixed $test, string $method, string $url, array $body = []): void
{
    EditorialSession::as($test, EditorialSession::token($test->superAdmin, passwordMinutesAgo: 16))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
}

/** @return array<string, mixed> */
function r11CityBody(array $override = []): array
{
    return [
        'name' => 'Vicenza',
        'province_code' => 'VI',
        'province_name' => 'Vicenza',
        'region' => 'Veneto',
        'country_code' => 'IT',
        'center_lat' => 45.5455,
        'center_lng' => 11.5354,
        'default_zoom' => 12,
        'radius_km' => 30,
        'bounds' => ['north' => 45.7, 'south' => 45.4, 'east' => 11.7, 'west' => 11.3],
        'timezone' => 'Europe/Rome',
        'night_cutoff_time' => '05:30',
        'starting_soon_minutes' => 120,
        'locale' => 'it',
        'is_active' => false,
        'settings' => [['key' => 'community', 'enabled' => true], ['key' => 'carpool', 'enabled' => false]],
        ...$override,
    ];
}

// ------------------------------------------------------------ ingresso

it('mostra a ogni ruolo le voci del catalogo che il pannello gli mostra', function (): void {
    r11Gate($this, 'GET', '/api/v1/editorial/catalog');

    $keys = fn ($person): array => collect(EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/catalog')
        ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->json('data.sections'))->pluck('can_create', 'key')->all();

    // Il moderatore legge categorie, tag e città; non crea niente e non vede caratteristiche e pagine.
    expect($keys($this->s->moderator))->toBe(['categories' => false, 'tags' => false, 'cities' => false]);
    foreach ($this->writers as $writer) {
        expect($keys($writer))->toBe(['categories' => true, 'tags' => true, 'event_features' => true, 'cities' => true, 'pages' => true]);
    }
});

it('dà le scelte dei moduli solo a chi può scrivere nel catalogo', function (): void {
    r11Gate($this, 'GET', '/api/v1/editorial/catalog/choices');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/catalog/choices')->assertForbidden();

    $data = EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson('/api/v1/editorial/catalog/choices')->assertOk()->json('data');
    expect($data['categories'])->toContain(['value' => (string) $this->s->category->id, 'label' => $this->s->category->name])
        ->and($data['cities'][0]['label'])->toBe($this->s->city->name)
        ->and(array_column($data['feature_groups'], 'value'))->toBe(EventFeature::GROUPS)
        ->and(array_column($data['feature_icons'], 'value'))->toContain('check-circle', 'ticket')
        ->and($data['timezones'])->toContain('Europe/Rome')
        ->and($data['locales'])->toBe(['it', 'en', 'de'])
        ->and($data['limits'])->toBe(['text' => 5000, 'faqs' => 30, 'seo_title' => 180, 'seo_description' => 400]);
});

// ------------------------------------------------------------ categorie

it('elenca le categorie a tutta la redazione con le colonne, i filtri e l\'ordine del pannello', function (): void {
    // La categoria dello scenario esce a caso dalla fabbrica: qui non è da sera, così il filtro conta una riga sola.
    $this->s->category->update(['sort_order' => 10, 'is_nightlife' => false]);
    Category::factory()->create(['name' => 'Discoteche', 'sort_order' => 0, 'is_nightlife' => true, 'is_active' => true, 'parent_id' => $this->s->category->id]);
    Category::factory()->create(['name' => 'Spenta', 'sort_order' => 50, 'is_active' => false]);

    r11Gate($this, 'GET', '/api/v1/editorial/categories');

    foreach ($this->staff as $person) {
        $response = EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/categories')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 24);
        $first = $response->json('data.0');
        // Ordine predefinito `sort_order`, come la tabella del pannello.
        expect($first['name'])->toBe('Discoteche')
            ->and($first['parent'])->toBe(['id' => $this->s->category->id, 'name' => $this->s->category->name])
            ->and($first['is_nightlife'])->toBeTrue()
            ->and($first)->toHaveKeys(['default_duration_minutes', 'supports_ongoing', 'events_count', 'is_active'])
            ->and($first['abilities'])->toBe(['update' => $person !== $this->s->moderator, 'delete_on_web' => $person !== $this->s->moderator])
            // Eliminare si fa sul sito (decisione 4): la riga porta la pagina del pannello.
            ->and($first['web_path'])->toBe('admin/categories/discoteche/edit');
    }

    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $admin->getJson('/api/v1/editorial/categories?filter[is_active]=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Spenta');
    $admin->getJson('/api/v1/editorial/categories?filter[is_nightlife]=1')->assertOk()->assertJsonPath('meta.total', 1);
    $admin->getJson('/api/v1/editorial/categories?q=disco')->assertOk()->assertJsonPath('meta.total', 1);
    $admin->getJson('/api/v1/editorial/categories?sort=-name')->assertOk()->assertJsonPath('data.0.name', 'Spenta');
    // Il conteggio degli eventi della categoria dello scenario.
    $row = collect($admin->getJson('/api/v1/editorial/categories')->json('data'))->firstWhere('id', $this->s->category->id);
    expect($row['events_count'])->toBe($this->s->category->events()->count());
});

it('crea e modifica una categoria come admin e super admin, mai come moderatore, e non la elimina dall\'app', function (): void {
    $body = [
        'name' => 'Laboratori per bambini',
        'sort_order' => 3,
        'default_duration_minutes' => 90,
        'supports_ongoing' => false,
        'is_nightlife' => false,
        'color' => '#ff8800',
        'icon' => 'sparkles',
        'content_details' => [
            'introduction' => '<p>Ciao <script>alert(1)</script><strong>bimbi</strong></p>',
            'city_introductions' => [['city_id' => $this->s->city->id, 'text' => '<p>A Padova</p>']],
            'faqs' => [['question' => 'Serve prenotare?', 'answer' => '<p>Quasi sempre.</p>']],
        ],
        'seo' => ['title' => 'Laboratori', 'description' => 'Per i più piccoli', 'image' => 'https://example.test/a.jpg', 'indexing' => 'excluded'],
        'is_demo' => true,
    ];

    r11Gate($this, 'POST', '/api/v1/editorial/categories', $body);
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson('/api/v1/editorial/categories', $body)->assertForbidden();

    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $data = $admin->postJson('/api/v1/editorial/categories', $body)->assertCreated()->json('data');
    $category = Category::query()->findOrFail($data['id']);
    expect($category->slug)->toBe('laboratori-per-bambini')
        ->and($category->is_active)->toBeTrue()
        ->and($category->supports_ongoing)->toBeFalse()
        ->and($category->default_duration_minutes)->toBe(90)
        ->and($category->content_details['introduction'])->not->toContain('script')->toContain('<strong>bimbi</strong>')
        ->and($category->content_details['city_introductions'][0]['city_id'])->toBe($this->s->city->id)
        ->and($category->seo['indexing'])->toBe('excluded')
        ->and($category->is_demo)->toBeTrue()
        ->and($data['content_details']['faqs'][0]['question'])->toBe('Serve prenotare?');

    // Il moderatore non apre il modulo e non modifica.
    $moderator = EditorialSession::as($this, EditorialSession::token($this->s->moderator));
    $moderator->getJson('/api/v1/editorial/categories/'.$category->id)->assertForbidden();
    $moderator->patchJson('/api/v1/editorial/categories/'.$category->id, ['name' => 'X', 'sort_order' => 1])->assertForbidden();

    $super = EditorialSession::as($this, EditorialSession::token($this->superAdmin));
    $super->getJson('/api/v1/editorial/categories/'.$category->id)->assertOk()->assertJsonPath('data.parent_id', null);
    $super->patchJson('/api/v1/editorial/categories/'.$category->id, ['name' => 'Laboratori', 'slug' => 'laboratori', 'sort_order' => 4, 'parent_id' => $this->s->category->id])
        ->assertOk()->assertJsonPath('data.parent.id', $this->s->category->id);
    expect($category->refresh()->slug)->toBe('laboratori')
        // Le chiavi non mandate restano: il modulo dell'app le manda tutte, un'altra strada può mandarne solo alcune.
        ->and($category->content_details['faqs'])->toHaveCount(1)
        // Il cambio di indirizzo lascia il reindirizzamento della lista, come dal pannello (`CategoryObserver`).
        ->and(Redirect::query()->where('from_path', '/eventi/categoria/laboratori-per-bambini')->exists())->toBeTrue();

    // Eliminare resta sul sito (decisione 4): nell'app la rotta non c'è.
    EditorialSession::as($this, EditorialSession::token($this->superAdmin))->deleteJson('/api/v1/editorial/categories/'.$category->id)->assertStatus(405);
    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();
});

it('convalida la categoria con le regole del modulo del pannello', function (): void {
    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $admin->postJson('/api/v1/editorial/categories', ['name' => '', 'sort_order' => 'x', 'default_duration_minutes' => 4, 'slug' => $this->s->category->slug])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['name', 'sort_order', 'default_duration_minutes', 'slug']]]);
    // Non è padre di sé stessa; le presentazioni per città sono una per città.
    $admin->patchJson('/api/v1/editorial/categories/'.$this->s->category->id, [
        'name' => 'Musica', 'sort_order' => 0, 'parent_id' => $this->s->category->id,
        'content_details' => ['city_introductions' => [['city_id' => $this->s->city->id, 'text' => 'a'], ['city_id' => $this->s->city->id, 'text' => 'b']]],
    ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['parent_id', 'content_details.city_introductions.0.city_id']]]);
    // Trenta domande al massimo.
    $admin->patchJson('/api/v1/editorial/categories/'.$this->s->category->id, [
        'name' => 'Musica', 'sort_order' => 0,
        'content_details' => ['faqs' => array_fill(0, 31, ['question' => 'D', 'answer' => 'R'])],
    ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['content_details.faqs']]]);
});

// ------------------------------------------------------------ tag

it('elenca i tag a tutta la redazione e lascia scrivere e unire solo admin e super admin', function (): void {
    $tag = Tag::factory()->create(['name' => 'Jazz', 'is_approved' => false, 'category_id' => $this->s->category->id]);
    Tag::factory()->create(['name' => 'Blues', 'is_approved' => true]);

    r11Gate($this, 'GET', '/api/v1/editorial/tags');
    foreach ($this->staff as $person) {
        $rows = EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/tags')->assertOk()
            ->assertJsonPath('meta.total', 2)->json('data');
        // Ordine predefinito per nome.
        expect(array_column($rows, 'name'))->toBe(['Blues', 'Jazz'])
            ->and($rows[1]['category'])->toBe(['id' => $this->s->category->id, 'name' => $this->s->category->name])
            ->and($rows[1]['abilities'])->toBe(['update' => $person !== $this->s->moderator, 'delete_on_web' => $person !== $this->s->moderator, 'merge' => $person !== $this->s->moderator]);
    }
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson('/api/v1/editorial/tags?filter[is_approved]=0')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Jazz');

    // Approvare è un campo del modulo: il moderatore non apre il modulo, come nel pannello.
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->patchJson('/api/v1/editorial/tags/'.$tag->id, ['name' => 'Jazz', 'is_approved' => true])->assertForbidden();
    expect($tag->refresh()->is_approved)->toBeFalse();

    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $admin->patchJson('/api/v1/editorial/tags/'.$tag->id, ['name' => 'Jazz', 'is_approved' => true, 'synonyms' => ['swing', '', 'bebop'], 'category_id' => null])
        ->assertOk()->assertJsonPath('data.synonyms', ['swing', 'bebop'])->assertJsonPath('data.category', null);
    expect($tag->refresh()->is_approved)->toBeTrue();

    $created = $admin->postJson('/api/v1/editorial/tags', ['name' => 'Musica antica'])->assertCreated()->json('data');
    expect($created['slug'])->toBe('musica-antica')->and($created['is_approved'])->toBeFalse();
});

it('unisce due tag con la stessa azione del pannello e chiede la password recente', function (): void {
    $keep = Tag::factory()->create(['name' => 'Musica dal vivo', 'synonyms' => null]);
    $absorbed = Tag::factory()->create(['name' => 'Live music', 'synonyms' => ['concerto']]);
    $this->s->publishedEventA->tags()->attach($absorbed);
    $url = '/api/v1/editorial/tags/'.$keep->id.'/merge';

    r11Gate($this, 'POST', $url, ['absorbed_id' => $absorbed->id]);
    r11NeedsPassword($this, 'POST', $url, ['absorbed_id' => $absorbed->id]);
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, ['absorbed_id' => $absorbed->id])->assertForbidden();
    // Con sé stesso no: nel pannello il tag non è fra le scelte.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, ['absorbed_id' => $keep->id])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['absorbed_id']]]);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, ['absorbed_id' => $absorbed->id])->assertOk()
        ->assertJsonPath('meta.moved', 1)
        ->assertJsonPath('meta.message', __('admin.notifications.merged', ['count' => 1]))
        ->assertJsonPath('data.synonyms', ['Live music', 'concerto']);
    expect(Tag::query()->whereKey($absorbed->id)->exists())->toBeFalse()
        ->and($this->s->publishedEventA->tags()->pluck('tags.id')->all())->toBe([$keep->id]);
});

it('non unisce se chi chiede non può eliminare il tag assorbito', function (): void {
    $keep = Tag::factory()->create(['name' => 'Musica dal vivo']);
    $absorbed = Tag::factory()->create(['name' => 'Live music']);
    $this->s->publishedEventA->tags()->attach($absorbed);
    /* `TagPolicy::delete` oggi guarda solo il permesso: qui il tag assorbito è protetto e quello che resta no. */
    Gate::before(fn ($user, string $ability, array $arguments): ?bool => $ability === 'delete' && ($arguments[0] ?? null) instanceof Tag && $arguments[0]->is($absorbed) ? false : null);

    foreach ($this->writers as $writer) {
        EditorialSession::as($this, EditorialSession::token($writer))->postJson('/api/v1/editorial/tags/'.$keep->id.'/merge', ['absorbed_id' => $absorbed->id])
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    expect(Tag::query()->whereKey($absorbed->id)->exists())->toBeTrue()
        ->and($this->s->publishedEventA->tags()->pluck('tags.id')->all())->toBe([$absorbed->id])
        ->and($keep->refresh()->synonyms)->toBeEmpty();
});

// ------------------------------------------------------------ caratteristiche

it('lascia le caratteristiche degli eventi solo ad admin e super admin e non elimina quelle di sistema', function (): void {
    $system = EventFeature::query()->create(['name' => 'Tessera', 'group' => 'Ingresso', 'icon' => 'identification']);
    $system->forceFill(['is_system' => true])->save();

    r11Gate($this, 'GET', '/api/v1/editorial/event-features');
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/event-features')->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson('/api/v1/editorial/event-features', ['name' => 'X', 'group' => 'Servizi', 'icon' => 'ticket'])->assertForbidden();

    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $created = $admin->postJson('/api/v1/editorial/event-features', ['name' => 'Guardaroba', 'group' => 'Servizi', 'icon' => 'shopping-bag', 'description' => 'Al piano terra'])
        ->assertCreated()->json('data');
    expect($created['is_active'])->toBeTrue()->and($created['is_system'])->toBeFalse()->and($created['abilities']['delete_on_web'])->toBeTrue()->and($created['web_path'])->toBe('admin/event-features/'.$created['id'].'/edit');
    $admin->postJson('/api/v1/editorial/event-features', ['name' => 'X', 'group' => 'Altro', 'icon' => 'drago'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['group', 'icon']]]);

    $row = collect(EditorialSession::as($this, EditorialSession::token($this->superAdmin))->getJson('/api/v1/editorial/event-features?q=Tessera')->assertOk()->json('data'))->first();
    expect($row['is_system'])->toBeTrue()->and($row['abilities'])->toBe(['update' => true, 'delete_on_web' => false]);

    // «Disponibile» delle voci di sistema non si cambia; il nome sì.
    EditorialSession::as($this, EditorialSession::token($this->superAdmin))->patchJson('/api/v1/editorial/event-features/'.$system->id, ['name' => 'Tessera associativa', 'group' => 'Ingresso', 'icon' => 'identification', 'is_active' => false])
        ->assertOk();
    expect($system->refresh()->is_active)->toBeTrue()->and($system->name)->toBe('Tessera associativa');

    EditorialSession::as($this, EditorialSession::token($this->superAdmin))->deleteJson('/api/v1/editorial/event-features/'.$created['id'])->assertStatus(405);
    expect(EventFeature::query()->whereKey($created['id'])->exists())->toBeTrue();
});

// ------------------------------------------------------------ città

it('elenca le città a tutta la redazione e chiede la password recente per modificarle', function (): void {
    r11Gate($this, 'GET', '/api/v1/editorial/cities');
    foreach ($this->staff as $person) {
        $row = EditorialSession::as($this, EditorialSession::token($person))->getJson('/api/v1/editorial/cities')->assertOk()->json('data.0');
        expect($row['name'])->toBe($this->s->city->name)
            ->and($row['night_cutoff_time'])->toMatch('/^\d\d:\d\d$/')
            ->and($row['venues_count'])->toBe(2)
            ->and($row)->toHaveKeys(['province_name', 'timezone', 'starting_soon_minutes', 'events_count', 'is_active'])
            ->and($row['abilities']['update'])->toBe($person !== $this->s->moderator);
    }

    $url = '/api/v1/editorial/cities/'.$this->s->city->id;
    $body = r11CityBody(['name' => $this->s->city->name, 'slug' => $this->s->city->slug, 'is_active' => true, 'night_cutoff_time' => '04:00']);
    r11Gate($this, 'PATCH', $url, $body);
    r11NeedsPassword($this, 'PATCH', $url, $body);
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->patchJson($url, $body)->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson($url)->assertForbidden();

    $form = EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($url)->assertOk()->json('data');
    expect($form)->toHaveKeys(['center_lat', 'center_lng', 'default_zoom', 'radius_km', 'bounds', 'locale', 'settings', 'content_details', 'seo'])
        ->and($form['bounds'])->toHaveKeys(['north', 'south', 'east', 'west']);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->patchJson($url, $body)->assertOk()->assertJsonPath('data.night_cutoff_time', '04:00');
    $city = $this->s->city->refresh();
    expect(substr((string) $city->night_cutoff_time, 0, 5))->toBe('04:00')
        ->and($city->settings)->toBe(['community' => true, 'carpool' => false])
        ->and($city->bounds)->toBe(['north' => 45.7, 'south' => 45.4, 'east' => 11.7, 'west' => 11.3]);
});

it('crea una città con i valori e i tetti del modulo del pannello, con la password recente', function (): void {
    // Una città nuova e attiva compare subito nel sito: come la modifica, chiede la password recente.
    r11NeedsPassword($this, 'POST', '/api/v1/editorial/cities', r11CityBody());
    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $admin->postJson('/api/v1/editorial/cities', r11CityBody(['timezone' => 'Europa/Padova', 'locale' => 'fr', 'night_cutoff_time' => '25:00', 'default_zoom' => 21, 'radius_km' => 0, 'center_lat' => 91]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['timezone', 'locale', 'night_cutoff_time', 'default_zoom', 'radius_km', 'center_lat']]]);

    $data = $admin->postJson('/api/v1/editorial/cities', r11CityBody())->assertCreated()->json('data');
    $city = City::query()->findOrFail($data['id']);
    expect($city->slug)->toBe('vicenza')
        ->and($city->is_active)->toBeFalse()
        ->and($city->settings)->toBe(['community' => true, 'carpool' => false])
        ->and($data['settings'])->toBe([['key' => 'community', 'enabled' => true], ['key' => 'carpool', 'enabled' => false]]);

});

// ------------------------------------------------------------ pagine

it('lascia le pagine solo a chi ha pages.manage, con anteprima e controllo della revisione', function (): void {
    $page = Page::factory()->create(['title' => 'Privacy', 'slug' => 'privacy', 'body' => "# Titolo\n\nTesto", 'is_published' => true, 'sort_order' => 1]);

    r11Gate($this, 'GET', '/api/v1/editorial/pages');
    $moderator = EditorialSession::as($this, EditorialSession::token($this->s->moderator));
    $moderator->getJson('/api/v1/editorial/pages')->assertForbidden();
    $moderator->postJson('/api/v1/editorial/pages/preview', ['body' => 'x'])->assertForbidden();
    $moderator->getJson('/api/v1/editorial/pages/'.$page->id)->assertForbidden();

    $admin = EditorialSession::as($this, EditorialSession::token($this->s->admin));
    $admin->getJson('/api/v1/editorial/pages?filter[is_published]=1')->assertOk()->assertJsonPath('data.0.slug', 'privacy');
    // L'anteprima scarta la marcatura grezza e i collegamenti javascript, come la pagina sul sito.
    $html = $admin->postJson('/api/v1/editorial/pages/preview', ['body' => "## Ciao\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1)) **ok**"])->assertOk()->json('data.html');
    expect($html)->toContain('<h2>Ciao</h2>')->toContain('<strong>ok</strong>')->not->toContain('<script>')->not->toContain('javascript:');

    $form = $admin->getJson('/api/v1/editorial/pages/'.$page->id)->assertOk()->json('data');
    expect($form['revision'])->toBe(CatalogPages::revision($page))->and($form['url'])->toEndWith('/pagine/privacy');

    $body = ['title' => 'Informativa privacy', 'body' => "# Titolo\n\nTesto corretto", 'is_published' => true, 'sort_order' => 1];
    // Senza revisione 422; con una revisione vecchia 409.
    $admin->patchJson('/api/v1/editorial/pages/'.$page->id, $body)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['revision']]]);
    $admin->patchJson('/api/v1/editorial/pages/'.$page->id, [...$body, 'revision' => 'vecchia'])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

    $saved = $admin->patchJson('/api/v1/editorial/pages/'.$page->id, [...$body, 'revision' => $form['revision']])->assertOk()->json('data');
    // Lo slug non si muove cambiando il titolo (`doNotGenerateSlugsOnUpdate`); la revisione sì.
    expect($saved['slug'])->toBe('privacy')->and($saved['revision'])->not->toBe($form['revision'])
        ->and($page->refresh()->body)->toBe("# Titolo\n\nTesto corretto");
    // La stessa revisione non vale due volte.
    $admin->patchJson('/api/v1/editorial/pages/'.$page->id, [...$body, 'title' => 'Altro', 'revision' => $form['revision']])->assertStatus(409);

    $admin->postJson('/api/v1/editorial/pages', ['title' => 'Chi siamo', 'body' => 'Siamo noi.', 'slug' => 'Chi Siamo'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['slug']]]);
    $created = $admin->postJson('/api/v1/editorial/pages', ['title' => 'Chi siamo', 'body' => 'Siamo noi.'])->assertCreated()->json('data');
    expect($created['slug'])->toBe('chi-siamo')->and($created['is_published'])->toBeFalse();

});

it('scarta visibilità sui motori e contenuto dimostrativo di chi non è admin, come il pannello', function (): void {
    $tag = Tag::factory()->create(['name' => 'Folk']);
    // Un redattore con il solo permesso dei tag (senza ruolo admin) non tocca indicizzazione e demo.
    $writer = EditorialSession::user(UserRole::Moderator);
    $writer->givePermissionTo('tags.manage');

    EditorialSession::as($this, EditorialSession::token($writer))->patchJson('/api/v1/editorial/tags/'.$tag->id, [
        'name' => 'Folk', 'seo' => ['title' => 'Folk', 'indexing' => 'excluded'], 'is_demo' => true,
    ])->assertOk();
    expect($tag->refresh()->seo['indexing'] ?? 'automatic')->toBe('automatic')
        ->and($tag->seo['title'])->toBe('Folk')
        ->and((bool) $tag->is_demo)->toBeFalse();
});

it('non scrive nel registro attività, come il pannello per le cinque risorse del catalogo', function (): void {
    // Categorie, tag, caratteristiche, città e pagine non tengono un registro nel pannello: l'app non ne aggiunge uno.
    $before = Activity::query()->count();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/tags', ['name' => 'Rock'])->assertCreated();
    expect(Activity::query()->count())->toBe($before);
});
