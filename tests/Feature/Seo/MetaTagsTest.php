<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Services\Media\OpenGraphImage;
use App\Support\EventUrl;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

/**
 * Canonical, Open Graph, X/Twitter, `hreflang` (§12.2) e le regole di §11.11
 * che eliminano il salto di layout.
 */
beforeEach(function (): void {
    Storage::fake('public');

    $this->city = testCity();
    $this->category = testCategory();
});

it('dichiara canonical, Open Graph e X su una scheda evento', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30', event: [
        'title' => 'Concerto al circolo',
        'short_description' => 'Una serata di musica dal vivo.',
    ])->event;

    freezeLocal($this->city, '2026-09-01 12:00');

    $html = $this->get(route('events.show', $event))->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="canonical" href="'.EventUrl::occurrence($event->occurrences()->first()).'">')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<meta property="og:title" content="Concerto al circolo a '.$this->city->name.'">')
        ->toContain('<meta property="og:url" content="'.EventUrl::occurrence($event->occurrences()->first()).'">')
        ->toContain('<meta name="twitter:title" content="Concerto al circolo a '.$this->city->name.'">')
        ->toContain('<meta property="og:description" content="Una serata di musica dal vivo.">')
        ->toContain('<meta name="twitter:description" content="Una serata di musica dal vivo.">');
});

it('mette in og:image l\'anteprima composta, con le sue misure', function (): void {
    config()->set('media.open_graph.enabled', true);

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30')->event;

    app(OpenGraphImage::class)->render($event);

    freezeLocal($this->city, '2026-09-01 12:00');

    $html = $this->get(route('events.show', $event))->assertOk()->getContent();

    expect($html)
        ->toContain('og/eventi/'.$event->getKey().'.jpg')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
});

it('dichiara hreflang x-default su ogni pagina', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    freezeLocal($this->city, '2026-09-01 12:00');

    $html = $this->get('/eventi')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="alternate" hreflang="x-default"')
        ->toContain('hreflang="it"');
});

it('tiene fuori dall\'indice le combinazioni di filtri', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    freezeLocal($this->city, '2026-09-01 12:00');

    $this->get('/cerca?q=concerto')->assertOk()->assertSee('content="noindex, follow"', escape: false);
});

describe('immagini senza salto di layout (§11.11)', function (): void {
    it('dichiara larghezza e altezza su ogni immagine della pagina', function (): void {
        $event = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30')->event;
        $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

        // "Stasera" è la prima sezione della pagina iniziale: è lì che la
        // locandina deve comparire.
        freezeLocal($this->city, '2026-09-12 18:00');

        /*
         * `/eventi` non è più in questo elenco: dal disegno adottato (D46) le
         * card di un elenco sono tipografiche e non portano locandina — è ciò
         * che permette di affiancarle a due pixel di distanza. Le fotografie
         * restano dove pesano davvero, ed è lì che questa regola va verificata:
         * il riquadro in evidenza della pagina iniziale e la scheda
         * dell'evento, che sono le due che questo test popola.
         */
        foreach (['/', route('events.show', $event->refresh())] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('#<img\b[^>]*>#s', $html, $immagini);

            expect($immagini[0])->not->toBeEmpty("nessuna immagine su {$url}");

            foreach ($immagini[0] as $tag) {
                expect($tag)->toContain('width=')->toContain('height=');
            }
        }
    });

    it('offre AVIF e WebP e rinvia le locandine sotto la piega', function (): void {
        /*
         * L'elenco dei locali: cinque schede con fotografia, di cui solo le
         * prime stanno sopra la piega. Prima questo test guardava `/eventi`,
         * ma da D46 le card di un elenco di eventi non hanno locandina — e un
         * test sul rinvio delle immagini ha bisogno di una pagina che di
         * immagini ne abbia parecchie, sopra e sotto la piega.
         */
        foreach (range(1, 5) as $indice) {
            $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
            $venue->addMedia(ImageFixtures::upload('locale.jpg', ImageFixtures::jpeg()))->toMediaCollection('cover');
        }

        freezeLocal($this->city, '2026-09-01 12:00');

        $html = $this->get('/locali')->assertOk()->getContent();

        /*
         * Il WebP c'è sempre; l'AVIF solo dove questa macchina lo produce
         * davvero — una fonte AVIF viene dichiarata solo se dietro c'è un AVIF
         * vero, altrimenti il browser sceglierebbe un file col tipo sbagliato.
         */
        expect($html)
            ->toContain('<source type="image/webp"')
            ->toContain('loading="lazy"')
            ->toContain('data:image/png;base64,');
    });

    it('annuncia la locandina piu grande sopra la piega', function (): void {
        $event = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30')->event;
        $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg(900, 1200)))->toMediaCollection('poster');

        freezeLocal($this->city, '2026-09-01 12:00');

        $html = $this->get(route('events.show', $event->refresh()))->assertOk()->getContent();

        /*
         * Il preload dichiara il formato migliore DISPONIBILE, non per forza
         * l'AVIF: dove quella variante non esiste si annuncia il WebP col suo
         * `imagesrcset`. Ciò che non deve mai mancare è il preload stesso —
         * senza, l'immagine più grande sopra la piega viene scoperta solo
         * quando il browser incontra l'HTML che la contiene (§11.11).
         */
        expect($html)
            ->toContain('rel="preload"')
            ->toContain('fetchpriority="high"')
            ->toMatch('/rel="preload"[^>]*type="image\/(avif|webp)"/s');
    });
});
