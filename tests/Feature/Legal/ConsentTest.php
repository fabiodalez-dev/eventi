<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use App\Http\Middleware\CachePage;
use App\Models\ConsentLog;
use App\Models\Page;
use App\Models\User;
use App\Support\Consent;
use Database\Seeders\PageSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Il banner del consenso (§16): preventivo, granulare, con il rifiuto semplice
 * quanto l'accettazione, e con il registro di ciò che è stato scelto.
 */
beforeEach(function (): void {
    testCity();
    config()->set('consent.version', '2026-08-31');
});

/**
 * I due pulsanti di scelta, estratti dall'HTML con le loro classi. È il modo
 * di verificare la parità visiva senza affidarla alla rilettura di un diff.
 *
 * @return array<string, string>
 */
function consentButtons(string $html): array
{
    preg_match_all(
        '/<button[^>]*name="action"[^>]*value="(accept_all|reject_all)"[^>]*class="([^"]*)"[^>]*>/',
        $html,
        $matches,
        PREG_SET_ORDER,
    );

    $buttons = [];

    foreach ($matches as $match) {
        $buttons[$match[1]] = $match[2];
    }

    return $buttons;
}

describe('comparsa', function (): void {
    it('compare al primo accesso', function (): void {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-consent-banner', false)
            ->assertSee(__('consent.accept'))
            ->assertSee(__('consent.reject'));
    });

    it('non compare alla visita successiva, dopo aver accettato', function (): void {
        $accepted = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        $accepted->assertRedirect();

        requestWithCookies('GET', '/', cookiesFrom($accepted))
            ->assertOk()
            ->assertDontSee('data-consent-banner', false);
    });

    it('non compare alla visita successiva, dopo aver rifiutato', function (): void {
        /*
         * È la metà che si dimentica: un banner che sparisce solo a chi accetta
         * è un banner che punisce il rifiuto, e chi rifiuta se lo ritrova a
         * ogni pagina finché non cede.
         */
        $rejected = $this->post('/consenso', ['action' => ConsentAction::RejectAll->value]);

        requestWithCookies('GET', '/', cookiesFrom($rejected))
            ->assertOk()
            ->assertDontSee('data-consent-banner', false);
    });

    it('ricompare quando cambia la versione dell\'informativa', function (): void {
        $accepted = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);
        $cookies = cookiesFrom($accepted);

        config()->set('consent.version', '2027-01-01');

        requestWithCookies('GET', '/', $cookies)
            ->assertOk()
            ->assertSee('data-consent-banner', false);
    });

    it('non impedisce la lettura del sito', function (): void {
        $html = $this->get('/')->assertOk()->getContent();

        /*
         * Nessun velo che oscura la pagina, nessun blocco dello scorrimento:
         * il banner è una striscia in fondo. Chi vuole leggere e decidere
         * dopo, può.
         */
        expect($html)
            ->not->toContain('data-consent-backdrop')
            ->and($html)->not->toContain('overflow-hidden" data-consent');

        expect(substr_count($html, 'data-consent-banner'))->toBe(1);
    });
});

describe('parità fra accettazione e rifiuto', function (): void {
    it('presenta due pulsanti con la stessa identica classe', function (): void {
        $buttons = consentButtons($this->get('/')->assertOk()->getContent());

        expect($buttons)->toHaveKeys(['accept_all', 'reject_all'])
            ->and($buttons['reject_all'])->toBe($buttons['accept_all']);
    });

    it('rende il rifiuto raggiungibile da tastiera come l\'accettazione', function (): void {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/<button[^>]*value="reject_all"[^>]*>/', $html, $reject);
        preg_match('/<button[^>]*value="accept_all"[^>]*>/', $html, $accept);

        expect($reject)->not->toBeEmpty()
            ->and($accept)->not->toBeEmpty();

        foreach ([$accept[0], $reject[0]] as $button) {
            // Un `<button type="submit">` senza `tabindex` negativo, senza
            // `disabled` e senza `hidden` è raggiungibile con il tabulatore.
            expect($button)
                ->toContain('type="submit"')
                ->not->toContain('tabindex="-1"')
                ->not->toContain('disabled')
                ->not->toContain('hidden');
        }
    });

    it('registra il rifiuto in un solo invio, senza passare dalle preferenze', function (): void {
        $this->post('/consenso', ['action' => ConsentAction::RejectAll->value])->assertRedirect();

        $log = ConsentLog::query()->firstOrFail();

        expect($log->action)->toBe(ConsentAction::RejectAll)
            ->and($log->allows(ConsentCategory::Statistics))->toBeFalse();
    });
});

describe('scelta granulare', function (): void {
    it('accetta una finalità sola', function (): void {
        $this->post('/consenso', [
            'action' => ConsentAction::Custom->value,
            'categories' => [ConsentCategory::Statistics->value],
        ])->assertRedirect();

        $log = ConsentLog::query()->firstOrFail();

        expect($log->action)->toBe(ConsentAction::Custom)
            ->and($log->allows(ConsentCategory::Statistics))->toBeTrue()
            ->and($log->allows(ConsentCategory::Necessary))->toBeTrue();
    });

    it('rifiuta anche se una casella è rimasta spuntata', function (): void {
        /*
         * Conta il pulsante premuto, non lo stato del modulo: leggere le
         * caselle invece dell'azione è il modo esatto in cui un banner
         * registra il contrario di ciò che gli è stato detto.
         */
        $this->post('/consenso', [
            'action' => ConsentAction::RejectAll->value,
            'categories' => [ConsentCategory::Statistics->value],
        ])->assertRedirect();

        expect(ConsentLog::query()->firstOrFail()->allows(ConsentCategory::Statistics))->toBeFalse();
    });

    it('la categoria necessaria resta vera anche rifiutando tutto', function (): void {
        $this->post('/consenso', ['action' => ConsentAction::RejectAll->value]);

        expect(ConsentLog::query()->firstOrFail()->allows(ConsentCategory::Necessary))->toBeTrue();
    });

    it('scarta un\'azione che non esiste', function (): void {
        $this->post('/consenso', ['action' => 'accetta-tutto-per-sempre'])
            ->assertSessionHasErrors('action');

        expect(ConsentLog::query()->count())->toBe(0);
    });
});

describe('registro del consenso', function (): void {
    it('scrive una riga con versione e finalità', function (): void {
        $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        $log = ConsentLog::query()->firstOrFail();

        expect($log->policy_version)->toBe('2026-08-31')
            ->and($log->consent_id)->not->toBeEmpty()
            /*
             * L'elenco è scritto per esteso, e deve restare così.
             *
             * Derivarlo da `ConsentCategory::cases()` renderebbe il test
             * sempre verde — anche il giorno in cui qualcuno aggiunge una
             * finalità di trattamento senza accorgersene. Aggiungere una
             * categoria è una decisione con conseguenze legali: che questa
             * riga si rompa è il suo unico modo di chiedere che qualcuno la
             * guardi. È successo con «marketing», arrivata quando sono
             * arrivate le sponsorizzazioni.
             */
            ->and($log->choices)->toBe([
                ConsentCategory::Necessary->value => true,
                ConsentCategory::Statistics->value => true,
                ConsentCategory::Marketing->value => true,
            ]);
    });

    it('non sovrascrive la scelta precedente: ne aggiunge una nuova con lo stesso identificativo', function (): void {
        $first = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        test()->call('POST', '/consenso', ['action' => ConsentAction::RejectAll->value], cookiesFrom($first));

        $logs = ConsentLog::query()->orderBy('id')->get();

        expect($logs)->toHaveCount(2)
            ->and($logs[1]->consent_id)->toBe($logs[0]->consent_id)
            ->and($logs[0]->action)->toBe(ConsentAction::AcceptAll)
            ->and($logs[1]->action)->toBe(ConsentAction::RejectAll);
    });

    it('collega la riga all\'utente se una sessione è aperta', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        expect((int) ConsentLog::query()->firstOrFail()->user_id)->toBe((int) $user->getKey());
    });

    it('non conserva l\'indirizzo IP di chi sceglie', function (): void {
        $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        $columns = array_keys(ConsentLog::query()->firstOrFail()->getAttributes());

        expect($columns)->not->toContain('ip_address');
    });
});

describe('revoca', function (): void {
    it('cancella la scelta e fa ricomparire il banner', function (): void {
        $accepted = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        $revoked = requestWithCookies('DELETE', '/consenso', cookiesFrom($accepted));

        $revoked->assertRedirect();

        requestWithCookies('GET', '/', cookiesFrom($revoked))
            ->assertOk()
            ->assertSee('data-consent-banner', false);
    });

    it('offre il pannello dentro la cookie policy', function (): void {
        (new PageSeeder)->run();

        $this->get('/pagine/cookie')
            ->assertOk()
            ->assertSee(__('consent.manage.title'))
            ->assertSee(__('consent.manage.current_none'));
    });

    it('non offre la revoca a chi non ha ancora scelto', function (): void {
        Page::factory()->create(['slug' => 'cookie', 'title' => 'Cookie policy']);

        $this->get('/pagine/cookie')
            ->assertOk()
            ->assertDontSee(__('consent.manage.revoke'));
    });
});

describe('cache di pagina', function (): void {
    beforeEach(function (): void {
        config()->set('page_cache.enabled', true);
        Cache::flush();
    });

    it('non serve a chi non ha scelto la copia di chi ha accettato', function (): void {
        $accepted = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);
        $cookies = cookiesFrom($accepted);

        // Chi ha accettato riempie la cache: nessun banner.
        requestWithCookies('GET', '/', $cookies)->assertOk()->assertDontSee('data-consent-banner', false);

        // Chi arriva dopo, senza aver scelto, deve vederlo comunque.
        requestWithCookies('GET', '/')->assertOk()->assertSee('data-consent-banner', false);
    });

    it('dà chiavi diverse a scelte diverse', function (): void {
        $request = Request::create('/');
        $middleware = new CachePage;

        $senza = $middleware->key($request);

        app(Consent::class)->remember(new ConsentState(
            id: 'ab5c8d64-0a7e-4a94-9f5f-5c2f3c9c0d11',
            version: '2026-08-31',
            choices: [ConsentCategory::Necessary->value => true, ConsentCategory::Statistics->value => true],
        ));

        expect($middleware->key($request))->not->toBe($senza);
    });
});
