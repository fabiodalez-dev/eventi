<?php

declare(strict_types=1);

use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use App\Models\ConsentLog;
use App\Models\ConsentScript;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\PageSeeder;

/**
 * Il consenso ai cookie (§16) percorso come lo percorre un visitatore: il
 * banner al primo accesso, la scelta, gli script che compaiono **solo** dopo
 * il consenso, il ripensamento e la revoca dalla cookie policy. E le pagine
 * legali raggiunte dal piè di pagina, sul sito e nell'app.
 */
beforeEach(function (): void {
    testCity();
    config()->set('consent.version', '2026-08-31');

    // Statistiche ospitate in proprio, configurate come in un .env vero.
    config()->set('analytics.provider', 'plausible');
    config()->set('analytics.domain', 'eventi.fabiodalez.it');
    config()->set('analytics.src', 'https://statistiche.esempio.test/script.js');

    ConsentScript::create(['name' => 'Mappa di calore', 'category' => ConsentCategory::Statistics, 'enabled' => true, 'src' => 'https://terzi.esempio.test/calore-statistiche.js']);
    ConsentScript::create(['name' => 'Pixel pubblicitario', 'category' => ConsentCategory::Marketing, 'enabled' => true, 'src' => 'https://terzi.esempio.test/pixel-marketing.js']);
});

it('carica statistiche e marketing solo dopo l accettazione e li toglie con la revoca', function (): void {
    // 1. Primo accesso: il banner c'è, nessuno script di terzi.
    $this->get('/')
        ->assertOk()
        ->assertSee('data-consent-banner', false)
        ->assertDontSee('statistiche.esempio.test', false)
        ->assertDontSee('calore-statistiche.js', false)
        ->assertDontSee('pixel-marketing.js', false);

    // 2. Accetta tutto dal banner (modulo senza JavaScript).
    $accettato = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value])
        ->assertRedirect()
        ->assertSessionHas('status', __('consent.saved'));

    $registro = ConsentLog::query()->sole();

    expect($registro->action)->toBe(ConsentAction::AcceptAll)
        ->and($registro->policy_version)->toBe('2026-08-31')
        ->and($registro->allows(ConsentCategory::Statistics))->toBeTrue()
        ->and($registro->allows(ConsentCategory::Marketing))->toBeTrue()
        ->and($registro->user_id)->toBeNull();

    // 3. Alla pagina successiva il banner non c'è e gli script sì.
    requestWithCookies('GET', '/', cookiesFrom($accettato))
        ->assertOk()
        ->assertDontSee('data-consent-banner', false)
        ->assertSee('https://statistiche.esempio.test/script.js', false)
        ->assertSee('calore-statistiche.js', false)
        ->assertSee('pixel-marketing.js', false);

    // 4. Revoca: il cookie viene cancellato e si torna al punto di partenza.
    $revocato = requestWithCookies('DELETE', '/consenso', cookiesFrom($accettato))
        ->assertRedirect()
        ->assertSessionHas('status', __('consent.revoked'));

    $cancellato = collect($revocato->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'consenso');

    expect(cookiesFrom($accettato))->toHaveKey('consenso')
        ->and($cancellato)->not->toBeNull()
        ->and($cancellato->isCleared())->toBeTrue();

    requestWithCookies('GET', '/', cookiesFrom($revocato))
        ->assertOk()
        ->assertSee('data-consent-banner', false)
        ->assertDontSee('statistiche.esempio.test', false)
        ->assertDontSee('pixel-marketing.js', false);

    // La revoca non cancella la prova della scelta di prima.
    expect(ConsentLog::query()->count())->toBe(1);
});

it('sceglie dal pannello le sole statistiche, poi ci ripensa e rifiuta tutto con lo stesso identificativo', function (): void {
    $marta = User::factory()->create();

    // 1. Scelta granulare inviata dallo script del banner: risposta JSON, nessun ricaricamento.
    $parziale = $this->actingAs($marta)->postJson('/consenso', [
        'action' => ConsentAction::Custom->value,
        'categories' => [ConsentCategory::Statistics->value],
    ])
        ->assertOk()
        ->assertJsonPath('choices.statistics', true)
        ->assertJsonPath('choices.marketing', false)
        ->assertJsonPath('choices.necessary', true);

    $primo = ConsentLog::query()->sole();

    expect((int) $primo->user_id)->toBe((int) $marta->getKey())
        ->and($primo->action)->toBe(ConsentAction::Custom);

    // 2. Le statistiche compaiono, il marketing no.
    requestWithCookies('GET', '/', cookiesFrom($parziale))
        ->assertOk()
        ->assertDontSee('data-consent-banner', false)
        ->assertSee('https://statistiche.esempio.test/script.js', false)
        ->assertSee('calore-statistiche.js', false)
        ->assertDontSee('pixel-marketing.js', false);

    // Un'azione inventata è scartata, senza registrare nulla.
    $this->postJson('/consenso', ['action' => 'accetta-per-sempre'])->assertJsonValidationErrors(['action']);
    expect(ConsentLog::query()->count())->toBe(1);

    // 3. Ci ripensa: rifiuta tutto, anche con una casella rimasta spuntata.
    $rifiutato = test()->call('POST', '/consenso', [
        'action' => ConsentAction::RejectAll->value,
        'categories' => [ConsentCategory::Statistics->value],
    ], cookiesFrom($parziale))->assertRedirect();

    $registro = ConsentLog::query()->orderBy('id')->get();

    expect($registro)->toHaveCount(2)
        ->and($registro[1]->consent_id)->toBe($registro[0]->consent_id)
        ->and($registro[1]->action)->toBe(ConsentAction::RejectAll)
        ->and($registro[1]->allows(ConsentCategory::Statistics))->toBeFalse()
        ->and($registro[1]->allows(ConsentCategory::Necessary))->toBeTrue();

    // 4. Niente script e niente banner: il rifiuto è una scelta, e si ricorda.
    requestWithCookies('GET', '/', cookiesFrom($rifiutato))
        ->assertOk()
        ->assertDontSee('data-consent-banner', false)
        ->assertDontSee('statistiche.esempio.test', false)
        ->assertDontSee('calore-statistiche.js', false);
});

it('dal piè di pagina raggiunge informativa e cookie policy, e da lì gestisce il consenso; l app legge gli stessi testi', function (): void {
    (new PageSeeder)->run();
    Page::factory()->draft()->create(['title' => 'Bozza riservata', 'slug' => 'bozza-riservata']);

    // 1. Il piè di pagina elenca le pagine pubblicate e non la bozza.
    $home = $this->get('/')->assertOk()->getContent();

    expect($home)->toContain('href="'.route('pages.show', ['slug' => 'privacy']).'"')
        ->toContain('href="'.route('pages.show', ['slug' => 'cookie']).'"')
        ->not->toContain('Bozza riservata');

    // 2. Apre l'informativa: testo vero, indirizzo canonico, data di aggiornamento.
    $privacy = Page::query()->where('slug', 'privacy')->sole();

    $this->get('/pagine/privacy')
        ->assertOk()
        ->assertSee($privacy->title)
        ->assertSee('<link rel="canonical" href="'.route('pages.show', ['slug' => 'privacy']).'">', false);

    // 3. La cookie policy offre il pannello del consenso, senza revoca finché non si sceglie.
    $this->get('/pagine/cookie')
        ->assertOk()
        ->assertSee(__('consent.manage.title'))
        ->assertSee(__('consent.manage.current_none'))
        ->assertDontSee(__('consent.manage.revoke'));

    // 4. Accetta: tornando sulla cookie policy ora può revocare.
    $accettato = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

    requestWithCookies('GET', '/pagine/cookie', cookiesFrom($accettato))
        ->assertOk()
        ->assertSee(__('consent.manage.revoke'));

    // 5. Le bozze e gli indirizzi inventati non esistono.
    $this->get('/pagine/bozza-riservata')->assertNotFound();
    $this->get('/pagine/inventata')->assertNotFound();

    // 6. L'app mostra gli stessi testi.
    $elenco = $this->getJson('/api/v1/pages')->assertOk()->json('data');

    expect(collect($elenco)->pluck('slug')->all())->toContain('privacy', 'cookie', 'termini')
        ->not->toContain('bozza-riservata');

    $this->getJson('/api/v1/pages/privacy')->assertOk()->assertJsonPath('data.title', $privacy->title);
    $this->getJson('/api/v1/pages/bozza-riservata')->assertNotFound();
});
