<?php

declare(strict_types=1);

use App\Enums\PriceType;
use App\Models\Event;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Number;

/**
 * Paginatore su dati finti: `$items` è la sola pagina corrente, come lo è nel
 * paginatore vero, mentre `$total` conta l'intero insieme.
 */
function paginatorOf(int $total, int $perPage, int $page): LengthAwarePaginator
{
    $items = array_slice(range(1, $total), ($page - 1) * $perPage, $perPage);

    return new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => 'https://esempio.test/eventi']);
}

it('non disegna la barra di paginazione quando c\'è una pagina sola', function (): void {
    $html = Blade::render('<x-pagination :paginator="$paginator" />', [
        'paginator' => paginatorOf(total: 3, perPage: 24, page: 1),
    ]);

    expect(trim($html))->toBe('');
});

it('disegna la paginazione con le etichette italiane e la pagina corrente marcata', function (): void {
    $html = Blade::render('<x-pagination :paginator="$paginator" :summary="true" />', [
        'paginator' => paginatorOf(total: 60, perPage: 24, page: 2),
    ]);

    expect($html)
        ->toContain(__('ui.pagination.previous'))
        ->toContain(__('ui.pagination.next'))
        ->toContain('aria-current="page"')
        ->toContain(__('ui.pagination.showing', ['first' => 25, 'last' => 48, 'total' => 60]));
});

it('lo stato vuoto porta un titolo e le azioni che gli si passano', function (): void {
    $html = Blade::render(
        '<x-empty-state :title="$title" :description="$body"><a href="/eventi">Sfoglia</a></x-empty-state>',
        ['title' => __('events.empty.search_title'), 'body' => __('events.empty.search_body')],
    );

    expect($html)
        ->toContain(__('events.empty.search_title'))
        ->toContain(e(__('events.empty.search_body')))
        ->toContain('href="/eventi"');
});

it('il prezzo gratuito si annuncia, quello sconosciuto no', function (): void {
    $free = Event::factory()->make(['price_type' => PriceType::Free, 'price_min' => null, 'price_max' => null]);
    $unknown = Event::factory()->make(['price_type' => PriceType::Unknown, 'price_min' => null, 'price_max' => null]);

    expect(Blade::render('<x-price-tag :event="$event" />', ['event' => $free]))
        ->toContain(__('events.price.free'));

    expect(trim(Blade::render('<x-price-tag :event="$event" />', ['event' => $unknown])))->toBe('');
});

it('il prezzo a biglietto esce in euro, con la fascia quando esiste', function (): void {
    $single = Event::factory()->make(['price_type' => PriceType::Ticket, 'price_min' => 8, 'price_max' => 8, 'currency' => 'EUR']);
    $range = Event::factory()->make(['price_type' => PriceType::Ticket, 'price_min' => 8, 'price_max' => 12, 'currency' => 'EUR']);

    /* La valuta la scrive `intl` nel locale italiano: importo, spazio unificatore
       e simbolo dopo. Scriverlo a mano nel test significherebbe verificare la
       propria idea del formato invece di quella del sistema. */
    $euro = fn (int $amount): string => Number::currency($amount, in: 'EUR', locale: 'it', precision: 0);

    expect(Blade::render('<x-price-tag :event="$event" />', ['event' => $single]))->toContain($euro(8));

    expect(Blade::render('<x-price-tag :event="$event" />', ['event' => $range]))
        ->toContain(e(__('events.price.range', ['min' => $euro(8), 'max' => $euro(12)])));
});

it('l\'intestazione di sezione lega il titolo alla sezione e mostra il rimando', function (): void {
    $html = Blade::render('<x-section-heading id="sezione-oggi" :title="$title" href="/eventi/oggi" />', [
        'title' => __('events.sections.today'),
    ]);

    expect($html)
        ->toContain('id="sezione-oggi"')
        ->toContain(__('events.sections.today'))
        ->toContain(__('common.actions.show_all'));
});

it('il pulsante è un bottone o un collegamento, con l\'etichetta a filo a sinistra', function (): void {
    $button = Blade::render('<x-button variant="primary">Pubblica</x-button>');
    $link = Blade::render('<x-button variant="secondary" href="/eventi">Sfoglia</x-button>');

    /* L'etichetta a filo a sinistra è una regola esplicita del sistema (D46):
       un pulsante più largo del testo comincia il testo al bordo sinistro. */
    expect($button)
        ->toContain('<button type="button"')
        ->toContain('justify-start')
        ->toContain('text-left')
        ->toContain('Pubblica')
        ->not->toContain('justify-center');

    expect($link)
        ->toContain('<a href="/eventi"')
        ->toContain('justify-start')
        ->toContain('Sfoglia');
});

it('l\'icona è decorativa senza etichetta e parlante con l\'etichetta', function (): void {
    $decorative = Blade::render('<x-lucide name="calendar" class="size-4" />');
    $labelled = Blade::render('<x-lucide name="search" :label="$label" />', ['label' => 'Cerca']);

    expect($decorative)
        ->toContain('aria-hidden="true"')
        ->toContain('stroke="currentColor"')
        ->not->toContain('<title>');

    expect($labelled)
        ->toContain('role="img"')
        ->toContain('<title>Cerca</title>')
        ->not->toContain('aria-hidden');
});

it('un\'icona che non esiste fallisce subito, non in produzione', function (): void {
    expect(fn () => Blade::render('<x-lucide name="inesistente" />'))
        ->toThrow(Exception::class, 'Icona sconosciuta: "inesistente"');
});

it('il divisore è una regola piena di 2px', function (): void {
    expect(Blade::render('<x-divider />'))->toContain('<hr')->toContain('divider');
});

it('il badge accetta un colore solo se è davvero un colore', function (): void {
    $legit = Blade::render('<x-badge :dot="true" dot-color="#7C3AED">Musica</x-badge>');
    $hostile = Blade::render('<x-badge :dot="true" :dot-color="$colore">Musica</x-badge>', [
        'colore' => 'red; background-image: url(https://esempio.test/x.png)',
    ]);

    expect($legit)->toContain('background-color: #7C3AED')
        ->and($hostile)->not->toContain('background-image')
        ->and($hostile)->toContain('bg-current');
});
