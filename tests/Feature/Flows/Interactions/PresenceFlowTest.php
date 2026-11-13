<?php

declare(strict_types=1);

use App\Models\PresenceShare;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * «Sono qui»: la sera dell'evento si crea un link per chi ti raggiunge, lo si
 * apre senza account, lo si sostituisce, lo si revoca, e scade da solo due ore
 * dopo la fine. Dal sito e dall'app.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 18:00');
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Caffè delle Erbe']);
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-11-12 21:00', '2026-11-12 23:30', event: ['title' => 'Swing al caffè'], venue: $this->venue);
    $this->datePage = route('events.occurrence', ['slug' => $this->occurrence->event->slug, 'occurrence' => $this->occurrence->url_number]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('la sera dell\'evento si crea il link dalla pagina della data, chi lo riceve vede il locale, poi lo si sostituisce, revoca e scade', function (): void {
    $giulia = User::factory()->create(['first_name' => 'Giulia', 'last_name' => 'Rossi', 'name' => 'Giulia Rossi']);
    $store = route('presence.store', $this->occurrence->id);

    $this->actingAs($giulia)->get($this->datePage)->assertOk()
        ->assertSee(__('presence.title'))->assertSee($store, false);

    $this->from($this->datePage)->post($store, ['display_name' => 'Giulia', 'note' => 'Al bancone a sinistra'])
        ->assertRedirect($this->datePage)->assertSessionHas('status', __('presence.created'));

    $share = PresenceShare::query()->sole();
    expect($share->user_id)->toBe($giulia->id)
        ->and($share->occurrence_id)->toBe($this->occurrence->id)
        ->and($share->token)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($share->display_name)->toBe('Giulia')
        ->and($share->note)->toBe('Al bancone a sinistra')
        ->and($share->revoked_at)->toBeNull()
        // Due ore dopo la fine, nell'ora della città.
        ->and($share->expires_at->equalTo(localInstant($this->city, '2026-11-13 01:30')))->toBeTrue();

    // La pagina ora mostra il link pronto e il pulsante per revocarlo.
    $this->get($this->datePage)->assertOk()->assertSee($share->url(), false)
        ->assertSee(route('presence.destroy', $share->token), false);

    // Chi lo riceve lo apre senza account: il locale e la nota, mai indicizzato.
    $this->app['auth']->forgetGuards();
    $this->get($share->url())->assertOk()
        ->assertSee(__('presence.who', ['name' => 'Giulia']))
        ->assertSee('Al bancone a sinistra')
        ->assertSee('Caffè delle Erbe')
        ->assertDontSee('Rossi')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // Un nuovo link per la stessa data sostituisce il precedente.
    $this->actingAs($giulia)->from($this->datePage)->post($store, ['note' => 'Ci siamo spostate in terrazza'])->assertRedirect($this->datePage);
    $nuovo = PresenceShare::query()->whereNull('revoked_at')->sole();
    expect($nuovo->id)->not->toBe($share->id)
        ->and($share->fresh()->revoked_at)->not->toBeNull()
        // Senza nome scelto vale il nome proprio dell'account.
        ->and($nuovo->display_name)->toBe('Giulia');
    $this->app['auth']->forgetGuards();
    $this->get($share->url())->assertNotFound();
    $this->get($nuovo->url())->assertOk()->assertSee('Ci siamo spostate in terrazza');

    // Giulia smette di condividere.
    $this->actingAs($giulia)->from($this->datePage)->delete(route('presence.destroy', $nuovo->token))
        ->assertRedirect($this->datePage)->assertSessionHas('status', __('presence.revoked'));
    expect($nuovo->fresh()->revoked_at)->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->get($nuovo->url())->assertNotFound();

    // Un terzo link scade da solo dopo le 01:30.
    $this->actingAs($giulia)->from($this->datePage)->post($store, [])->assertRedirect();
    $terzo = PresenceShare::query()->whereNull('revoked_at')->sole();
    $this->app['auth']->forgetGuards();
    freezeLocal($this->city, '2026-11-13 01:00');
    $this->get($terzo->url())->assertOk();
    freezeLocal($this->city, '2026-11-13 01:31');
    $this->get($terzo->url())->assertNotFound();
});

it('dall\'app si crea e si revoca il link, solo per date in corso o imminenti, pubbliche e proprie', function (): void {
    $marco = User::factory()->create(['first_name' => 'Marco']);
    $altro = User::factory()->create();

    $this->postJson('/api/v1/me/presence', ['occurrence_id' => $this->occurrence->id])->assertUnauthorized();

    // Un account senza email confermata non crea link.
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson('/api/v1/me/presence', ['occurrence_id' => $this->occurrence->id])
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');

    Sanctum::actingAs($marco);
    $this->postJson('/api/v1/me/presence', [])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['occurrence_id']]]);
    $creato = $this->postJson('/api/v1/me/presence', ['occurrence_id' => $this->occurrence->id, 'note' => 'Tavolo vicino al palco'])->assertCreated();
    $token = $creato->json('data.token');
    expect($creato->json('data.url'))->toBe(route('presence.show', $token))
        ->and(CarbonImmutable::parse($creato->json('data.expires_at'))->equalTo(localInstant($this->city, '2026-11-13 01:30')))->toBeTrue()
        ->and(PresenceShare::query()->where('token', $token)->sole()->display_name)->toBe('Marco');

    // Date fuori finestra: troppo lontane o già finite.
    $settimanaProssima = occurrenceAtLocal($this->city, $this->category, '2026-11-19 21:00', venue: $this->venue);
    $this->postJson('/api/v1/me/presence', ['occurrence_id' => $settimanaProssima->id])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['occurrence_id']]]);
    $ieri = occurrenceAtLocal($this->city, $this->category, '2026-11-11 21:00', '2026-11-11 23:00', venue: $this->venue);
    $this->postJson('/api/v1/me/presence', ['occurrence_id' => $ieri->id])->assertUnprocessable();

    // Date non pubbliche o annullate: come se non esistessero.
    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-11-12 21:00', event: ['status' => 'draft'], venue: $this->venue);
    $annullata = occurrenceAtLocal($this->city, $this->category, '2026-11-12 22:00', occurrence: ['status' => 'cancelled'], venue: $this->venue);
    $this->postJson('/api/v1/me/presence', ['occurrence_id' => $bozza->id])->assertNotFound();
    $this->postJson('/api/v1/me/presence', ['occurrence_id' => $annullata->id])->assertNotFound();
    $this->postJson('/api/v1/me/presence', ['occurrence_id' => 999999])->assertNotFound();
    expect(PresenceShare::query()->count())->toBe(1);

    // Un altro account non revoca il link di Marco.
    Sanctum::actingAs($altro);
    $this->deleteJson('/api/v1/me/presence/'.$token)->assertNoContent();
    $this->get(route('presence.show', $token))->assertOk()->assertSee('Tavolo vicino al palco');

    Sanctum::actingAs($marco);
    $this->deleteJson('/api/v1/me/presence/'.$token)->assertNoContent();
    expect(PresenceShare::query()->where('token', $token)->sole()->revoked_at)->not->toBeNull();
    $this->get(route('presence.show', $token))->assertNotFound();
});
