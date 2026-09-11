<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory(['name' => 'Comunità e assemblee']);
    $this->user = User::factory()->create();
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
});

it('keeps profile preferences aligned and every personal page linked back', function (string $device): void {
    $page = visit('/profilo/interessi')->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->assertVisible('[data-profile-back]')->screenshot(filename: 'profile-interests-'.$device);
    expect($page->script('(() => { const select = document.querySelector("select[name^=choices]"); const label = select.closest("label"); const a = select.getBoundingClientRect(), b = label.querySelector("span").getBoundingClientRect(); return Math.abs((a.top+a.bottom)/2-(b.top+b.bottom)/2) < 2 && document.documentElement.scrollWidth <= innerWidth; })()'))->toBeTrue();
    $page->click('[data-profile-back]')->assertSee('Il mio profilo')->assertPresent('select[name="timezone"]')->screenshot(filename: 'profile-'.$device);
    expect($page->script('document.documentElement.scrollWidth <= innerWidth && document.querySelector("nav[aria-label=\"Area personale\"] svg").getBoundingClientRect().width === 24'))->toBeTrue();
    $page->click('a[href$="/biglietti"]')->assertSee('Non hai ancora biglietti.')->assertVisible('[data-profile-back]')->screenshot(filename: 'tickets-empty-'.$device);
})->with(['desktop', 'mobile']);

it('opens the mobile scanner decodes a qr and records admission only after confirmation', function (string $device): void {
    $date = occurrenceAtLocal($this->city, $this->category, now('Europe/Rome')->addHour()->format('Y-m-d H:i'), null, ['booking_enabled' => true, 'booking_capacity' => 5, 'booking_limit' => 5]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $booking = app(TicketingService::class)->reserve($this->user, $date, ['Anna Rossi'], (string) Str::uuid(), false);
    $ticket = $booking->tickets->first();
    $svg = base64_encode((string) QrCode::format('svg')->size(480)->margin(4)->generate($ticket->code));
    $page = visit('/gestione-biglietti/'.$date->id)->inLightMode()->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')->screenshot(filename: 'ticket-management-'.$device);
    expect($page->script('document.permissionsPolicy ? document.permissionsPolicy.allowsFeature("camera") : document.featurePolicy.allowsFeature("camera")'))->toBeTrue();
    expect($page->script('(() => { const r = [...document.querySelectorAll("[data-ticket-statistics] > div")].map(e=>e.getBoundingClientRect().top); return (r[0] === r[1] && r[2] === r[3]) && document.documentElement.scrollWidth <= innerWidth; })()'))->toBeTrue();
    // Real ZXing decoding against a deterministic camera feed, without a physical camera in CI.
    $page->script('window.qrSource = '.json_encode('data:image/svg+xml;base64,'.$svg));
    $page->script('(() => { navigator.mediaDevices.getUserMedia = async () => { window.cameraRequested = true; const canvas = document.createElement("canvas"); canvas.width=canvas.height=480; const image = new Image(); image.src=window.qrSource; await image.decode(); const context=canvas.getContext("2d"); const draw=()=>{context.fillStyle="white";context.fillRect(0,0,480,480);context.drawImage(image,0,0);}; draw(); window.cameraInterval=setInterval(draw,100); window.cameraStream=canvas.captureStream(10); return window.cameraStream; }; return true; })()');
    $page->click('[data-scan-start]')->assertValue('[data-ticket-scanner] [name="code"]', $ticket->code);
    expect($page->script('window.cameraRequested'))->toBeTrue()
        ->and($page->script('window.cameraStream.getTracks().every(t=>t.readyState === "ended")'))->toBeTrue();
    $page->click('[data-ticket-scanner] button[type="submit"]')->assertSee('Ingresso registrato: Anna Rossi.')->assertSee('Ingresso già effettuato');
    $page->fill('[data-ticket-search] [name="q"]', 'inesistente')->assertSee('Non ci sono partecipanti corrispondenti alla ricerca');
    $page->fill('[data-ticket-search] [name="q"]', 'Anna')->assertSee('Anna Rossi');
    $page->script('(() => { navigator.mediaDevices.getUserMedia = async () => { throw new DOMException("denied", "NotAllowedError"); }; return true; })()');
    $page->click('[data-scan-start]')->assertSee('Accesso alla fotocamera negato.');
})->with(['desktop', 'mobile']);

it('searches upcoming and past events without losing the mobile controls', function (string $device): void {
    occurrenceAtLocal($this->city, $this->category, now('Europe/Rome')->addDay()->format('Y-m-d').' 21:00', event: ['title' => 'Concerto futuro']);
    occurrenceAtLocal($this->city, $this->category, now('Europe/Rome')->subDays(2)->format('Y-m-d').' 21:00', event: ['title' => 'Concerto passato']);
    $page = visit('/gestione-biglietti')->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->assertSee('Concerto futuro')->assertDontSee('Concerto passato');
    $page->select('period', 'past')->assertSee('Concerto passato')->assertDontSee('Concerto futuro');
    $page->fill('[data-ticket-search] [name="q"]', 'nessuna corrispondenza')->assertSee('Non ci sono eventi passati corrispondenti alla ricerca');
    $page->fill('[data-ticket-search] [name="q"]', 'Concerto')->assertSee('Concerto passato');
    $page->navigate('/admin/newsletter')->assertSee('Invio della newsletter')->screenshot(filename: 'newsletter-'.$device);
})->with(['desktop', 'mobile']);
