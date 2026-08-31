<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Enums\AnalyticsProvider;
use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use App\Services\Analytics\AnalyticsScript;
use App\Support\Consent;
use Database\Seeders\PageSeeder;

/**
 * L'analitica senza cookie (§16, e §12 dello stack tecnologico).
 *
 * Le due promesse che questi test tengono ferme:
 *
 * 1. **Spenta significa spenta.** Con `ANALYTICS_*` vuote, nell'HTML non c'è
 *    alcun riferimento a un dominio esterno. Non uno script che non fa niente:
 *    proprio niente.
 * 2. **Preventivo significa preventivo.** Configurata ma senza consenso, non
 *    parte lo stesso. Chi non ha ancora scelto vale come chi ha rifiutato.
 */
beforeEach(function (): void {
    testCity();
    config()->set('consent.version', '2026-08-31');
});

/**
 * Accende le tre variabili di `.env` come le accenderebbe un'installazione
 * vera, con un server di statistiche ospitato in proprio.
 */
function configureAnalytics(string $provider = 'plausible'): void
{
    config()->set('analytics.provider', $provider);
    config()->set('analytics.domain', 'eventi.fabiodalez.it');
    config()->set('analytics.src', 'https://statistiche.esempio.test/script.js');
}

/**
 * Il consenso alle statistiche, espresso e già valido per la richiesta in
 * corso: evita di dover portare avanti il cookie in ogni test.
 */
function grantStatistics(bool $granted = true): void
{
    app(Consent::class)->remember(new ConsentState(
        id: '4d0a5f21-8f2b-4c4a-9f4c-1f5f9e6a2c33',
        version: (string) config('consent.version'),
        choices: [
            ConsentCategory::Necessary->value => true,
            ConsentCategory::Statistics->value => $granted,
        ],
    ));
}

describe('spenta', function (): void {
    beforeEach(function (): void {
        config()->set('analytics.provider', '');
        config()->set('analytics.domain', '');
        config()->set('analytics.src', '');
    });

    it('non considera configurato uno strumento senza variabili', function (): void {
        expect(app(AnalyticsScript::class)->configured())->toBeFalse()
            ->and(app(AnalyticsScript::class)->enabled())->toBeFalse();
    });

    it('non mette alcuno script esterno nell\'HTML', function (string $url): void {
        grantStatistics();

        $html = $this->get($url)->assertOk()->getContent();

        /*
         * Non basta cercare il nome del fornitore: si cerca **qualunque**
         * `src` o `href` che punti fuori dal nostro dominio. Un giorno lo
         * script potrebbe chiamarsi altrimenti; la promessa è che non ce ne
         * siano affatto.
         */
        preg_match_all('/(?:src|href)="(https?:\/\/[^"]+)"/i', $html, $matches);

        $esterni = array_values(array_filter(
            $matches[1],
            static fn (string $indirizzo): bool => ! str_starts_with($indirizzo, config('app.url'))
                // Attribuzioni e riferimenti di licenza sono collegamenti che
                // il browser segue solo se qualcuno li clicca: non sono
                // risorse caricate.
                && ! str_contains($indirizzo, 'openstreetmap.org')
                && ! str_contains($indirizzo, 'opendatacommons.org'),
        ));

        expect($esterni)->toBe([])
            ->and($html)->not->toContain('plausible')
            ->and($html)->not->toContain('umami');
    })->with([
        'pagina iniziale' => '/',
        'lista eventi' => '/eventi',
    ]);

    it('lo dice nella cookie policy invece di far finta', function (): void {
        (new PageSeeder)->run();

        $this->get('/pagine/cookie')
            ->assertOk()
            ->assertSee(__('consent.analytics.inactive'));
    });

    it('lo dice anche nel banner', function (): void {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('consent.body_without_analytics'))
            ->assertDontSee(__('consent.body'));
    });
});

describe('configurata ma senza consenso', function (): void {
    beforeEach(function (): void {
        configureAnalytics();
    });

    it('non carica niente a chi non ha ancora scelto', function (): void {
        $html = $this->get('/')->assertOk()->getContent();

        expect(app(AnalyticsScript::class)->configured())->toBeTrue()
            ->and($html)->not->toContain('statistiche.esempio.test');
    });

    it('non carica niente a chi ha rifiutato', function (): void {
        $rejected = $this->post('/consenso', ['action' => ConsentAction::RejectAll->value]);

        $html = requestWithCookies('GET', '/', cookiesFrom($rejected))
            ->assertOk()
            ->getContent();

        expect($html)->not->toContain('statistiche.esempio.test');
    });
});

describe('configurata e accettata', function (): void {
    it('carica lo script con l\'attributo giusto per Plausible', function (): void {
        configureAnalytics('plausible');

        $accepted = $this->post('/consenso', ['action' => ConsentAction::AcceptAll->value]);

        $html = requestWithCookies('GET', '/', cookiesFrom($accepted))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('src="https://statistiche.esempio.test/script.js"')
            ->toContain('data-domain="eventi.fabiodalez.it"')
            ->toContain('<script defer');
    });

    it('usa `data-website-id` per Umami', function (): void {
        configureAnalytics('umami');
        grantStatistics();

        expect(app(AnalyticsScript::class)->attributes())
            ->toBe([
                'src' => 'https://statistiche.esempio.test/script.js',
                'data-website-id' => 'eventi.fabiodalez.it',
            ]);
    });

    it('lo dichiara nella cookie policy con il nome e l\'ospite giusti', function (): void {
        configureAnalytics('plausible');
        (new PageSeeder)->run();

        $this->get('/pagine/cookie')
            ->assertOk()
            ->assertSee(__('consent.analytics.active', [
                'provider' => AnalyticsProvider::Plausible->label(),
                'host' => 'statistiche.esempio.test',
            ]));
    });
});

describe('configurazione incompleta o insicura', function (): void {
    it('resta spenta se manca una delle tre variabili', function (string $mancante): void {
        configureAnalytics();
        config()->set('analytics.'.$mancante, '');

        expect(app(AnalyticsScript::class)->configured())->toBeFalse();
    })->with(['provider', 'domain', 'src']);

    it('resta spenta se il fornitore non è fra quelli riconosciuti', function (): void {
        configureAnalytics('google-analytics');

        expect(app(AnalyticsScript::class)->provider())->toBeNull()
            ->and(app(AnalyticsScript::class)->configured())->toBeFalse();
    });

    it('rifiuta uno script servito in chiaro', function (): void {
        configureAnalytics();
        config()->set('analytics.src', 'http://statistiche.esempio.test/script.js');

        expect(app(AnalyticsScript::class)->configured())->toBeFalse();
    });
});
