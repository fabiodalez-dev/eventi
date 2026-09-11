<?php

use App\Enums\EventStatus;
use App\Filament\Organizer\Pages\Profile;
use App\Filament\Organizer\Resources\Events\Pages\CreateEvent;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Policies\BookingPolicy;
use App\Policies\EventOccurrencePolicy;
use App\Policies\EventPolicy;
use App\Queries\EventOccurrenceQuery;
use App\Services\Seo\EditorialContent;
use App\Services\Seo\StructuredData;
use App\Support\EventUrl;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->category = testCategory();
    $this->owner = User::factory()->create();
    $this->organizer = Organizer::create(['city_id' => $this->city->id, 'owner_id' => $this->owner->id, 'name' => 'Collettivo Itinerante Padova', 'description' => 'Promotori di rassegne diffuse', 'is_active' => true]);
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00');
    $this->date->event->update(['organizer_id' => $this->organizer->id, 'organizer_name' => null]);
    $this->date->refresh();
});
it('isolates organizer management from unrelated events and suspended profiles', function (): void {
    $other = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00');
    $policy = new EventPolicy;
    expect($policy->update($this->owner, $this->date->event))->toBeTrue();
    expect($policy->update($this->owner, $other->event))->toBeFalse();
    expect((new EventOccurrencePolicy)->update($this->owner, $this->date))->toBeTrue();
    $this->organizer->update(['is_active' => false]);
    expect($policy->update($this->owner, $this->date->fresh()->event))->toBeFalse();
});
it('shows organizer archives and finds organizers in web and API search', function (): void {
    $this->get('/organizzatori')->assertOk()->assertSee('Collettivo Itinerante Padova');
    $this->get('/organizzatori/'.$this->organizer->slug)->assertOk()->assertSee($this->date->event->title);

    /*
     * Il nodo `Organization` deve **arrivare in pagina**.
     *
     * La scheda passava i dati strutturati al layout come `:structured-data`,
     * che non è fra i suoi `@props`: finivano in `$attributes` e non venivano
     * stampati da nessuna parte. Il controller era giusto, il nodo era giusto,
     * e in pagina non c'era niente — un guasto che nessuno vede perché non
     * rompe nulla di visibile. Adesso c'è una riga che lo vede.
     */
    $this->get('/organizzatori/'.$this->organizer->slug)
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type":"Organization"', false)
        ->assertSee(route('organizers.show', $this->organizer).'#organizer', false);

    /* Una ricerca è una pagina infinita: navigabile, non collezionabile. */
    $this->get('/organizzatori?q=Itinerante')->assertOk()
        ->assertSee('noindex, follow')
        ->assertSee('<link rel="canonical" href="'.route('organizers.index').'">', false);
    $this->get('/cerca/suggerimenti?q=Itinerante')->assertOk()->assertSee('Organizzatori')->assertSee($this->organizer->name);
    $this->get('/cerca?q=Itinerante')->assertOk()->assertSee($this->organizer->name);
    $this->getJson('/api/v1/search?q=Itinerante')->assertOk()->assertJsonPath('data.organizers.0.id', $this->organizer->id);
    $this->getJson('/api/v1/organizers/'.$this->organizer->slug)->assertOk()->assertJsonPath('data.events.0.occurrence_id', $this->date->id);
});
it('uses the actual venue per date for API maps and venue archives', function (): void {
    $venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'approved', 'lat' => 45.4, 'lng' => 11.9]);
    $original = $this->date->event->venue_id;
    $this->date->update(['venue_id' => $venue->id]);
    expect($this->date->fresh()->effectiveVenue()->id)->toBe($venue->id);
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->atVenue($venue)->get()->modelKeys())->toContain($this->date->id);
    if ($original) {
        expect(EventOccurrenceQuery::for($this->city)->upcoming()->atVenue($original)->get()->modelKeys())->not->toContain($this->date->id);
    }
    $this->getJson('/api/v1/occurrences/'.$this->date->id)->assertOk()->assertJsonPath('data.venue.id', $venue->id);
    $this->get(EventUrl::occurrence($this->date))->assertOk()->assertSee($venue->name);
});
it('finds organizers and their events by partial description without exposing inactive organizers', function (): void {
    $this->get('/cerca/suggerimenti?q=rasseg')->assertOk()->assertSee($this->organizer->name);
    $this->getJson('/api/v1/search?q=rasseg')->assertOk()
        ->assertJsonPath('data.organizers.0.id', $this->organizer->id);
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->search('rasseg')->get()->modelKeys())->toContain($this->date->id);
    $this->organizer->update(['is_active' => false]);
    $this->getJson('/api/v1/search?q=rasseg')->assertOk()->assertJsonCount(0, 'data.organizers');
    $this->get('/organizzatori/'.$this->organizer->slug)->assertNotFound();
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->search('rasseg')->get()->modelKeys())->not->toContain($this->date->id);
});
it('uses the original event venue as organizer when a date moves without a separate organizer', function (): void {
    $venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'approved']);
    $this->date->event->update(['organizer_id' => null, 'organizer_name' => null, 'organizer_url' => null, 'content_details' => []]);
    $this->date->update(['venue_id' => $venue->id]);
    $node = app(StructuredData::class)->event($this->date->fresh()->event, $this->date->fresh());
    expect($node['organizer']['name'])->toBe($this->date->event->venue->name);
    expect($node['location']['name'])->toBe($venue->name);
});
it('keeps hidden categories out of organizer archives', function (): void {
    $this->owner->update(['content_preferences' => ['mode' => 'selected', 'categories' => [], 'hidden_categories' => []]]);
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/organizers/'.$this->organizer->slug)->assertOk()->assertJsonCount(0, 'data.events');
});
it('admits only members of active organizer tenants', function (): void {
    $panel = Filament::getPanel('organizer');
    expect($this->owner->canAccessPanel($panel))->toBeTrue();
    expect($this->owner->canAccessTenant($this->organizer))->toBeTrue();
    expect(User::factory()->create()->canAccessTenant($this->organizer))->toBeFalse();
    $this->actingAs($this->owner)->get('/organizza/'.$this->organizer->slug.'/eventi')->assertOk();
});

it('creates organizer events as drafts without gaining venue ownership', function (): void {
    $venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'approved']);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('organizer'));
    Filament::setTenant($this->organizer);
    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Rassegna itinerante nuova', 'description' => 'Un evento organizzato in un locale ospitante.', 'city_id' => $this->city->id, 'venue_id' => $venue->id, 'category_id' => $this->category->id, 'price_type' => 'free'])
        ->call('create')->assertHasNoFormErrors();
    $event = Event::where('title', 'Rassegna itinerante nuova')->firstOrFail();
    expect($event->organizer_id)->toBe($this->organizer->id);
    expect($event->status)->toBe(EventStatus::Draft);
    expect($this->owner->venues()->exists())->toBeFalse();
});
it('lets the owner edit the public profile but not collaborators', function (): void {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('organizer'));
    Filament::setTenant($this->organizer);
    Livewire::test(Profile::class)
        ->fillForm(['description' => 'Nuova presentazione', 'email' => 'pubblico@example.test', 'website' => 'https://example.test'])
        ->call('save')->assertHasNoFormErrors();
    expect($this->organizer->fresh()->description)->toBe('<p>Nuova presentazione</p>');
    $member = User::factory()->create();
    $this->organizer->users()->attach($member);
    $this->actingAs($member);
    expect(Profile::canAccess())->toBeFalse();
    expect((new EventPolicy)->update($member, $this->date->event))->toBeTrue();
});
it('keeps booked attendance scoped to the correct organizer and actual host', function (): void {
    $other = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00');
    $policy = new BookingPolicy;
    expect($policy->manage($this->owner, $this->date))->toBeTrue();
    expect($policy->manage($this->owner, $other))->toBeFalse();
});

it('follows organizers without forcing notifications and isolates each account', function (): void {
    $this->getJson('/api/v1/me/follows/organizer/'.$this->organizer->id)->assertUnauthorized();
    Sanctum::actingAs($this->owner);
    $payload = ['type' => 'organizer', 'id' => $this->organizer->id, 'notify' => false];
    $this->postJson('/api/v1/me/follows', $payload)->assertCreated()->assertJsonPath('data.name', $this->organizer->name);
    $this->getJson('/api/v1/me/follows/organizer/'.$this->organizer->id)->assertOk()->assertJsonPath('data.notify', false);
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->followedBy($this->owner)->get()->modelKeys())->toContain($this->date->id);
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->followedBy($this->owner, notifyingOnly: true)->get()->modelKeys())->not->toContain($this->date->id);
    $this->postJson('/api/v1/me/follows', [...$payload, 'notify' => true])->assertCreated();
    expect($this->owner->follows()->count())->toBe(1);
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->followedBy($this->owner, notifyingOnly: true)->get()->modelKeys())->toContain($this->date->id);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/me/follows/organizer/'.$this->organizer->id)->assertOk()->assertJsonPath('data.following', false);
    $this->organizer->update(['is_active' => false]);
    $this->postJson('/api/v1/me/follows', $payload)->assertNotFound();
    expect(EventOccurrenceQuery::for($this->city)->upcoming()->followedBy($this->owner)->get()->modelKeys())->not->toContain($this->date->id);
});

it('inherits practical information from the actual date venue and preserves explicit event facts', function (): void {
    $host = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'content_details' => ['transit_notes' => 'Tram fermata ospitante', 'accessibility' => 'no']]);
    $this->date->event->update(['content_details' => ['mandatory_costs' => 'Consumazione obbligatoria 5 euro', 'accessibility' => 'yes']]);
    $this->date->update(['venue_id' => $host->id]);
    $details = app(EditorialContent::class)->details($this->date->fresh()->event, $this->date->fresh());
    expect($details['transit_notes'])->toBe('Tram fermata ospitante');
    expect($details['accessibility'])->toBe('yes');
    $this->getJson('/api/v1/events/'.$this->date->event->slug)->assertOk()
        ->assertJsonPath('data.occurrences.0.content_details.transit_notes', 'Tram fermata ospitante');
    $this->get(EventUrl::occurrence($this->date))->assertOk()
        ->assertSee('Prima di andare')->assertSee('Tram fermata ospitante')->assertSee('Consumazione obbligatoria 5 euro');
});

it('does not interpret an unknown accessibility value as a negative answer', function (): void {
    $this->date->event->update(['content_details' => ['accessibility' => 'unknown']]);
    $this->get('/eventi/'.$this->date->event->slug)->assertOk()->assertDontSee('Non specificato')->assertDontSee('Non accessibile in sedia a rotelle');
});

it('rejects foreign-city date venues and forged follow subjects', function (): void {
    $foreign = City::factory()->padova()->create(['slug' => 'altra-citta']);
    $venue = Venue::factory()->approved()->create(['city_id' => $foreign->id]);
    expect(fn () => $this->date->update(['venue_id' => $venue->id]))->toThrow(ValidationException::class);
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/me/follows', ['type' => 'organizer', 'id' => 999999999])->assertNotFound();
    $this->postJson('/api/v1/me/follows', ['type' => 'user', 'id' => $this->owner->id])->assertUnprocessable();
});
