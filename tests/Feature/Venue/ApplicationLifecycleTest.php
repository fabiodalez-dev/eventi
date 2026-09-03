<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Enums\ApplicationStatus;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\VenueAccessGranted;
use App\Services\Geo\AddressGeocoder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Dalla richiesta al locale, in un gesto solo.
 *
 * Il modulo pubblico e la coda esistevano gia'; mancava il pezzo in mezzo.
 * Per accettare una richiesta bisognava leggerla, aprire un'altra pagina e
 * ricopiare i dati a mano — col rischio di ricopiarli male e la certezza di
 * perdere il filo fra il locale nuovo e chi l'aveva chiesto.
 */
beforeEach(function (): void {
    Notification::fake();

    /*
     * Il geocodificatore vero chiama OpenStreetMap: una suite che lo
     * interroga e' lenta, dipende dalla rete e consuma il limite di una
     * richiesta al secondo di un servizio gratuito. Qui si dichiara cosa
     * risponde, e i test che vogliono il caso «non trovato» lo cambiano.
     */
    $this->geocoder = Mockery::mock(AddressGeocoder::class);
    $this->geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $this->geocoder);

    /* I ruoli globali servono davvero: l'invito assegna `venue_owner`, e senza
       il seeder l'approvazione fallisce con «There is no role named». E' lo
       stesso seeder che gira in produzione, quindi il test prova il percorso
       vero e non una versione semplificata. */
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->citta = City::factory()->create([
        'center_lat' => 45.4064,
        'center_lng' => 11.8768,
    ]);

    $this->moderatore = User::factory()->create();
    $this->richiesta = VenueApplication::query()->create([
        'venue_name' => 'Circolo Arci Prova',
        'contact_name' => 'Anna Bianchi',
        'contact_email' => 'anna@example.test',
        'contact_phone' => '049 000000',
        'address' => 'Via delle Prove 1',
        'type' => 'associazione',
        'message' => 'Vorremmo pubblicare i nostri concerti.',
        'status' => ApplicationStatus::Pending,
    ]);
});

it('crea il locale con i dati della richiesta', function (): void {
    $locale = app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);

    expect($locale->name)->toBe('Circolo Arci Prova')
        ->and($locale->address)->toBe('Via delle Prove 1')
        ->and($locale->email)->toBe('anna@example.test');
});

it('lo lascia in bozza, perche accettare non e pubblicare', function (): void {
    $locale = app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);

    /* Alla richiesta mancano quasi sempre coordinate, orari e una foto:
       pubblicarla d'ufficio metterebbe online una scheda mezza vuota. La
       pubblicazione resta una seconda decisione, presa guardando una scheda
       finita. */
    expect($locale->status)->toBe(VenueStatus::Draft);
});

it('invita chi ha scritto, come referente', function (): void {
    app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);

    $invitato = User::query()->where('email', 'anna@example.test')->first();

    expect($invitato)->not->toBeNull();
    Notification::assertSentTo($invitato, VenueAccessGranted::class);

    /* Senza il collegamento nella pivot avremmo creato un locale che il suo
       referente non puo' toccare: `/gestione` guarda esattamente quella. */
    expect($invitato->venues()->wherePivot('role', VenueRole::Owner->value)->exists())->toBeTrue();
});

it('lega la richiesta al locale che ne e nato', function (): void {
    $locale = app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);
    $fresca = $this->richiesta->fresh();

    /* E' il filo che permette, fra sei mesi, di sapere da dove venne questa
       scheda e chi la chiese. */
    expect($fresca->venue_id)->toBe($locale->getKey())
        ->and($fresca->status)->toBe(ApplicationStatus::Approved)
        ->and($fresca->reviewed_by)->toBe($this->moderatore->getKey());
});

it('non crea un secondo locale se la richiesta viene approvata due volte', function (): void {
    $azione = app(ApproveVenueApplication::class);
    $primo = $azione->handle($this->richiesta, $this->moderatore);
    $secondo = $azione->handle($this->richiesta->fresh(), $this->moderatore);

    /* Due schede per lo stesso posto sono un guaio che si scopre mesi dopo,
       quando gli eventi sono divisi fra le due. */
    expect($secondo->getKey())->toBe($primo->getKey())
        ->and(Venue::query()->where('name', 'Circolo Arci Prova')->count())->toBe(1);
});

it('non fallisce quando due locali si chiamano davvero allo stesso modo', function (): void {
    Venue::factory()->create(['name' => 'Circolo Arci Prova', 'slug' => 'circolo-arci-prova']);

    $locale = app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);

    /* «Bar Centrale» esiste in due comuni diversi, e la colonna e' unica:
       senza suffisso la seconda approvazione morirebbe con un errore di
       database davanti a chi sta solo accettando una richiesta. */
    expect($locale->slug)->toBe('circolo-arci-prova-2');
});

it('mette il locale dove dice il suo indirizzo', function (): void {
    $locale = app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);

    /* L'indirizzo scritto nella richiesta diventa un punto vero: prima si
       scriveva sempre il centro della citta', e un locale a due chilometri
       compariva in centro. */
    expect((float) $locale->lat)->toBe(45.4111)
        ->and((float) $locale->lng)->toBe(11.8765);
});

it('ricade sul centro della citta quando l indirizzo non si trova', function (): void {
    $this->geocoder->shouldReceive('coordinate')->andReturn(null);

    $locale = app(ApproveVenueApplication::class)->handle($this->richiesta, $this->moderatore);

    /*
     * Un indirizzo scritto male, o un servizio irraggiungibile, non devono
     * fermare chi sta approvando: resta il centro, da correggere sulla
     * scheda. Ed e' comunque un punto vero — la prima stesura leggeva
     * `City::$lat`, che non esiste, e `null ?? 0` mandava ogni locale al
     * largo dell'Africa con tutti i test verdi.
     */
    expect((float) $locale->lat)->toBe(45.4064)
        ->and((float) $locale->lng)->toBe(11.8768)
        ->and((float) $locale->lat)->not->toBe(0.0);
});
