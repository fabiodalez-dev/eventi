{{--
    Una scheda tecnica: coppie etichetta/valore (`events.facts`, `venues.info`).

    Niente righe se non c'è niente: la sezione non si disegna affatto (§8.6).
--}}
@props([
    'facts',
    'heading',
    'headingId',
    'level' => 'h2',
])

@php
    /* `collect()` su un `FactList` chiamerebbe `toArray()` e restituirebbe
       array grezzi: la lista si usa com'è, che è il motivo per cui è un tipo
       e non un array. Chi passa altro — un array da un import, per esempio —
       viene ricondotto alla stessa forma. */
    $rows = $facts instanceof \App\DTOs\FactList ? $facts : \App\DTOs\FactList::fromMixed($facts);
@endphp

@if ($rows->isNotEmpty())
    <section aria-labelledby="{{ $headingId }}" {{ $attributes->class(['flex flex-col gap-3']) }}>
        <{{ $level }} id="{{ $headingId }}" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ $heading }}</{{ $level }}>

        <dl class="flex flex-col divide-y divide-line">
            @foreach ($rows as $fact)
                <div class="flex flex-wrap items-baseline justify-between gap-3 py-2">
                    <dt class="text-eyebrow font-display font-extrabold uppercase tracking-[0.12em] text-ink-subtle">{{ $fact->label }}</dt>
                    <dd class="text-right font-semibold text-ink">{{ $fact->value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif
