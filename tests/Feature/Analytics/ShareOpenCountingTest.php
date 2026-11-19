<?php

declare(strict_types=1);

use App\Filament\Venue\Pages\EventShareAnalytics as VenueEventShareAnalytics;
use App\Filament\Venue\Pages\Statistics as VenueStatistics;
use App\Services\Analytics\EventShares;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\VenueIsolationScenario;

/**
 * Decisione del 19/11/2026: le aperture dei link brevi `/s/{code}` si contano
 * anche senza consenso, come totale anonimo per link e per giorno. Nessun
 * cookie, nessun identificativo, nessun IP; anteprime, bot e prefetch restano
 * fuori. Il pulsante «Condividi» e le visite alla scheda restano legati al
 * consenso, e le pagine delle statistiche lo spiegano sempre.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    freezeLocal($this->s->city, '2026-09-10 21:30');
    $this->links = app(EventShares::class)->links($this->s->publishedEventA, $this->s->occurrenceA);
});

afterEach(fn () => Carbon::setTestNow());

it('conta l’apertura senza consenso, senza cookie e senza dati di chi apre', function (): void {
    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/604.1', 'X-Forwarded-For' => '203.0.113.7'])
        ->get($this->links['whatsapp']['url']);
    $response->assertRedirect();
    expect($response->headers->getCookies())->toBeEmpty();
    $this->get($this->links['whatsapp']['url'])->assertRedirect();

    $row = DB::table('event_share_daily')->sole();
    expect((int) $row->clicks)->toBe(2)->and((int) $row->shares)->toBe(0)->and($row->date)->toBe('2026-09-10');
    // Solo il link, il giorno e i due contatori: nessuna colonna per la persona.
    expect(Schema::getColumnListing('event_share_daily'))->toEqualCanonicalizing(['id', 'share_link_id', 'date', 'shares', 'clicks']);
    expect(json_encode(DB::table('event_share_daily')->get()))->not->toContain('203.0.113.7')->not->toContain('iPhone');
    // L'apertura non diventa una visita alla scheda: quella resta col consenso.
    expect((int) DB::table('event_views_daily')->sum('views'))->toBe(0);
});

it('non conta anteprime, bot, HEAD e caricamenti anticipati anche senza consenso', function (string $method, array $headers): void {
    // `call()` non usa le intestazioni di `withHeaders()`: vanno passate come variabili del server.
    $this->call($method, $this->links['native']['url'], server: $this->transformHeadersToServerVars($headers))->assertRedirect();
    expect(DB::table('event_share_daily')->count())->toBe(0);
})->with([
    ['HEAD', []], ['GET', ['User-Agent' => 'WhatsApp/2.0']], ['GET', ['User-Agent' => 'facebookexternalhit/1.1']],
    ['GET', ['User-Agent' => 'TelegramBot']], ['GET', ['User-Agent' => 'Googlebot']], ['GET', ['User-Agent' => 'Slackbot-LinkExpanding']],
    ['GET', ['Sec-Purpose' => 'prefetch;prerender']], ['GET', ['Purpose' => 'prefetch']], ['GET', ['X-Purpose' => 'prefetch']],
]);

it('non lascia gonfiare il contatore con una serie di aperture dallo stesso indirizzo, e non conta le richieste senza User-Agent', function (): void {
    foreach (range(1, 15) as $n) {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($this->links['whatsapp']['url'])->assertRedirect();
    }
    expect((int) DB::table('event_share_daily')->sole()->clicks)->toBe(10);

    // Un altro indirizzo conta di nuovo; il tetto non salva l'indirizzo.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeaders(['User-Agent' => 'Mozilla/5.0'])
        ->get($this->links['whatsapp']['url'])->assertRedirect();
    expect((int) DB::table('event_share_daily')->sole()->clicks)->toBe(11)
        ->and(json_encode(DB::table('event_share_daily')->get()))->not->toContain('198.51.100.9');

    // Senza User-Agent il rinvio c'è, il conteggio no.
    $this->call('GET', $this->links['telegram']['url'], server: ['HTTP_USER_AGENT' => '', 'REMOTE_ADDR' => '198.51.100.10'])->assertRedirect();
    expect((int) DB::table('event_share_daily')->sum('clicks'))->toBe(11);
});

it('lascia la condivisione legata al consenso', function (): void {
    $this->postJson($this->links['native']['metric'])->assertNoContent();
    expect(DB::table('event_share_daily')->count())->toBe(0);
});

it('le statistiche del locale spiegano cosa contano, a una colonna e con lo stato vuoto', function (): void {
    $this->actingAs($this->s->ownerA);
    $share = $this->get(VenueEventShareAnalytics::getUrl(panel: 'venue', tenant: $this->s->venueA))->assertOk()
        ->assertSee('data-analytics-consent', false)
        ->assertSee(__('panel.analytics.consent_links'))
        ->assertSee('data-analytics-empty', false)
        ->assertSee('data-analytics-charts', false);
    expect($share->getContent())->not->toContain('raccogliamo');

    $this->get(VenueStatistics::getUrl(panel: 'venue', tenant: $this->s->venueA))->assertOk()
        ->assertSee('data-analytics-consent', false)
        ->assertSee('data-analytics-empty', false)
        ->assertDontSee('grid-cols-2', false)
        ->assertDontSee('md:grid-cols-4', false);

    // Con un'apertura il riepilogo non è più vuoto.
    $this->get($this->links['telegram']['url'])->assertRedirect();
    $this->get(VenueEventShareAnalytics::getUrl(panel: 'venue', tenant: $this->s->venueA))->assertOk()
        ->assertDontSee('data-analytics-empty', false);
});
