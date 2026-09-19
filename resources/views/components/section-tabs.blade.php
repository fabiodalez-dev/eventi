{{--
    Le schede delle pagine di servizio (community, passaggi): un solo disegno,
    così passare da «Verifiche e requisiti» ad «Avvisi» non cambia colori,
    spessori e posizione della barra. Ogni voce: href, label, active, e
    facoltativamente `count`, la chiave del contatore che lo script aggiorna.
--}}
@props(['label', 'items'])
<nav {{ $attributes->class('mb-8 flex flex-wrap gap-x-6 gap-y-0 border-b border-line text-sm font-semibold sm:mb-10') }} aria-label="{{ $label }}">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}" @class([
            'relative -mb-px inline-flex min-h-13 items-center gap-2 border-b-2 py-3 transition-colors hover:text-brand',
            'border-brand text-brand' => $item['active'],
            'border-transparent text-ink-muted' => ! $item['active'],
        ]) @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}@isset($item['count'])<span data-community-count="{{ $item['count'] }}" hidden class="min-w-5 rounded-full bg-accent px-1 text-center text-xs font-bold text-on-accent"></span>@endisset</a>
    @endforeach
</nav>
@if($errors->any())<div role="alert" class="mb-6 border-l-4 border-brand bg-surface p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
