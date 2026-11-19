<?php

declare(strict_types=1);

use App\Queries\EventOccurrenceQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;

/**
 * La vista degli elenchi sul telefono: card a tutta riga, griglia, elenco
 * compatto. La scelta resta nel browser, quindi l'HTML è lo stesso per tutti.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return list<DOMElement> */
function cardLayoutOptions(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $nodes = (new DOMXPath($dom))->query('//*[@data-card-layout-toggle]//button[@data-card-layout-option]');

    return iterator_to_array($nodes);
}

it('offre tre viste in un gruppo radio accessibile', function (): void {
    $html = Blade::render('<x-card-layout-toggle />');
    $options = cardLayoutOptions($html);

    expect($html)->toContain('role="radiogroup"', 'aria-labelledby=')
        ->and(array_map(fn (DOMElement $option): string => $option->getAttribute('data-card-layout-option'), $options))->toBe(['full', 'grid', 'list']);

    foreach ($options as $option) {
        expect($option->getAttribute('role'))->toBe('radio')
            ->and($option->getAttribute('aria-label'))->not->toBe('')
            ->and(trim($option->textContent))->toBe('');
    }
});

it('esce senza una preferenza: la scelta la applica il browser', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00');

    $html = (string) $this->withCookie('incitta:card-layout', 'grid')->get('/eventi')->assertOk()->getContent();
    $checked = array_values(array_filter(cardLayoutOptions($html), fn (DOMElement $option): bool => $option->getAttribute('aria-checked') === 'true'));

    expect($html)->toContain('data-card-layout="full"')
        ->and($checked)->toHaveCount(1)
        ->and($checked[0]->getAttribute('data-card-layout-option'))->toBe('full');
});

it('stampa il selettore una volta sola per pagina', function (string $path): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00:00');
    $category = testCategory();
    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00');
    occurrenceAtLocal($city, $category, '2026-09-13 21:00:00');

    $html = (string) $this->get($path)->assertOk()->getContent();

    expect(substr_count($html, 'data-card-layout-toggle'))->toBe(1);
})->with(['/', '/eventi']);

it('non stampa il selettore nelle griglie degli eventi correlati', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00');
    $occurrences = EventOccurrenceQuery::for($city)->upcoming()->get()->load('event.venue', 'event.category', 'event.media');

    $html = Blade::render('<x-event-grid :occurrences="$occurrences" :balanced="true" />', ['occurrences' => $occurrences]);

    expect($html)->not->toContain('data-card-layout-toggle');
});

it('su /eventi tiene il selettore dentro il margine laterale della pagina, fuori dalla griglia a tutta larghezza', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00');

    $html = (string) $this->get('/eventi')->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $toggle = (new DOMXPath($dom))->query('//*[@data-card-layout-toggle]')->item(0);

    expect($toggle)->not->toBeNull()
        ->and($toggle->parentNode->getAttribute('class'))->toContain('px-gutter');
});
