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
    $page->fill('#locali-cerca', 'pedro');
    $page->assertPresent('[data-locali-opzioni] li');
    $page->click('[data-locali-opzioni] li');

    // La chip c'è e la casella corrispondente è spuntata: è lei che viene inviata.
    $page->assertSee('Centro Sociale Pedro');
    expect($page->script('document.querySelector("[data-locali-caselle] input[value=\"'.$pedro->getKey().'\"]").checked'))->toBeTrue()
        ->and($page->script('document.querySelectorAll("[data-locali-scelti] li").length'))->toBe(1);

    // Chi è già scelto non ricompare fra i risultati.
    $page->fill('#locali-cerca', 'pedro');
    expect($page->script('document.querySelectorAll("[data-locali-opzioni] li").length'))->toBe(0);

    // La × della chip despunta la casella.
    $page->click('[data-locali-scelti] button');
    expect($page->script('document.querySelector("[data-locali-caselle] input[value=\"'.$pedro->getKey().'\"]").checked'))->toBeFalse()
        ->and($page->script('document.querySelectorAll("[data-locali-scelti] li").length'))->toBe(0);
});

/*
 * Manca di proposito la prova dell'invio dal browser.
 *
 * Non per pigrizia: **questo modulo non si invia** nel banco di prova, e non per
 * colpa di questo lavoro. Verificato su `main` senza nessuna di queste modifiche,
 * e isolato per codifica con tre richieste identiche nel contenuto:
 *
 *   multipart con il campo file    -> 422, i campi non arrivano
 *   multipart senza il campo file  -> 422, i campi non arrivano
 *   application/x-www-form-urlencoded -> 200
 *
 * Quindi non è il campo file: è la codifica. `enctype="multipart/form-data"` sta
 * qui perché serve alla foto del profilo, e questo è l'unico modulo multipart di
 * tutto il sito — per cui nessun altro test avrebbe potuto accorgersene.
 *
 * Resta aperto se la cosa valga anche in produzione, dove gira PHP-FPM dietro
 * LiteSpeed e non il server di sviluppo. Indizio, non prova: dei 18 profili in
 * produzione **nessuno** ha una foto caricata. Accertarlo richiede un invio vero
 * sul sito, cioè una scrittura sui dati di produzione.
 */
