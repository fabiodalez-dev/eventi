{{--
    Le schede delle pagine di servizio (community, passaggi): un solo disegno,
    così passare da «Verifiche e requisiti» ad «Avvisi» non cambia colori,
    spessori e posizione della barra. Ogni voce: href, label, active, e
    facoltativamente `count`, la chiave del contatore che lo script aggiorna.

    Il riepilogo degli errori del modulo lo stampa `<x-service-page>` sotto il
    titolo, non le schede. `scroll` tiene le schede
    su una riga sola, scorrevole sul telefono, invece di mandarle a capo.
    `flush` toglie il margine sotto: dentro `<x-service-page>` le distanze le
    decide la pagina.
--}}
@props(['label', 'items', 'scroll' => false, 'flush' => false])
<nav {{ $attributes->class([
    'flex gap-x-6 gap-y-0 border-b border-line text-sm font-semibold',
    'flex-wrap' => ! $scroll,
    'svc-tabs--scroll flex-nowrap' => $scroll,
    'mb-8 sm:mb-10' => ! $flush,
]) }} aria-label="{{ $label }}">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}" @class([
            'relative -mb-px inline-flex min-h-13 items-center gap-2 border-b-2 py-3 transition-colors hover:text-brand',
            'shrink-0 whitespace-nowrap' => $scroll,
            'border-brand text-brand' => $item['active'],
            'border-transparent text-ink-muted' => ! $item['active'],
        ]) @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}@isset($item['count'])<span data-community-count="{{ $item['count'] }}" hidden class="min-w-5 rounded-full bg-accent px-1 text-center text-xs font-bold text-on-accent"></span>@endisset</a>
    @endforeach
</nav>
