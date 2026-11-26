<?php

declare(strict_types=1);

use App\Filament\Organizer\Pages\EventAnalyticsDetail;
use App\Filament\Organizer\Resources\Events\EventResource;
use App\Models\Organizer;
use App\Models\User;
use Tests\Support\VenueIsolationScenario;

/*
 * Un evento solo co-organizzato sta nel pannello `/organizza` di chi lo
 * co-organizza: la scheda e la modifica si aprono, ma le statistiche restano
 * di chi lo organizza (`ManagementAnalytics::eventQuery`), quindi il pannello
 * non offre la scheda «Statistiche», come l'app con `links.statistics`.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    $this->organizer = Organizer::create(['name' => 'Associazione Musica Aperta', 'city_id' => $this->s->city->id, 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
    $this->member = User::factory()->create();
    $this->organizer->users()->attach($this->member);
    $this->own = $this->s->publishedEventA;
    $this->own->update(['organizer_id' => $this->organizer->id]);
    $this->shared = $this->s->publishedEventB;
    $this->shared->organizers()->attach($this->organizer);
    $this->url = fn (string $page, $event): string => EventResource::getUrl($page, ['record' => $event], panel: 'organizer', tenant: $this->organizer);
    $this->stats = fn ($event): string => EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->getKey()], panel: 'organizer', tenant: $this->organizer);
});

it('apre scheda e modifica di un evento co-organizzato, senza la scheda Statistiche', function (): void {
    $this->actingAs($this->member);

    $this->get(($this->url)('view', $this->shared))->assertOk()->assertDontSee(($this->stats)($this->shared), false);
    $this->get(($this->url)('edit', $this->shared))->assertOk()->assertDontSee(($this->stats)($this->shared), false);
    $this->get(($this->stats)($this->shared))->assertNotFound();
});

it('lascia la scheda Statistiche sugli eventi di cui l’organizzazione è l’organizzatore principale', function (): void {
    $this->actingAs($this->member);

    // Sia la scheda della barra in alto sia il pulsante fra le pagine collegate (`eventLinks`).
    $html = $this->get(($this->url)('view', $this->own))->assertOk()->assertSee('fi-tabs-item', false)->getContent();
    $href = preg_quote('href="'.($this->stats)($this->own).'"', '/');
    expect(preg_match_all('/<a\s[^>]*'.$href.'[^>]*class="fi-btn|<a\s[^>]*class="fi-btn[^>]*'.$href.'/s', $html))->toBe(1);
    $this->get(($this->url)('edit', $this->own))->assertOk()->assertSee(($this->stats)($this->own), false);
    $this->get(($this->stats)($this->own))->assertOk();
});

it('non offre «Statistiche» nel menu della riga di un evento co-organizzato', function (): void {
    $this->actingAs($this->member);

    $this->get(EventResource::getUrl('index', panel: 'organizer', tenant: $this->organizer))->assertOk()
        ->assertSee(($this->stats)($this->own), false)
        ->assertDontSee(($this->stats)($this->shared), false);
});
