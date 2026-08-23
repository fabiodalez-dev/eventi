<?php

declare(strict_types=1);

use App\Services\Media\OpenGraphImage;
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
        ->toContain('<link rel="canonical" href="'.route('events.show', $event).'">')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<meta property="og:title" content="Concerto al circolo">')
        ->toContain('<meta property="og:url" content="'.route('events.show', $event).'">')
        ->toContain('<meta name="twitter:title" content="Concerto al circolo">')
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

        foreach (['/', '/eventi', route('events.show', $event->refresh())] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('#<img\b[^>]*>#s', $html, $immagini);

            expect($immagini[0])->not->toBeEmpty("nessuna immagine su {$url}");

            foreach ($immagini[0] as $tag) {
                expect($tag)->toContain('width=')->toContain('height=');
            }
        }
    });

    it('offre AVIF e WebP e rinvia le locandine sotto la piega', function (): void {
        // Cinque date: le prime quattro stanno sopra la piega e si caricano
        // subito, la quinta deve aspettare di essere raggiunta.
        foreach (range(12, 16) as $giorno) {
            $event = occurrenceAtLocal($this->city, $this->category, "2026-09-{$giorno} 21:30")->event;
            $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');
        }

        freezeLocal($this->city, '2026-09-01 12:00');

        $html = $this->get('/eventi')->assertOk()->getContent();

        expect($html)
            ->toContain('<source type="image/avif"')
            ->toContain('<source type="image/webp"')
            ->toContain('loading="lazy"')
            ->toContain('data:image/png;base64,');
    });

    it('annuncia la locandina piu grande sopra la piega', function (): void {
        $event = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30')->event;
        $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg(900, 1200)))->toMediaCollection('poster');

        freezeLocal($this->city, '2026-09-01 12:00');

        $html = $this->get(route('events.show', $event->refresh()))->assertOk()->getContent();

        expect($html)
            ->toContain('rel="preload"')
            ->toContain('type="image/avif"')
            ->toContain('fetchpriority="high"');
    });
});
