<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Models\Organizer;
use App\Services\Analytics\EventShares;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Condivisione di un evento con i link brevi `/s/{code}` (apertura, gesto di
 * condivisione, QR stampabile) e misure firmate delle schede: si conta solo
 * con il consenso alle statistiche, mai i robot, mai da un indirizzo
 * manomesso.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', '2026-11-20 23:30', event: ['title' => 'Tango in piazza']);
    $this->event = $this->occurrence->event;
    $this->datePage = route('events.occurrence', ['slug' => $this->event->slug, 'occurrence' => $this->occurrence->url_number]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function intFlowConsentStatistics(object $test): void
{
    $state = new ConsentState('flow-interazioni', config('consent.version'), ['necessary' => true, 'statistics' => true]);
    $test->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
}

/** L'indirizzo firmato delle misure che la scheda consegna al JavaScript. */
function intFlowMetricUrl(TestResponse $page): string
{
    preg_match('/data-content-analytics\s+data-url="([^"]+)"/', (string) $page->getContent(), $match);
    expect($match)->not->toBeEmpty();

    return html_entity_decode($match[1]);
}

it('chi condivide la data dalla scheda genera link brevi stabili: apertura e condivisione si contano solo col consenso, il QR non conta', function (): void {
    $page = $this->get($this->datePage)->assertOk();
    $links = app(EventShares::class)->links($this->event, $this->occurrence);
    $page->assertSee('data-share-url="'.$links['native']['url'].'"', false)
        ->assertSee(urlencode($links['whatsapp']['url']), false);
    $code = basename((string) parse_url($links['whatsapp']['url'], PHP_URL_PATH));
    expect($code)->toMatch('/^[A-Za-z0-9]{7}$/');

    // Senza consenso: porta alla data, nessun cookie, nessun conteggio.
    $aperto = $this->get('/s/'.$code.'?url=https://evil.example')->assertRedirect($this->datePage);
    expect($aperto->headers->get('Cache-Control'))->toContain('no-store')
        ->and($aperto->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($aperto->headers->getCookies())->toBeEmpty();
    $this->post('/s/'.$code.'/share')->assertNoContent();
    expect(DB::table('event_share_daily')->count())->toBe(0);

    // Con il consenso alle statistiche: un'apertura e una condivisione.
    intFlowConsentStatistics($this);
    $this->get('/s/'.$code)->assertRedirect($this->datePage);
    $this->post('/s/'.$code.'/share')->assertNoContent();
    // L'anteprima di un'app di messaggi non è una persona.
    $this->get('/s/'.$code, ['User-Agent' => 'WhatsApp/2.23 A'])->assertRedirect($this->datePage);
    $this->get('/s/'.$code, ['Purpose' => 'prefetch'])->assertRedirect($this->datePage);

    $riga = DB::table('event_share_daily')->sole();
    expect((int) $riga->clicks)->toBe(1)->and((int) $riga->shares)->toBe(1)->and($riga->date)->toBe('2026-11-12');
    $this->assertDatabaseHas('event_views_daily', ['event_id' => $this->event->id, 'shares' => 1]);
    $this->assertDatabaseHas('occurrence_views_daily', ['occurrence_id' => $this->occurrence->id, 'shares' => 1]);

    // Il QR per il volantino: SVG da vedere o da scaricare, senza contare nulla.
    $qr = $this->get('/s/'.$code.'/qr.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($qr->headers->get('Content-Disposition'))->toBe('inline; filename="qr-'.$code.'.svg"')
        ->and((string) $qr->getContent())->toContain('<svg');
    $download = $this->get('/s/'.$code.'/qr.svg?scarica=1')->assertOk();
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment;');
    expect((int) DB::table('event_share_daily')->sole()->clicks)->toBe(1);

    // Gli stessi link alla visita successiva; un codice inventato non esiste.
    expect(app(EventShares::class)->links($this->event, $this->occurrence))->toBe($links);
    $this->get('/s/ZZZZZZZ')->assertNotFound();

    // L'evento torna in bozza: il link smette di portare da qualche parte.
    $this->event->forceFill(['status' => 'draft'])->save();
    $this->get('/s/'.$code)->assertNotFound();
    $this->get('/s/'.$code.'/qr.svg')->assertNotFound();
});

it('le schede di evento, locale e organizzatore misurano solo da indirizzi firmati, solo col consenso e solo metriche note', function (): void {
    $organizer = Organizer::create(['name' => 'Milonga Padovana', 'city_id' => $this->city->id, 'is_active' => true]);
    $altro = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00', event: ['title' => 'Altra serata'])->event;

    $eventUrl = intFlowMetricUrl($this->get(route('events.show', ['slug' => $this->event->slug]))->assertOk());
    expect($eventUrl)->toStartWith('/misure/event/'.$this->event->id)->toContain('signature=');

    // Senza consenso la richiesta riesce ma non lascia traccia.
    $this->postJson($eventUrl, ['metric' => 'views'])->assertNoContent();
    expect(DB::table('event_views_daily')->count())->toBe(0);

    intFlowConsentStatistics($this);
    $this->postJson($eventUrl, ['metric' => 'views'])->assertNoContent();
    $this->postJson($eventUrl, ['metric' => 'views'])->assertNoContent();
    $this->postJson($eventUrl, ['metric' => 'ticket_clicks'])->assertNoContent();
    $riga = DB::table('event_views_daily')->where('event_id', $this->event->id)->sole();
    expect((int) $riga->views)->toBe(2)->and((int) $riga->ticket_clicks)->toBe(1)->and($riga->date)->toBe('2026-11-12');

    // Metrica sconosciuta, indirizzo senza firma o con la firma di un altro evento: respinti.
    $this->postJson($eventUrl, ['metric' => 'password'])->assertUnprocessable();
    $this->postJson('/misure/event/'.$this->event->id, ['metric' => 'views'])->assertForbidden();
    $this->postJson(str_replace('/misure/event/'.$this->event->id, '/misure/event/'.$altro->id, $eventUrl), ['metric' => 'views'])->assertForbidden();
    expect(DB::table('event_views_daily')->where('event_id', $altro->id)->exists())->toBeFalse();

    // Le schede di locale e organizzatore contano nel proprio registro, separato.
    $venueUrl = intFlowMetricUrl($this->get(route('venues.show', ['slug' => $this->event->venue->slug]))->assertOk());
    $organizerUrl = intFlowMetricUrl($this->get(route('organizers.show', ['slug' => $organizer->slug]))->assertOk());
    $this->postJson($venueUrl, ['metric' => 'phone_clicks'])->assertNoContent();
    $this->postJson($organizerUrl, ['metric' => 'views'])->assertNoContent();
    $this->assertDatabaseHas('profile_views_daily', ['profile_type' => 'venue', 'profile_id' => $this->event->venue->id, 'phone_clicks' => 1]);
    $this->assertDatabaseHas('profile_views_daily', ['profile_type' => 'organizer', 'profile_id' => $organizer->id, 'views' => 1]);

    // Un evento tornato in bozza non accumula più misure, anche con l'indirizzo firmato.
    $this->event->forceFill(['status' => 'draft'])->save();
    $this->postJson($eventUrl, ['metric' => 'views'])->assertNotFound();
    expect((int) DB::table('event_views_daily')->where('event_id', $this->event->id)->sole()->views)->toBe(2);
});
