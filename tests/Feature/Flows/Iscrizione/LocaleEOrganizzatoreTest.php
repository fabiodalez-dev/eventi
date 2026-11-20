<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Enums\UserRole;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\Organizer;
use App\Models\User;
use App\Models\VenueApplication;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\AdminVenueApplicationSubmitted;
use App\Notifications\OrganizerAccessGranted;
use App\Notifications\RegistrationLink;
use App\Notifications\VenueApplicationApproved;
use App\Notifications\VerifyEmailLink;
use App\Services\Geo\AddressGeocoder;
use App\Support\Honeypot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * L'iscrizione di chi gestisce un posto o un'organizzazione (§10, D64, D65):
 * da «registra il tuo locale» e da «registra la tua organizzazione» fino al
 * pannello, passando per la conferma dell'email, la coda della redazione,
 * l'approvazione e il primo accesso, che porta direttamente nel pannello.
 */
beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4064, 'lng' => 11.8768])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);
    (new RolesAndPermissionsSeeder)->run();
    config(['community.enabled' => false]);

    $this->city = testCity();
    $this->redazione = User::factory()->create(['email' => 'redazione.flussi@example.test']);
    $this->redazione->assignRole(UserRole::Admin->value);
});

/** Il modulo «registra il tuo locale» compilato da un ospite. */
function iscrizioneModuloLocale(array $campi = []): array
{
    return [
        'venue_name' => 'Osteria del Portello',
        'type' => 'bar',
        'address' => 'Via del Portello 12',
        'postal_code' => '35129',
        'municipality' => 'Padova',
        'contact_name' => 'Chiara Neri',
        'contact_role' => 'Titolare',
        'contact_email' => 'chiara@osteria-portello.example',
        'password' => 'password-dell-osteria',
        'password_confirmation' => 'password-dell-osteria',
        'message' => 'Musica acustica il venerdì.',
        Honeypot::FIELD => '',
        StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute()),
        ...$campi,
    ];
}

function iscrizioneRichieste()
{
    return VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL);
}

it('registra il locale da ospite, conferma dalla posta, viene approvato e al primo accesso entra nel pannello', function (): void {
    $this->get('/registra-il-tuo-locale')->assertOk();
    $this->post('/registra-il-tuo-locale', iscrizioneModuloLocale())
        ->assertRedirect(route('venue-applications.create'))
        ->assertSessionHas('application_sent', 'check_email');

    $chiara = User::query()->where('email', 'chiara@osteria-portello.example')->sole();
    expect(iscrizioneRichieste()->sole()->isAwaitingEmail())->toBeTrue();
    Notification::assertNotSentTo($this->redazione, AdminVenueApplicationSubmitted::class);

    /* Apre il messaggio nello stesso browser: conferma, entra e torna al modulo. */
    $this->get(Notification::sent($chiara, RegistrationLink::class)->first()->url)
        ->assertRedirect(route('venue-applications.create'));
    $this->assertAuthenticatedAs($chiara);
    $richiesta = iscrizioneRichieste()->sole();
    expect($richiesta->isAwaitingEmail())->toBeFalse();
    Notification::assertSentTo($this->redazione, AdminVenueApplicationSubmitted::class);

    /* «Le mie richieste» la mostra in valutazione; il pannello è ancora chiuso. */
    $this->get('/le-mie-richieste')->assertOk()->assertSee('Osteria del Portello');
    $this->get('/gestione')->assertForbidden();

    $locale = app(ApproveVenueApplication::class)->handle($richiesta, $this->redazione);
    Notification::assertSentTo($chiara, VenueApplicationApproved::class);

    /* Esce e rientra con la password scelta nel modulo. */
    $this->post('/esci');
    $this->post('/accedi', ['email' => 'chiara@osteria-portello.example', 'password' => 'password-dell-osteria'])
        ->assertRedirect(url('/gestione/'.$locale->slug));
    $this->get('/gestione/'.$locale->slug)->assertOk();
});

it('non crea né account né richiesta se la password del modulo del locale è debole o non ripetuta', function (): void {
    $this->from('/registra-il-tuo-locale')->post('/registra-il-tuo-locale', iscrizioneModuloLocale(['password' => 'corta', 'password_confirmation' => 'corta']))
        ->assertRedirect('/registra-il-tuo-locale')
        ->assertSessionHasErrors('password')
        ->assertSessionHasInput('venue_name', 'Osteria del Portello');

    $this->from('/registra-il-tuo-locale')->post('/registra-il-tuo-locale', iscrizioneModuloLocale(['password_confirmation' => 'un-altra-ancora']))
        ->assertSessionHasErrors('password');

    $this->from('/registra-il-tuo-locale')->post('/registra-il-tuo-locale', iscrizioneModuloLocale(['contact_email' => 'non-un-indirizzo']))
        ->assertSessionHasErrors('contact_email');

    expect(session()->getOldInput('password'))->toBeNull()
        ->and(User::query()->where('email', 'chiara@osteria-portello.example')->exists())->toBeFalse()
        ->and(iscrizioneRichieste()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('riconosce un account esistente scritto in maiuscolo nel modulo del locale e lascia a lui la conferma della richiesta', function (): void {
    $titolare = User::factory()->create(['email' => 'marco@enoteca.example', 'password' => Hash::make('password-di-marco')]);

    $this->post('/registra-il-tuo-locale', iscrizioneModuloLocale(['venue_name' => 'Enoteca Marco', 'contact_email' => 'Marco@Enoteca.EXAMPLE']))
        ->assertSessionHas('application_sent', 'check_email');

    expect(User::query()->where('email', 'marco@enoteca.example')->count())->toBe(1)
        ->and(Hash::check('password-di-marco', (string) $titolare->fresh()->password))->toBeTrue();
    Notification::assertSentTo($titolare, AccountAlreadyRegistered::class);
    $richiesta = iscrizioneRichieste()->sole();
    expect($richiesta->user_id)->toBe($titolare->id)->and($richiesta->isAwaitingEmail())->toBeTrue();

    /* Entra con la sua password e la trova da confermare. */
    $this->post('/accedi', ['email' => 'marco@enoteca.example', 'password' => 'password-di-marco'])->assertRedirect();
    $this->get('/registra-il-tuo-locale')->assertOk()->assertSee('Enoteca Marco');
    $this->from('/registra-il-tuo-locale')->post(route('venue-applications.confirm', $richiesta->id))
        ->assertRedirect('/registra-il-tuo-locale')
        ->assertSessionHas('status', __('forms.application.confirmed'));

    expect($richiesta->fresh()->isAwaitingEmail())->toBeFalse();
    Notification::assertSentTo($this->redazione, AdminVenueApplicationSubmitted::class);
});

it('tiene in attesa la richiesta di chi è collegato ma non ha confermato, e la mette in coda alla conferma', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'collegata.non.confermata@example.test', 'password' => Hash::make('password-collegata')]);
    $this->post('/accedi', ['email' => 'collegata.non.confermata@example.test', 'password' => 'password-collegata'])->assertRedirect();

    $this->post('/registra-il-tuo-locale', iscrizioneModuloLocale(['password' => null, 'password_confirmation' => null, 'contact_email' => null]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('application_sent', 'verify');
    $richiesta = iscrizioneRichieste()->sole();
    expect($richiesta->user_id)->toBe($user->id)
        ->and($richiesta->contact_email)->toBe('collegata.non.confermata@example.test')
        ->and($richiesta->isAwaitingEmail())->toBeTrue();

    $this->post('/email/verifica')->assertRedirect();
    $this->get((string) Notification::sent($user, VerifyEmailLink::class)->first()->toMail($user)->actionUrl)->assertRedirect();

    expect($richiesta->fresh()->isAwaitingEmail())->toBeFalse();
    Notification::assertSentTo($this->redazione, AdminVenueApplicationSubmitted::class);
});

it('registra un organizzazione da ospite, e all approvazione la persona sceglie la password ed entra nel suo pannello', function (): void {
    $this->post(route('organizer-applications.store'), [
        'venue_name' => 'Rassegna Sotto le Mura',
        'contact_name' => 'Pietro Galli',
        'contact_email' => 'Pietro.Galli@Rassegna.example',
        'message' => 'Concerti itineranti in estate.',
        StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute()),
    ])->assertRedirect(route('organizer-applications.create'))->assertSessionHas('application_sent', 'organizer');

    /* Da ospite nessun account nasce prima dell'approvazione. */
    expect(User::query()->where('email', 'pietro.galli@rassegna.example')->exists())->toBeFalse();

    $organizzatore = app(ApproveVenueApplication::class)->handle(iscrizioneRichieste()->sole(), $this->redazione);
    $pietro = User::query()->where('email', 'pietro.galli@rassegna.example')->sole();
    Notification::assertSentTo($pietro, OrganizerAccessGranted::class);

    /* Il messaggio porta a scegliere la password: è anche la prova della casella. */
    $link = (string) Notification::sent($pietro, OrganizerAccessGranted::class)->first()->toMail($pietro)->actionUrl;
    expect($link)->toStartWith(route('account.password.reset'));
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    $this->get($link)->assertOk();
    $this->post('/reimposta-password', [
        'token' => $query['token'],
        'email' => $query['email'],
        'password' => 'password-di-pietro',
        'password_confirmation' => 'password-di-pietro',
    ])->assertRedirect(route('login'));
    expect($pietro->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->post('/accedi', ['email' => 'pietro.galli@rassegna.example', 'password' => 'password-di-pietro'])
        ->assertRedirect(url('/organizza/'.$organizzatore->slug));
    $this->get('/organizza/'.$organizzatore->slug)->assertOk();
    $this->get('/gestione')->assertForbidden();
});

it('approva l organizzazione chiesta da un iscritto collegato senza toccargli la password', function (): void {
    $iscritta = User::factory()->create(['email' => 'iscritta.organizza@example.test', 'password' => Hash::make('password-iscritta')]);
    $this->post('/accedi', ['email' => 'iscritta.organizza@example.test', 'password' => 'password-iscritta'])->assertRedirect(route('account.feed'));

    $this->post(route('organizer-applications.store'), [
        'venue_name' => 'Circolo Letterario',
        'contact_name' => 'Iscritta',
        StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute()),
    ])->assertSessionHasNoErrors();
    $richiesta = iscrizioneRichieste()->sole();
    expect($richiesta->user_id)->toBe($iscritta->id);
    $this->get('/organizza')->assertForbidden();

    $organizzatore = app(ApproveVenueApplication::class)->handle($richiesta, $this->redazione);
    expect($organizzatore)->toBeInstanceOf(Organizer::class)
        ->and(Hash::check('password-iscritta', (string) $iscritta->fresh()->password))->toBeTrue();

    /* La notifica porta al pannello, non a una password nuova. */
    $link = (string) Notification::sent($iscritta, OrganizerAccessGranted::class)->first()->toMail($iscritta)->actionUrl;
    expect($link)->not->toContain('reimposta-password');

    $this->post('/esci');
    $this->post('/accedi', ['email' => 'iscritta.organizza@example.test', 'password' => 'password-iscritta'])
        ->assertRedirect(url('/organizza/'.$organizzatore->slug));
});
