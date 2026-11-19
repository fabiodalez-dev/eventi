<?php

declare(strict_types=1);

use App\Actions\Account\ClaimMailbox;
use App\Actions\ApproveVenueApplication;
use App\Enums\ApplicationKind;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\AdminVenueApplicationSubmitted;
use App\Notifications\RegistrationLink;
use App\Notifications\VenueAccessGranted;
use App\Notifications\VenueApplicationApproved;
use App\Notifications\VenueApplicationReceived;
use App\Services\Geo\AddressGeocoder;
use App\Support\Honeypot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Registrare un locale vuol dire anche avere un account: chi è collegato
 * scrive col suo, chi non lo è lo crea dal modulo. La richiesta entra nella
 * coda della redazione solo quando l'email è confermata, e l'approvazione
 * apre il pannello all'account che l'ha chiesta, senza collegamenti a
 * scadenza.
 */
beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    $this->redazione = User::factory()->create();
    $this->redazione->assignRole(UserRole::Admin->value);
});

function accountModulo(array $overrides = []): array
{
    return [
        'venue_name' => 'Bottega del Jazz',
        'type' => 'bar',
        'address' => 'Via Altinate 10',
        'postal_code' => '35121',
        'municipality' => 'Padova',
        'website' => 'https://bottega.example',
        'public_email' => 'info@bottega.example',
        'public_phone' => '049 8765432',
        'capacity' => '80',
        'description' => 'Jazz dal vivo ogni giovedì.',
        'contact_name' => 'Giulia Verdi',
        'contact_role' => 'Titolare',
        'contact_phone' => '347 1112223',
        'contact_email' => 'giulia@bottega.example',
        'password' => 'una-password-lunga',
        'password_confirmation' => 'una-password-lunga',
        'message' => 'Facciamo jazz.',
        Honeypot::FIELD => '',
        StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute()),
        ...$overrides,
    ];
}

function tutteLeRichieste()
{
    return VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL);
}

it('da ospite crea l’account, tiene la richiesta fuori dalla coda e la mette in coda alla conferma dell’email', function (): void {
    $this->post('/registra-il-tuo-locale', accountModulo())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('venue-applications.create'))
        ->assertSessionHas('application_sent', 'check_email');

    $giulia = User::query()->where('email', 'giulia@bottega.example')->sole();
    $richiesta = tutteLeRichieste()->sole();
    expect($giulia->hasVerifiedEmail())->toBeFalse()
        ->and($richiesta->user_id)->toBe($giulia->id)
        ->and($richiesta->isAwaitingEmail())->toBeTrue()
        ->and($richiesta->venue_data)->toMatchArray(['public_email' => 'info@bottega.example', 'capacity' => 80, 'postal_code' => '35121'])
        // Fuori dalla coda: la redazione non la vede né la conta.
        ->and(VenueApplication::query()->count())->toBe(0);
    Notification::assertSentTo($giulia, RegistrationLink::class);
    Notification::assertNotSentTo($this->redazione, AdminVenueApplicationSubmitted::class);
    $this->actingAs($this->redazione)->get('/admin/venue-applications/'.$richiesta->id.'/edit')->assertNotFound();

    // Apre il collegamento della registrazione: l'email è confermata, la richiesta entra in coda.
    auth()->logout();
    app(ClaimMailbox::class)->confermaRegistrazione($giulia);
    expect($richiesta->fresh()->isAwaitingEmail())->toBeFalse();
    Notification::assertSentTo($this->redazione, AdminVenueApplicationSubmitted::class);
    Notification::assertSentTo($giulia, VenueApplicationReceived::class);

    // Approvata: entra subito come referente, con l'avviso che porta al pannello.
    $locale = app(ApproveVenueApplication::class)->handle($richiesta->fresh(), $this->redazione);
    $riga = DB::table('venue_user')->where('venue_id', $locale->id)->where('user_id', $giulia->id)->sole();
    expect($riga->role)->toBe(VenueRole::Owner->value)
        ->and($riga->accepted_at)->not->toBeNull()
        ->and($giulia->fresh()->hasRole(UserRole::VenueOwner->value))->toBeTrue()
        ->and($locale->website)->toBe('https://bottega.example')
        ->and($locale->email)->toBe('info@bottega.example')
        ->and($locale->phone)->toBe('049 8765432')
        ->and($locale->capacity)->toBe(80);
    Notification::assertSentTo($giulia, VenueApplicationApproved::class);
    Notification::assertNotSentTo($giulia, VenueAccessGranted::class);
    /* Un browser nuovo: la sessione del test porta ancora l'impronta della
       password della redazione, e il pannello la confronta. */
    $this->flushSession();
    $this->actingAs($giulia->fresh())->get('/gestione/'.$locale->slug)->assertOk();
});

it('risponde allo stesso modo se l’email è già registrata, e la richiesta la conferma il titolare dopo l’accesso', function (): void {
    $nuova = $this->post('/registra-il-tuo-locale', accountModulo(['contact_email' => 'nuova@bottega.example']));
    $esistente = User::factory()->create(['email' => 'gia@bottega.example', 'name' => 'Già Registrata']);
    $this->flushSession();
    $giaUsata = $this->post('/registra-il-tuo-locale', accountModulo(['contact_email' => 'gia@bottega.example', 'venue_name' => 'Altro Locale']));

    expect($giaUsata->headers->get('Location'))->toBe($nuova->headers->get('Location'))
        ->and($giaUsata->getStatusCode())->toBe($nuova->getStatusCode());
    $giaUsata->assertSessionHas('application_sent', 'check_email')->assertSessionHas('status', __('forms.application.sent.check_email'));
    expect(User::query()->where('email', 'gia@bottega.example')->count())->toBe(1)
        ->and($esistente->fresh()->password)->toBe($esistente->password);
    Notification::assertSentTo($esistente, AccountAlreadyRegistered::class);

    $richiesta = tutteLeRichieste()->where('venue_name', 'Altro Locale')->sole();
    expect($richiesta->user_id)->toBe($esistente->id)->and($richiesta->isAwaitingEmail())->toBeTrue();

    // Entra e la trova in cima al modulo: la conferma lei, non chi ha scritto il suo indirizzo.
    $this->actingAs($esistente)->get('/registra-il-tuo-locale')->assertOk()->assertSee('data-awaiting-application', escape: false)->assertSee('Altro Locale');
    $altro = User::factory()->create();
    $this->actingAs($altro)->post(route('venue-applications.confirm', $richiesta->id))->assertNotFound();
    $this->actingAs($esistente)->post(route('venue-applications.confirm', $richiesta->id))->assertRedirect();
    expect($richiesta->fresh()->isAwaitingEmail())->toBeFalse();
    Notification::assertSentTo($this->redazione, AdminVenueApplicationSubmitted::class);
});

it('lascia eliminare la richiesta da confermare al titolare', function (): void {
    $titolare = User::factory()->create(['email' => 'tit@bottega.example']);
    $richiesta = VenueApplication::factory()->create(['user_id' => $titolare->id, 'contact_email' => 'tit@bottega.example', 'email_confirmed_at' => null]);

    $this->actingAs($titolare)->delete(route('venue-applications.discard', $richiesta->id))->assertRedirect();
    expect(tutteLeRichieste()->count())->toBe(0);
});

it('non crea la quarta richiesta in attesa per la stessa email', function (): void {
    $utente = User::factory()->create(['email' => 'tanti@bottega.example']);
    foreach (range(1, 3) as $n) {
        $this->actingAs($utente)->post('/registra-il-tuo-locale', accountModulo(['venue_name' => 'Locale '.$n, 'password' => null, 'password_confirmation' => null]))
            ->assertSessionHasNoErrors();
    }
    $this->actingAs($utente)->post('/registra-il-tuo-locale', accountModulo(['venue_name' => 'Locale 4', 'password' => null, 'password_confirmation' => null]))
        ->assertSessionHasErrors('form');
    expect(tutteLeRichieste()->count())->toBe(3);

    // Da ospite la risposta resta quella di sempre, ma la richiesta non nasce.
    auth()->logout();
    $this->post('/registra-il-tuo-locale', accountModulo(['venue_name' => 'Locale 5', 'contact_email' => 'tanti@bottega.example']))
        ->assertSessionHasNoErrors()->assertSessionHas('application_sent', 'check_email');
    expect(tutteLeRichieste()->count())->toBe(3);
});

it('non lascia che le richieste scritte da un ospite con l’indirizzo di qualcun altro gli impediscano di mandare la sua', function (): void {
    $titolare = User::factory()->create(['email' => 'locale@bottega.example']);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);

    // Un ospite manda tre richieste di organizzazione con l'indirizzo del titolare: nessun account, in coda subito.
    foreach (range(1, 3) as $n) {
        $this->post(route('organizer-applications.store'), ['venue_name' => 'Finta '.$n, 'contact_name' => 'Chiunque', 'contact_email' => 'locale@bottega.example', StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute())])
            ->assertSessionHasNoErrors();
    }
    // La quarta da ospite non nasce: il tetto per indirizzo resta.
    $this->post(route('organizer-applications.store'), ['venue_name' => 'Finta 4', 'contact_name' => 'Chiunque', 'contact_email' => 'locale@bottega.example', StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute())])
        ->assertSessionHasNoErrors()->assertSessionHas('application_sent', 'organizer');
    expect(tutteLeRichieste()->count())->toBe(3);

    // Il titolare, collegato, registra il suo locale, lo rivendica e registra la sua organizzazione.
    $this->actingAs($titolare)->post('/registra-il-tuo-locale', accountModulo(['password' => null, 'password_confirmation' => null]))
        ->assertSessionHasNoErrors()->assertSessionHas('application_sent', 'queued');
    $this->actingAs($titolare)->post(route('venues.claim.store', $venue), ['contact_name' => 'Pia', 'message' => 'Gestisco il locale da dieci anni.', StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute())])
        ->assertSessionHasNoErrors();
    $this->actingAs($titolare)->post(route('organizer-applications.store'), ['venue_name' => 'Collettivo vero', 'contact_name' => 'Pia', StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute())])
        ->assertSessionHasNoErrors();
    expect(tutteLeRichieste()->where('user_id', $titolare->id)->count())->toBe(3);
});

it('non conta nel tetto del titolare le richieste in attesa di conferma scritte da un ospite col suo indirizzo, e ne limita il numero', function (): void {
    $titolare = User::factory()->create(['email' => 'gestore@bottega.example']);

    foreach (range(1, 4) as $n) {
        $this->flushSession();
        $this->post('/registra-il-tuo-locale', accountModulo(['venue_name' => 'Locale finto '.$n, 'contact_email' => 'gestore@bottega.example']))
            ->assertSessionHas('application_sent', 'check_email');
    }
    // Ne nascono al massimo tre, e partono al più tre avvisi alla casella.
    expect(tutteLeRichieste()->where('user_id', $titolare->id)->count())->toBe(3)
        ->and(tutteLeRichieste()->where('user_id', $titolare->id)->whereNull('email_confirmed_at')->count())->toBe(3);
    Notification::assertSentToTimes($titolare, AccountAlreadyRegistered::class, 3);

    // Il titolare entra e manda la sua: le tre da confermare non lo bloccano.
    $this->actingAs($titolare)->post('/registra-il-tuo-locale', accountModulo(['venue_name' => 'Il mio locale', 'password' => null, 'password_confirmation' => null]))
        ->assertSessionHasNoErrors()->assertSessionHas('application_sent', 'queued');
    expect(tutteLeRichieste()->where('venue_name', 'Il mio locale')->sole()->isAwaitingEmail())->toBeFalse();
});

it('accetta una sola rivendicazione aperta per locale ed email', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $utente = User::factory()->create();
    $dati = ['contact_name' => 'Pia', 'message' => 'Gestisco il locale da dieci anni.', StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute())];

    $this->actingAs($utente)->post(route('venues.claim.store', $venue), $dati)->assertSessionHasNoErrors();
    $this->actingAs($utente)->post(route('venues.claim.store', $venue), $dati)->assertSessionHasErrors('form');
    expect(tutteLeRichieste()->where('kind', ApplicationKind::Claim)->count())->toBe(1);
});

it('precompila nome ed email di chi è collegato e mostra l’accesso a chi non lo è', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $utente = User::factory()->create(['name' => 'Sara Bianchi', 'email' => 'sara@example.test']);

    $this->actingAs($utente)->get(route('venues.claim.create', $venue))->assertOk()
        ->assertSee('value="Sara Bianchi"', escape: false)
        ->assertSee('data-account-email', escape: false)
        ->assertSee('sara@example.test')
        ->assertDontSee('data-login-card', escape: false)
        ->assertDontSee('name="password"', escape: false);

    auth()->logout();
    $this->get(route('venues.claim.create', $venue))->assertOk()
        ->assertSee('data-login-card', escape: false)
        ->assertSee('name="password"', escape: false);
});

it('conserva quanto scritto mentre si va ad accedere', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);

    $this->post(route('venues.claim.draft', $venue), ['contact_name' => 'Nome Scritto', 'message' => 'Testo che non si perde', 'password' => 'segreta-123'])
        ->assertRedirect(route('login', ['intended' => route('venues.claim.create', $venue)]));
    expect(session('venue_application_draft.claim:'.$venue->id))->toBe(['contact_name' => 'Nome Scritto', 'message' => 'Testo che non si perde']);

    $this->actingAs(User::factory()->create())->get(route('venues.claim.create', $venue))->assertOk()
        ->assertSee('Nome Scritto')->assertSee('Testo che non si perde');
});

it('respinge i robot: campo esca e invio troppo rapido', function (): void {
    $this->post('/registra-il-tuo-locale', accountModulo([Honeypot::FIELD => 'https://spam.example']))->assertSessionHasErrors(Honeypot::FIELD);
    $this->post('/registra-il-tuo-locale', accountModulo([StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken()]))
        ->assertSessionHasErrors(StoreVenueApplicationRequest::STARTED_FIELD);
    $this->post('/registra-il-tuo-locale', accountModulo([StoreVenueApplicationRequest::STARTED_FIELD => 'falso']))
        ->assertSessionHasErrors(StoreVenueApplicationRequest::STARTED_FIELD);
    $this->post('/registra-il-tuo-locale', accountModulo([StoreVenueApplicationRequest::STARTED_FIELD => null]))
        ->assertSessionHasErrors(StoreVenueApplicationRequest::STARTED_FIELD);

    expect(tutteLeRichieste()->count())->toBe(0)->and(User::query()->where('email', 'giulia@bottega.example')->exists())->toBeFalse();
});

it('tiene l’immagine sul disco privato e la porta sulla scheda all’approvazione', function (): void {
    /* Un disco tutto suo: `Storage::fake('local')` usa una cartella fissa che
       altri test in parallelo svuotano. */
    $radice = storage_path('framework/testing/disks/claim-'.getmypid());
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => $radice]));
    $utente = User::factory()->create(['email' => 'foto@bottega.example']);

    $this->actingAs($utente)->post('/registra-il-tuo-locale', accountModulo([
        'password' => null, 'password_confirmation' => null,
        'image' => UploadedFile::fake()->image('locale.jpg', 800, 600),
    ]))->assertSessionHasNoErrors();
    $richiesta = VenueApplication::query()->sole();
    expect($richiesta->image_path)->toStartWith('venue-applications/');
    Storage::disk('local')->assertExists($richiesta->image_path);

    $this->actingAs($utente)->post('/registra-il-tuo-locale', accountModulo([
        'password' => null, 'password_confirmation' => null, 'venue_name' => 'Altro',
        'image' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
    ]))->assertSessionHasErrors('image');

    $locale = app(ApproveVenueApplication::class)->handle($richiesta, $this->redazione);
    expect($locale->getFirstMedia('cover'))->not->toBeNull();
    (new Filesystem)->deleteDirectory($radice);
});
