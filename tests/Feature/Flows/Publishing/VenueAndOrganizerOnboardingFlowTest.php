<?php

declare(strict_types=1);

use App\Actions\Account\ClaimMailbox;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Filament\Admin\Resources\Events\EventResource as AdminEventResource;
use App\Filament\Admin\Resources\Events\Pages\EditEvent as AdminEditEvent;
use App\Filament\Admin\Resources\VenueApplications\Pages\ListVenueApplications;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue as AdminEditVenue;
use App\Filament\Organizer\Resources\Events\Pages\CreateEvent as OrganizerCreateEvent;
use App\Filament\Organizer\Resources\Events\Pages\EditEvent as OrganizerEditEvent;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\OrganizerAccessGranted;
use App\Services\Geo\AddressGeocoder;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Flusso «entra nel catalogo»: chi gestisce un locale si candida, chi ha un
 * locale già a catalogo lo rivendica, chi organizza senza sede registra
 * l'organizzazione. La redazione decide dal pannello, e solo dopo
 * l'approvazione si apre il pannello di gestione.
 */
beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->category = testCategory();
    $this->admin = User::factory()->create(['name' => 'Redazione Centrale']);
    $this->admin->assignRole(UserRole::Admin->value);
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** La redazione apre la coda delle richieste come farebbe il browser. */
function pubCodaRichieste(User $chi): void
{
    test()->actingAs($chi)->get(VenueApplicationResource::getUrl())->assertOk();
    Filament::setCurrentPanel('admin');
}

function pubCandidaturaLocale(array $overrides = []): array
{
    return [
        'venue_name' => 'Osteria del Portello',
        'type' => VenueType::Bar->value,
        'address' => 'Via Portello 21',
        'website' => 'https://osteria-portello.example',
        'contact_name' => 'Marco Gestore',
        'contact_role' => 'Titolare',
        'contact_phone' => '347 1234567',
        'contact_email' => 'marco@osteria.example',
        'message' => 'Facciamo musica dal vivo il venerdì.',
        StoreVenueApplicationRequest::STARTED_FIELD => pubModuloAperto(),
        ...$overrides,
    ];
}

/** Il modulo aperto un minuto fa: il tempo minimo di compilazione è passato. */
function pubModuloAperto(): string
{
    return StoreVenueApplicationRequest::startedToken(now()->subMinute());
}

it('porta un gestore dalla candidatura al suo pannello e alla scheda pubblica', function (): void {
    $gestore = User::factory()->create(['email' => 'marco@osteria.example']);

    $this->actingAs($gestore)->get('/registra-il-tuo-locale')->assertOk()->assertSee(__('forms.application.title'));
    $this->actingAs($gestore)->post('/registra-il-tuo-locale', pubCandidaturaLocale())
        ->assertRedirect(route('venue-applications.create'))
        ->assertSessionHas('status', __('forms.application.sent.queued'));

    $richiesta = VenueApplication::query()->sole();
    expect($richiesta->kind())->toBe(ApplicationKind::Venue)
        ->and($richiesta->status)->toBe(ApplicationStatus::Pending)
        ->and($richiesta->user_id)->toBe($gestore->id)
        ->and($richiesta->type)->toBe(VenueType::Bar)
        ->and($richiesta->socials)->toBe(['website' => 'https://osteria-portello.example'])
        ->and(Venue::query()->count())->toBe(0);

    // Prima dell'approvazione il pannello non c'è.
    expect($gestore->fresh()->canAccessPanel(Filament::getPanel('venue')))->toBeFalse();

    pubCodaRichieste($this->admin);
    Livewire::test(ListVenueApplications::class)
        ->assertCanSeeTableRecords([$richiesta])
        ->callTableAction('approva', $richiesta)
        ->assertHasNoTableActionErrors();

    $locale = Venue::query()->sole();
    expect($locale->name)->toBe('Osteria del Portello')
        ->and($locale->status)->toBe(VenueStatus::Draft)
        ->and($locale->city_id)->toBe($this->city->id)
        ->and((float) $locale->lat)->toBe(45.4111)
        ->and($richiesta->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($richiesta->fresh()->venue_id)->toBe($locale->id);

    // Ha chiesto lui, da collegato e con la casella confermata: entra subito.
    $gestore = $gestore->fresh();
    expect($gestore->isOwnerOfVenue((int) $locale->id))->toBeTrue()
        ->and($gestore->hasRole(UserRole::VenueOwner->value))->toBeTrue();
    $this->actingAs($gestore)->get('/gestione/'.$locale->slug)->assertOk();
    $this->actingAs($gestore)->get('/gestione/'.$locale->slug.'/eventi/nuovo')->assertOk();

    // La scheda pubblica aspetta la seconda decisione della redazione.
    auth()->logout();
    $this->get('/locali/'.$locale->slug)->assertNotFound();

    $this->actingAs($this->admin)->get(AdminEditVenue::getUrl(['record' => $locale], panel: 'admin'))->assertOk();
    Livewire::test(AdminEditVenue::class, ['record' => $locale->getRouteKey()])->callAction('approve');
    expect($locale->fresh()->status)->toBe(VenueStatus::Approved)
        ->and($locale->fresh()->approved_by)->toBe($this->admin->id);

    auth()->logout();
    $this->get('/locali/'.$locale->slug)->assertOk()->assertSee('Osteria del Portello');
});

it('respinge le candidature scritte male; da ospite crea l’account e mette in coda solo dopo la conferma dell’email', function (): void {
    $this->from('/registra-il-tuo-locale')->post('/registra-il-tuo-locale', pubCandidaturaLocale([
        'contact_name' => '',
        'contact_phone' => 'chiamami',
        'website' => 'javascript:alert(1)',
        'type' => 'astronave',
        'contact_email' => 'non-una-email',
    ]))->assertRedirect('/registra-il-tuo-locale')
        ->assertSessionHasErrors(['contact_name', 'contact_phone', 'website', 'type', 'contact_email', 'password']);

    $this->post('/registra-il-tuo-locale', pubCandidaturaLocale(['website_url' => 'https://spam.example', 'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga']))
        ->assertSessionHasErrors('website_url');
    expect(VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->count())->toBe(0);

    // Da ospite, con la password: l'account nasce, la richiesta aspetta la conferma dell'email.
    $this->post('/registra-il-tuo-locale', pubCandidaturaLocale(['password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('venue-applications.create'))
        ->assertSessionHas('application_sent', 'check_email');
    $marco = User::query()->where('email', 'marco@osteria.example')->sole();
    $richiesta = VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->sole();
    expect($marco->hasVerifiedEmail())->toBeFalse()
        ->and($richiesta->user_id)->toBe($marco->id)
        ->and($richiesta->isAwaitingEmail())->toBeTrue()
        ->and(VenueApplication::query()->count())->toBe(0);

    pubCodaRichieste($this->admin);
    Livewire::test(ListVenueApplications::class)->assertCountTableRecords(0);

    // Conferma l'email dal collegamento della registrazione: la richiesta entra in coda.
    app(ClaimMailbox::class)->confermaRegistrazione($marco);
    expect($richiesta->fresh()->isAwaitingEmail())->toBeFalse();

    Livewire::test(ListVenueApplications::class)->assertCanSeeTableRecords([$richiesta])
        ->callTableAction('approva', $richiesta)->assertHasNoTableActionErrors();

    // Ha chiesto lui e ha confermato la casella: entra subito, senza collegamenti a scadenza.
    $locale = Venue::query()->sole();
    $marco = $marco->fresh();
    expect($marco->isOwnerOfVenue((int) $locale->id))->toBeTrue()
        ->and($locale->website)->toBe('https://osteria-portello.example')
        ->and($locale->email)->toBeNull();
    // Un browser nuovo: la sessione del test porta l'impronta della password della redazione.
    $this->flushSession();
    $this->actingAs($marco)->get('/gestione/'.$locale->slug)->assertOk();
});

it('lascia rifiutare una candidatura dalla coda solo con un motivo, e la coda è della redazione', function (): void {
    $richiesta = VenueApplication::factory()->create(['venue_name' => 'Bar Fantasma', 'status' => ApplicationStatus::Pending]);

    $utente = User::factory()->create();
    $utente->assignRole(UserRole::User->value);
    $this->actingAs($utente)->get(VenueApplicationResource::getUrl())->assertForbidden();

    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);
    pubCodaRichieste($moderatore);

    Livewire::test(ListVenueApplications::class)
        ->callTableAction('rifiuta', $richiesta, data: ['notes' => ''])
        ->assertHasTableActionErrors(['notes' => 'required']);
    expect($richiesta->fresh()->status)->toBe(ApplicationStatus::Pending);

    /* La coda si apre su «Da valutare»: dopo il rifiuto la richiesta esce da
       lì, quindi la si guarda nella scheda «Tutte». */
    Livewire::test(ListVenueApplications::class)
        ->set('activeTab', 'all')
        ->callTableAction('rifiuta', $richiesta, data: ['notes' => 'Indirizzo inesistente, nessuna risposta al telefono'])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('rifiuta', $richiesta);

    $richiesta->refresh();
    expect($richiesta->status)->toBe(ApplicationStatus::Rejected)
        ->and($richiesta->notes)->toBe('Indirizzo inesistente, nessuna risposta al telefono')
        ->and($richiesta->reviewed_by)->toBe($moderatore->id)
        ->and($richiesta->reviewed_at)->not->toBeNull()
        ->and(Venue::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('consegna le chiavi di un locale a catalogo a chi lo rivendica, senza creare un doppione', function (): void {
    $locale = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro Importato']);
    $titolare = User::factory()->create(['email' => 'titolare@teatro.example']);

    $this->actingAs($titolare)->get('/locali/'.$locale->slug)->assertOk()->assertSee(__('venues.claim_card.action'));
    $this->actingAs($titolare)->get(route('venues.claim.create', $locale))->assertOk()->assertSee('Teatro Importato');

    $this->actingAs($titolare)->post(route('venues.claim.store', $locale), [
        'venue_name' => 'Nome inventato che viene ignorato',
        'contact_name' => 'Teresa Titolare',
        'contact_email' => 'titolare@teatro.example',
        'message' => 'Gestisco il teatro dal 2018.',
        StoreVenueApplicationRequest::STARTED_FIELD => pubModuloAperto(),
    ])->assertRedirect(route('venues.claim.create', $locale))->assertSessionHas('status', __('forms.application.sent.queued'));

    $rivendicazione = VenueApplication::query()->sole();
    expect($rivendicazione->kind())->toBe(ApplicationKind::Claim)
        ->and($rivendicazione->venue_id)->toBe($locale->id)
        ->and($rivendicazione->venue_name)->toBe('Teatro Importato')
        ->and($rivendicazione->user_id)->toBe($titolare->id);

    pubCodaRichieste($this->admin);
    Livewire::test(ListVenueApplications::class)->callTableAction('approva', $rivendicazione)->assertHasNoTableActionErrors();

    expect(Venue::query()->count())->toBe(1)
        ->and($locale->fresh()->status)->toBe(VenueStatus::Approved)
        ->and($titolare->fresh()->isOwnerOfVenue((int) $locale->id))->toBeTrue();
    $this->actingAs($titolare->fresh())->get('/gestione/'.$locale->slug)->assertOk();

    // Un locale con un referente non si rivendica più.
    auth()->logout();
    $this->get(route('venues.claim.create', $locale))->assertNotFound();
    $this->post(route('venues.claim.store', $locale), [
        'venue_name' => 'x', 'contact_name' => 'Altra Persona', 'contact_email' => 'altra@example.test',
    ])->assertNotFound();
    $this->get('/locali/'.$locale->slug)->assertOk()->assertDontSee(__('venues.claim_card.action'));
    expect(VenueApplication::query()->count())->toBe(1);
});

it('porta un’organizzazione dalla registrazione a un evento pubblicato dalla redazione', function (): void {
    $sede = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Sala Ospitante']);
    $referente = User::factory()->create(['email' => 'luca@collettivo.example']);

    $this->get('/registra-la-tua-organizzazione')->assertOk()->assertSee(__('forms.organizer_application.title'));
    $this->actingAs($referente)->post('/registra-la-tua-organizzazione', [
        'venue_name' => 'Collettivo Portici',
        'website' => 'https://portici.example',
        'contact_name' => 'Luca Referente',
        'contact_email' => 'luca@collettivo.example',
        'message' => 'Rassegna itinerante di concerti.',
        StoreVenueApplicationRequest::STARTED_FIELD => pubModuloAperto(),
    ])->assertRedirect(route('organizer-applications.create'))->assertSessionHas('status');

    $richiesta = VenueApplication::query()->sole();
    expect($richiesta->kind())->toBe(ApplicationKind::Organizer);

    pubCodaRichieste($this->admin);
    Livewire::test(ListVenueApplications::class)->callTableAction('approva', $richiesta)->assertHasNoTableActionErrors();

    $organizzazione = Organizer::query()->sole();
    expect($organizzazione->name)->toBe('Collettivo Portici')
        ->and($organizzazione->is_active)->toBeTrue()
        ->and($organizzazione->owner_id)->toBe($referente->id)
        ->and(Venue::query()->count())->toBe(1);
    Notification::assertSentTo($referente, OrganizerAccessGranted::class);

    // Il referente entra in /organizza e crea l'evento con la prima data.
    $this->actingAs($referente->fresh())->get('/organizza/'.$organizzazione->slug.'/eventi')->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizzazione);
    Livewire::test(OrganizerCreateEvent::class)
        ->fillForm([
            'title' => 'Concerto itinerante d’autunno',
            'description' => 'Musica dal vivo nella sala ospitante.',
            'city_id' => $this->city->id,
            'venue_id' => $sede->id,
            'category_id' => $this->category->id,
            'price_type' => 'free',
            'starts_at' => '2026-09-25 21:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Concerto itinerante d’autunno')->sole();
    expect($evento->organizer_id)->toBe($organizzazione->id)
        ->and($evento->status)->toBe(EventStatus::Draft)
        ->and($evento->created_by)->toBe($referente->id)
        ->and($evento->occurrences()->sole()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-25 19:00')
        ->and($referente->fresh()->venues()->exists())->toBeFalse();

    // Lo manda alla redazione: finché non è approvato, il pubblico non lo vede.
    Livewire::test(OrganizerEditEvent::class, ['record' => $evento->getRouteKey()])->callAction('submit');
    expect($evento->fresh()->status)->toBe(EventStatus::Pending);
    auth()->logout();
    $this->get('/eventi/'.$evento->slug)->assertNotFound();

    Filament::setTenant(null);
    $this->actingAs($this->admin)->get(AdminEventResource::getUrl('edit', ['record' => $evento], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(AdminEditEvent::class, ['record' => $evento->getRouteKey()])->callAction('publish');
    expect($evento->fresh()->status)->toBe(EventStatus::Published);

    auth()->logout();
    $this->get('/eventi/'.$evento->slug)->assertOk()->assertSee('Concerto itinerante d’autunno', false)->assertSee('Collettivo Portici');
});

it('respinge le registrazioni di organizzazioni incomplete e non apre il pannello a chi non è approvato', function (): void {
    $this->from('/registra-la-tua-organizzazione')->post('/registra-la-tua-organizzazione', [
        'venue_name' => '',
        'website' => 'ftp://portici.example',
        'contact_name' => 'Luca',
        'contact_phone' => '12',
        'contact_email' => 'luca@collettivo.example',
    ])->assertRedirect('/registra-la-tua-organizzazione')
        ->assertSessionHasErrors(['venue_name', 'website', 'contact_phone']);

    $this->post('/registra-la-tua-organizzazione', [
        'venue_name' => 'Collettivo Robot', 'contact_name' => 'Bot', 'contact_email' => 'bot@example.test',
        'website_url' => 'https://spam.example',
    ])->assertSessionHasErrors('website_url');

    expect(VenueApplication::query()->count())->toBe(0)->and(Organizer::query()->count())->toBe(0);

    // Una richiesta in attesa non dà alcun pannello.
    $richiedente = User::factory()->create(['email' => 'attesa@collettivo.example']);
    $this->actingAs($richiedente)->post('/registra-la-tua-organizzazione', [
        'venue_name' => 'Collettivo in Attesa', 'contact_name' => 'Anna', 'contact_email' => 'attesa@collettivo.example',
        StoreVenueApplicationRequest::STARTED_FIELD => pubModuloAperto(),
    ])->assertSessionHasNoErrors();
    expect(VenueApplication::query()->sole()->status)->toBe(ApplicationStatus::Pending)
        ->and($richiedente->fresh()->canAccessPanel(Filament::getPanel('organizer')))->toBeFalse()
        ->and($richiedente->fresh()->venues()->where('venue_user.role', VenueRole::Owner->value)->exists())->toBeFalse();
});
