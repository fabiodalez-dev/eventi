<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Actions\ModerateVenueAction;
use App\Actions\ScheduleEventPublication;
use App\Enums\EventStatus;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue as AdminEditVenue;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Models\Event;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge ciò che succede a un locale messo fuori dal sito e a chi perde il
 * proprio account: il pannello si chiude subito, gli eventi escono da ogni
 * pagina pubblica — anche dalla loro scheda, raggiunta con un collegamento
 * vecchio — e non rientrano dalla porta di servizio di una pubblicazione
 * programmata. Riapprovare rimette tutto com'era (D52).
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->evento = $this->scenario->publishedEventA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
    Notification::fake();
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function geSospendiDallaRedazione(object $test, string $motivo): void
{
    Filament::setTenant(null);
    $test->actingAs($test->scenario->admin)->get('/admin/venues/'.$test->venue->getRouteKey().'/edit')->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(AdminEditVenue::class, ['record' => $test->venue->getRouteKey()])
        ->callAction('suspend', data: ['reason' => $motivo])
        ->assertHasNoActionErrors();
}

it('sospeso dalla redazione, il locale perde il pannello e i suoi eventi escono da ogni pagina pubblica', function (): void {
    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk();
    $this->get('/eventi/2026-09-11')->assertOk()->assertSee($this->evento->title);

    geSospendiDallaRedazione($this, 'Segnalazioni ripetute');
    expect($this->venue->fresh()->status)->toBe(VenueStatus::Suspended);

    geFuoriDalPannello();
    $this->actingAs($this->owner->fresh())->get('/gestione/'.$this->venue->slug)->assertForbidden();
    $this->actingAs($this->scenario->editorA->fresh())->get('/gestione/'.$this->venue->slug.'/eventi')->assertForbidden();

    geSitoPubblico();
    $this->get('/eventi/2026-09-11')->assertOk()->assertDontSee($this->evento->title);
    $this->get('/eventi')->assertOk()->assertDontSee($this->evento->title);
    // La pagina del locale resta raggiungibile ma senza cartellone (VenueController::findVisible).
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertDontSee($this->evento->title);
    // La data, aperta da un collegamento condiviso prima, non esiste più.
    $this->get('/eventi/'.$this->evento->slug.'/'.$this->scenario->occurrenceA->url_number)->assertNotFound();
    // Gli eventi dell'altro locale non c'entrano.
    $this->get('/eventi/'.$this->scenario->publishedEventB->slug)->assertOk();
});

it('riapprovato, il locale ritrova pannello, eventi e scheda pubblica', function (): void {
    app(ModerateVenueAction::class)->suspend($this->venue, 'In verifica');
    app(ModerateVenueAction::class)->approve($this->venue->fresh(), $this->scenario->admin);

    $this->actingAs($this->owner->fresh())->get('/gestione/'.$this->venue->slug.'/eventi')->assertOk()->assertSee($this->evento->title);

    geSitoPubblico();
    $this->get('/eventi/'.$this->evento->slug)->assertOk();
    $this->get('/eventi/2026-09-11')->assertOk()->assertSee($this->evento->title);
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertSee($this->evento->title);
});

it('non lascia uscire all’ora programmata l’evento di un locale sospeso nel frattempo', function (): void {
    $this->venue->update(['auto_publish' => true]);
    $bozza = $this->scenario->draftEventA;
    geData($bozza, '2026-09-26 21:00');
    app(ScheduleEventPublication::class)->schedule($bozza, $this->owner, CarbonImmutable::now()->addHour());

    app(ModerateVenueAction::class)->suspend($this->venue, 'In verifica');

    $this->travel(2)->hours();
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($bozza->fresh()->status)->toBe(EventStatus::Draft);
});

it('chi gestisce due locali continua nell’altro, ma non torna in quello sospeso scrivendo l’indirizzo', function (): void {
    $this->owner->venues()->attach($this->scenario->venueB, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);
    app(ModerateVenueAction::class)->suspend($this->venue, 'In verifica');
    $owner = $this->owner->fresh();

    $this->actingAs($owner)->get('/gestione/'.$this->scenario->venueB->slug.'/eventi')->assertOk()->assertSee($this->scenario->publishedEventB->title);
    $this->actingAs($owner)->get('/gestione/'.$this->venue->slug.'/eventi')->assertNotFound();
    $this->actingAs($owner)->get('/gestione/'.$this->venue->slug.'/eventi/'.$this->evento->getRouteKey().'/modifica')->assertNotFound();
    $this->actingAs($owner)->get('/gestione/'.$this->venue->slug.'/eventi/nuovo')->assertNotFound();

    // Lo switcher dei locali non propone quello sospeso.
    expect($owner->getTenants(Filament::getPanel('venue'))->pluck('id')->all())->toBe([$this->scenario->venueB->id]);
});

it('un locale eliminato dalla redazione porta via pannello ed eventi pubblici', function (): void {
    Filament::setTenant(null);
    $this->actingAs($this->scenario->admin)->get('/admin/venues/'.$this->venue->getRouteKey().'/edit')->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(AdminEditVenue::class, ['record' => $this->venue->getRouteKey()])->callAction('delete');
    expect($this->venue->fresh()->trashed())->toBeTrue();

    geFuoriDalPannello();
    expect($this->actingAs($this->owner->fresh())->get('/gestione/'.$this->venue->slug)->status())->toBeIn([403, 404]);

    geSitoPubblico();
    $this->get('/eventi/2026-09-11')->assertOk()->assertDontSee($this->evento->title);
    $this->get('/eventi/'.$this->evento->slug.'/'.$this->scenario->occurrenceA->url_number)->assertNotFound();
});

it('un account eliminato non rientra nel pannello con la sessione rimasta aperta, e i suoi eventi restano al locale', function (): void {
    $editor = $this->scenario->editorA;
    geEntra($editor, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Serata di chi se ne va']))
        ->call('create')
        ->assertHasNoFormErrors();
    $suo = Event::query()->where('title', 'Serata di chi se ne va')->sole();

    $sessione = [auth()->guard('web')->getName() => $editor->id];
    geSitoPubblico();
    $this->withSession($sessione)->get('/gestione/'.$this->venue->slug.'/eventi')->assertOk();

    $editor->delete();

    app('auth')->forgetGuards();
    $this->withSession($sessione)->get('/gestione/'.$this->venue->slug.'/eventi')->assertRedirect(route('login'));
    expect($suo->fresh()->venue_id)->toBe($this->venue->id)
        ->and($this->owner->can('update', $suo->fresh()))->toBeTrue();
});
