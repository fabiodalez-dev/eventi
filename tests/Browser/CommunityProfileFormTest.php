<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Models\User;
use App\Models\Venue;
use App\Services\Community\Community;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Le due funzioni nuove del modulo del profilo sono JavaScript, quindi il
 * markup non basta a dire che funzionano: qui si guarda cosa fa il browser.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    $this->city = testCity();
    (new RolesAndPermissionsSeeder)->run();
    $this->user = User::factory()->create(['first_name' => 'Chiara', 'last_name' => 'Bersani']);
    $this->user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'browser-profilo')])->save();
    $this->user = $this->user->fresh();
});

it('dice nel browser se il nome utente è preso o libero', function (): void {
    $altra = User::factory()->create();
    $altra->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'browser-altra')])->save();
    app(Community::class)->profile($altra->fresh(), ['handle' => 'nome_occupato',
        'display_name' => 'Chi c’era prima', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $this->actingAs($this->user);
    $page = visit('/profilo-social');
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');

    // Il nome proposto arriva dall'iscrizione, il cognome no.
    expect($page->script('document.querySelector("input[name=display_name]").value'))->toBe('Chiara');

    $page->fill('handle', 'nome_occupato');
    $page->assertSee(__('community.handle_taken'));
    expect($page->script('document.querySelector("[data-nome-utente-segno]").textContent'))->toBe('✗');

    $page->fill('handle', 'nome_tutto_mio');
    $page->assertSee(__('community.handle_free'));
    expect($page->script('document.querySelector("[data-nome-utente-segno]").textContent'))->toBe('✓');

    // Sotto le tre lettere non si chiede niente al server: si spiega e basta.
    $page->fill('handle', 'ab');
    $page->assertSee(__('community.handle_short'));
});

it('sceglie i locali con la ricerca e li toglie dalle chip, spuntando le caselle vere', function (): void {
    $pedro = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Centro Sociale Pedro']);
    $libreria = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Libreria Feltrinelli']);
    foreach ([$pedro, $libreria] as $locale) {
        $this->user->follows()->create(['followable_type' => 'venue', 'followable_id' => $locale->getKey()]);
    }

    $this->actingAs($this->user->fresh());
    $page = visit('/profilo-social');
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');

    // L'elenco di caselle si nasconde, ma resta nel documento e resta attivo.
    expect($page->script('document.querySelector("[data-locali-caselle]").hidden'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-locali-arricchito]").hidden'))->toBeFalse()
        ->and($page->script('document.querySelectorAll("[data-locali-caselle] input:disabled").length'))->toBe(0);

    $page->script('document.querySelector("[data-locali]").closest("details").open = true');
    /* Il clic sul campo vuoto apre l'elenco. Con la soglia di due lettere del
       wizard non compariva niente, e su due o quattro locali quel silenzio è
       indistinguibile da un campo rotto. */
    $page->click('#locali-cerca');
    expect($page->script('document.querySelectorAll("[data-locali-opzioni] li").length'))->toBe(2);

    // Una lettera sola basta a restringere.
    $page->fill('#locali-cerca', 'p');
    expect($page->script('[...document.querySelectorAll("[data-locali-opzioni] li")].map((e) => e.textContent)'))
        ->toBe(['Centro Sociale Pedro']);

    $page->click('[data-locali-opzioni] li');

    // La chip c'è e la casella corrispondente è spuntata: è lei che viene inviata.
    $page->assertSee('Centro Sociale Pedro');
    expect($page->script('document.querySelector("[data-locali-caselle] input[value=\"'.$pedro->getKey().'\"]").checked'))->toBeTrue()
        ->and($page->script('document.querySelectorAll("[data-locali-scelti] li").length'))->toBe(1);

    // Chi è già scelto non ricompare fra i risultati.
    $page->fill('#locali-cerca', 'pedro');
    expect($page->script('document.querySelectorAll("[data-locali-opzioni] li").length'))->toBe(0);

    /* Scelti tutti, il campo lo dice invece di tacere: era l'altro modo in cui
       sembrava rotto. */
    $page->fill('#locali-cerca', '');
    $page->click('[data-locali-opzioni] li');
    $page->assertSee(__('community.venue_all_chosen'));

    /* La × della chip despunta la casella. Il selettore nomina la chip: ora ce
       ne sono due, e `[data-locali-scelti] button` da solo ne troverebbe due. */
    $page->click('[data-locali-scelti] button[aria-label$="Centro Sociale Pedro"]');
    expect($page->script('document.querySelector("[data-locali-caselle] input[value=\"'.$pedro->getKey().'\"]").checked'))->toBeFalse()
        ->and($page->script('document.querySelectorAll("[data-locali-scelti] li").length'))->toBe(1)
        // Tolta una scelta, l'avviso «hai già scelto tutti» se ne va.
        ->and($page->script('document.querySelector("[data-locali-stato]").textContent'))->toBe('');
});

/*
 * Manca di proposito la prova dell'invio dal browser, e ora si sa perché.
 *
 * Questo modulo non si invia **da questo banco di prova**: i campi non arrivano,
 * e la stessa cosa succede su `main` senza alcuna modifica. Isolato per codifica,
 * con tre richieste identiche nel contenuto partite dalla pagina: multipart con
 * il campo file 422, multipart senza il campo file 422, urlencoded 200. Non è il
 * campo file, è la codifica — ed è l'unico modulo multipart del progetto, per cui
 * nessun altro test poteva incontrarlo.
 *
 * **Non è un guasto dell'applicazione, e non lo è nemmeno in produzione.**
 * Verificato in tre modi: `curl` multipart con un'immagine vera contro
 * `artisan serve` crea il profilo e allega la foto; lo stesso multipart forzato
 * in chunked passa; e in produzione il percorso completo del controller —
 * decodifica, ricodifica, allegato — produce un media servito in `image/jpeg`
 * con HTTP 200. Il server di sviluppo di PHP, da solo, analizza il multipart
 * correttamente comprese le parti file vuote.
 *
 * Resta quindi un limite del solo trasporto fra Chrome e il server di prova, di
 * cui non ho trovato la causa esatta. Il caricamento è coperto dove conta: in
 * `ProfileFormTest` dal modulo del sito, e in `CommunitySecurityTest` dalla
 * rotta dell'app. Se un giorno questo invio comincerà a funzionare, la prova va
 * aggiunta qui; finché non funziona, non vale inseguirlo.
 */
