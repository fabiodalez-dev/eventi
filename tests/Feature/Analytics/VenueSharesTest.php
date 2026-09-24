<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Enums\VenueStatus;
use App\Filament\Venue\Pages\EventShareAnalytics;
use App\Models\EventShareLink;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventShares;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VenueIsolationScenario;

/**
 * Blocco 3: il locale ha link tracciati anche sulla propria scheda, canali che
 * nomina lui e un QR da stampare.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 21:00');
    $this->shares = app(EventShares::class);
    $this->venue = $this->scenario->venueA;
});

afterEach(fn () => Carbon::setTestNow());

function allowVenueShareStatistics($test): void
{
    $state = new ConsentState('venue-share-test', config('consent.version'), ['statistics' => true]);
    $test->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
}

it('serve alla scheda del locale gli stessi codici a ogni ricostruzione', function (): void {
    $links = $this->shares->venueLinks($this->venue);

    expect($this->shares->venueLinks($this->venue))->toBe($links);
    expect(EventShareLink::where('venue_id', $this->venue->id)->count())->toBe(4);

    $page = $this->get('/locali/'.$this->venue->slug)->assertOk();
    $page->assertSee('data-share-url="'.$links['native']['url'].'"', false);
    $page->assertSee(urlencode($links['whatsapp']['url']), false);

    /* La pagina è servita da cache: una ricostruzione che generasse codici
       nuovi azzererebbe di fatto i conteggi senza dirlo a nessuno. */
    $this->get('/locali/'.$this->venue->slug)->assertOk();
    expect(EventShareLink::where('venue_id', $this->venue->id)->count())->toBe(4);
});

it('porta alla scheda del locale e conta apertura e condivisione', function (): void {
    allowVenueShareStatistics($this);
    $links = $this->shares->venueLinks($this->venue);

    $this->get($links['telegram']['url'])->assertRedirect(route('venues.show', ['slug' => $this->venue->slug]));
    $this->postJson($links['telegram']['metric'])->assertNoContent();

    $row = DB::table('event_share_daily')->sole();
    expect((int) $row->clicks)->toBe(1)->and((int) $row->shares)->toBe(1)->and($row->date)->toBe('2026-09-10');
    $this->assertDatabaseHas('profile_views_daily', ['profile_type' => 'venue', 'profile_id' => $this->venue->id, 'shares' => 1]);
});

it('restituisce il QR in vettoriale, scaricabile, senza contarlo come apertura', function (): void {
    allowVenueShareStatistics($this);
    $this->shares->venueLinks($this->venue);
    $code = EventShareLink::where('venue_id', $this->venue->id)->firstOrFail()->code;

    $inline = $this->get(route('event-shares.qr', ['code' => $code]))->assertOk();
    expect($inline->headers->get('Content-Type'))->toContain('image/svg+xml');
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline');
    expect($inline->getContent())->toContain('<svg');

    $scaricato = $this->get(route('event-shares.qr', ['code' => $code, 'scarica' => 1]))->assertOk();
    expect($scaricato->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('qr-'.$code.'.svg');

    expect(DB::table('event_share_daily')->count())->toBe(0);
});

it('smette di valere quando il locale non è più approvato', function (): void {
    $links = $this->shares->venueLinks($this->venue);
    $code = EventShareLink::where('venue_id', $this->venue->id)->firstOrFail()->code;
    $this->venue->update(['status' => VenueStatus::Suspended]);

    $this->get($links['native']['url'])->assertNotFound();
    $this->get(route('event-shares.qr', ['code' => $code]))->assertNotFound();
});

it('normalizza il nome del canale, lo riusa e si ferma al tetto', function (): void {
    $link = $this->shares->addChannel($this->venue, 'Volantino Estate!');

    expect($link->channel)->toBe('c-volantino-estate');
    expect(EventShares::channelLabel($link->channel))->toBe('Volantino Estate');
    /* Due etichette che danno lo stesso slug sono lo stesso canale: chi
       stampa non deve accorgersi di aver scritto una maiuscola diversa. */
    expect($this->shares->addChannel($this->venue, 'volantino ESTATE')->id)->toBe($link->id);

    for ($i = 2; $i <= EventShares::MAX_CUSTOM; $i++) {
        $this->shares->addChannel($this->venue, 'Canale numero '.$i);
    }
    expect(fn () => $this->shares->addChannel($this->venue, 'Uno di troppo'))->toThrow(HttpException::class);
    expect(fn () => $this->shares->addChannel($this->venue, '!!'))->toThrow(HttpException::class);
});

it('tiene i canali di un locale fuori dal pannello di un altro', function (): void {
    $this->shares->addChannel($this->venue, 'Volantino');
    Filament::setCurrentPanel('venue');

    $this->actingAs($this->scenario->ownerB);
    Filament::setTenant($this->scenario->venueB);
    expect(app(EventShares::class)->channels())->toBe(EventShares::CHANNELS);

    $this->actingAs($this->scenario->ownerA);
    Filament::setTenant($this->venue);
    expect(app(EventShares::class)->channels())->toContain('c-volantino');

    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

it('filtra i conteggi su un canale personalizzato di un evento', function (): void {
    $link = $this->shares->addChannel($this->venue, 'Locandina', $this->scenario->publishedEventA);
    $this->shares->record($link, false);

    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);

    $report = app(EventAnalyticsDashboard::class)->report([
        'from' => '2026-09-01', 'until' => '2026-09-10', 'channel' => 'c-locandina',
    ]);

    expect($report['totals']['short_clicks'])->toBe(1);
    expect($report['filter_labels']['channel'])->toBe('Locandina');
    expect(collect($report['channels'])->pluck('channel')->all())->toContain('Locandina');

    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

it('lascia i canali e i QR al referente e non al collaboratore', function (): void {
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);

    Livewire::test(EventShareAnalytics::class)
        ->assertSee(__('event-shares.custom.title'))
        ->set('channelLabel', 'Radio locale')
        ->call('addChannel')
        ->assertSee('Radio Locale');
    expect(EventShareLink::where('venue_id', $this->venue->id)->where('channel', 'c-radio-locale')->exists())->toBeTrue();

    $this->actingAs($this->scenario->editorA);
    $collaboratore = Livewire::test(EventShareAnalytics::class)->assertDontSee(__('event-shares.custom.title'));
    expect($collaboratore->instance()->canManageChannels())->toBeFalse();

    /* L'azione resta nel componente anche per chi non vede il modulo:
       chiamarla lo stesso non deve bastare, e la prova è che il canale non
       nasca — Livewire trasforma l'interruzione in una risposta e non la
       lascia arrivare qui come eccezione. */
    $collaboratore->set('channelLabel', 'Di nascosto')->call('addChannel');
    expect(EventShareLink::where('channel', 'c-di-nascosto')->exists())->toBeFalse();
});
