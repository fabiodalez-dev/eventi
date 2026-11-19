{{--
    Una fila di numeri: posti, prenotazioni, visite. Ogni voce: `label`,
    `value` e facoltativamente `hint`. È un elenco di definizioni, così uno
    screen reader legge l'etichetta insieme al suo numero; le voci vanno a
    capo quando non ci stanno, invece di stringersi in colonne illeggibili.
--}}
@props(['label' => null, 'items' => []])
<dl data-stat-list @if (filled($label)) aria-label="{{ $label }}" @endif {{ $attributes->class('m-0 flex flex-wrap gap-x-8 gap-y-4') }}>
    @foreach ($items as $item)
        <div class="flex min-w-[6.5rem] flex-col gap-1">
            <dt class="text-xs font-semibold tracking-[0.06em] text-ink-muted uppercase">{{ $item['label'] }}</dt>
            <dd class="m-0 font-display text-[1.75rem] leading-none font-extrabold tracking-[-0.02em] text-ink tabular-nums">{{ $item['value'] }}</dd>
            @if (filled($item['hint'] ?? null))
                <dd class="m-0 text-xs text-ink-subtle">{{ $item['hint'] }}</dd>
            @endif
        </div>
    @endforeach
</dl>
