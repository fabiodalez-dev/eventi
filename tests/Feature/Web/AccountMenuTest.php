<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Il menu dell'account nella testata.
 *
 * Prima c'era un solo bottone, «Salvati», che per chi non era collegato
 * portava alla pagina di accesso: dopo l'accesso non cambiava niente. Non
 * c'era una via verso il proprio profilo, il proprio feed o l'uscita — e chi
 * amministra il sito, entrando dal sito pubblico, non aveva **nessun
 * collegamento verso il pannello**: doveva ricordarsi `/admin` e scriverlo a
 * mano.
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
});

it('invita ad accedere chi non e collegato', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('login'), false)
        ->assertSee(__('account.nav.login'), false)
        /* E non mostra il menu, che non avrebbe niente da dire. */
        ->assertDontSee('data-account-menu', false);
});

it('mostra le proprie pagine a chi e collegato', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Marta']));

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-account-menu', false)
        ->assertSee(route('account.saved'), false)
        ->assertSee(route('account.feed'), false)
        ->assertSee(route('account.profile'), false)
        ->assertSee(__('account.nav.logout'), false)
        /* Il nome di chi e collegato: e' l'informazione per cui si guarda li'. */
        ->assertSee('Marta', false);
});

it('non offre l amministrazione a un utente normale', function (): void {
    /*
     * La voce non e' una comodita': e' un indizio su cosa esiste. Mostrarla a
     * chi non puo' entrarci produce solo una porta chiusa in faccia — e dice a
     * chiunque che quel pannello c'e'.
     */
    $this->actingAs(User::factory()->create());

    $risposta = $this->get(route('home'))->assertOk();

    $risposta->assertDontSee(__('account.nav.admin'), false);
});

it('porta un amministratore al pannello, che e la via che mancava', function (): void {
    $utente = User::factory()->create();
    $utente->syncRoles([UserRole::Admin->value]);

    $this->actingAs($utente);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(__('account.nav.admin'), false)
        ->assertSee('/admin', false);
});

it('la offre anche a un moderatore, che nel pannello ci lavora', function (): void {
    $utente = User::factory()->create();
    $utente->syncRoles([UserRole::Moderator->value]);

    $this->actingAs($utente);

    $this->get(route('home'))->assertOk()->assertSee(__('account.nav.admin'), false);
});

it('porta al proprio locale chi ne ha uno', function (): void {
    $utente = User::factory()->create();
    $utente->syncRoles([UserRole::VenueOwner->value]);

    $locale = Venue::factory()->create(['city_id' => testCity()->getKey()]);
    /* La relazione si chiama `members` sul locale e `venues` sull'utente. */
    $utente->venues()->attach($locale, ['role' => 'owner']);

    $this->actingAs($utente);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(__('account.nav.venue'), false)
        ->assertSee('/gestione', false);
});

it('non offre un locale a chi non ne ha', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertOk()->assertDontSee(__('account.nav.venue'), false);
});

it('l uscita e un modulo, non un collegamento', function (): void {
    /*
     * Una richiesta che cambia lo stato non deve poter partire da un `href`:
     * basta un'immagine con quell'indirizzo su un altro sito per far uscire
     * chi la visita.
     */
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('action="'.route('account.logout').'"')
        ->and($html)->not->toContain('href="'.route('account.logout').'"');
});

it('funziona senza JavaScript', function (): void {
    /*
     * E' un `<details>`: si apre e si chiude da solo, e da tastiera e' un
     * elemento che i browser sanno gia' gestire. Un menu costruito a mano
     * richiederebbe di riscrivere fuoco, Esc e clic-fuori — e di solito se ne
     * riscrive meta'.
     */
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('<details')
        ->and($html)->toContain('<summary');
});
