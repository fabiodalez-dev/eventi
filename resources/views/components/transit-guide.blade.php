{{--
    «Come arrivare» a un locale: una riga per mezzo, con l'etichetta del
    mezzo a sinistra e l'indicazione a destra (`venues.transit`).
--}}
@props([
    'transit',
    'headingId' => 'come-arrivare',
    'level' => 'h2',
])

@php
    $lines = $transit instanceof \App\DTOs\TransitGuide ? $transit : \App\DTOs\TransitGuide::fromMixed($transit);
@endphp

@if ($lines->isNotEmpty())
    <section aria-labelledby="{{ $headingId }}" {{ $attributes->class(['flex flex-col gap-3']) }}>
        <{{ $level }} id="{{ $headingId }}" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.transit') }}</{{ $level }}>

        <ul class="flex flex-col divide-y divide-line">
            @foreach ($lines as $line)
                <li class="flex items-start gap-3 py-2.5">
                    <x-badge tone="brand" size="sm" class="mt-0.5 shrink-0">{{ $line->mode->label() }}</x-badge>
                    <span class="text-sm text-ink-muted">{{ $line->text }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
