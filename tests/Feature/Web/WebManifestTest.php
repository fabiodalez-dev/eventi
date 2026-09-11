<?php

declare(strict_types=1);

/**
 * Il manifesto dell'applicazione web (§15.6).
 */
beforeEach(function (): void {
    $this->city = testCity();
});

it('descrive l’applicazione con i campi che un telefono pretende', function (): void {
    $response = $this->get('/site.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/manifest+json');

    $manifesto = $response->json();

    /* I campi senza i quali nessun browser offre l'installazione. */
    expect($manifesto)->toHaveKeys(['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'icons'])
        ->and($manifesto['display'])->toBe('standalone')
        ->and($manifesto['name'])->toContain(config('app.name'))
        ->and($manifesto['name'])->toContain($this->city->name)
        /* Sotto l'icona ci va la città: con più sottodomini è l'unica cosa
           che distingue due installazioni identiche. */
        ->and($manifesto['short_name'])->toBe($this->city->name);

    /* Le due misure che Chrome richiede, più la variante mascherabile che
       Android ritaglia nella forma del sistema. */
    $misure = collect($manifesto['icons'])->pluck('sizes')->all();
    expect($misure)->toContain('192x192')->toContain('512x512')
        ->and(collect($manifesto['icons'])->pluck('purpose')->all())->toContain('maskable');

    foreach ($manifesto['icons'] as $icona) {
        expect(public_path(ltrim($icona['src'], '/')))->toBeFile();
    }
});

/*
 * Il nome del prodotto arriva da `config('app.name')` e non è scritto a mano
 * da nessuna parte (D10). Un manifesto statico in `public/` lo congelerebbe, e
 * chi rinomina il prodotto se lo ritroverebbe nella schermata iniziale dei
 * telefoni di chi l'aveva installato.
 */
it('prende il nome dalla configurazione e non da una stringa scritta a mano', function (): void {
    config()->set('app.name', 'Provaccia');

    $this->get('/site.webmanifest')->assertOk()->assertJsonPath('name', 'Provaccia '.$this->city->name);
});

/*
 * `id`, `start_url` e `scope` sono **relativi**, quindi si risolvono contro
 * l'origine da cui il manifesto è stato scaricato. È la riga che rende
 * `padova.incitta.it` e `bologna.incitta.it` due applicazioni installabili
 * fianco a fianco invece che una che scalza l'altra.
 */
it('resta relativo, così ogni sottodominio è un’applicazione a sé', function (): void {
    $manifesto = $this->get('/site.webmanifest')->assertOk()->json();

    expect($manifesto['id'])->toBe('/')
        ->and($manifesto['start_url'])->toBe('/')
        ->and($manifesto['scope'])->toBe('/');

    foreach ($manifesto['shortcuts'] as $scorciatoia) {
        expect($scorciatoia['url'])->toStartWith('/')->not->toContain('://');
    }
});

it('è dichiarato nell’intestazione di ogni pagina', function (): void {
    $this->get('/')->assertOk()->assertSee('rel="manifest"', false);
});

it('offre come scorciatoie solo rotte che esistono', function (): void {
    $scorciatoie = $this->get('/site.webmanifest')->assertOk()->json('shortcuts');

    expect($scorciatoie)->not->toBeEmpty();

    foreach ($scorciatoie as $scorciatoia) {
        $this->get($scorciatoia['url'])->assertOk();
    }
});
