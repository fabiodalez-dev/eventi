{{--
    L'accessibilità dichiarata da un locale (`venues.accessibility`).

    Si elencano **solo le voci presenti**. Una voce dichiarata assente non si
    stampa come divieto e una non dichiarata non si stampa affatto: «non lo
    sappiamo» non è «no», e mostrarli allo stesso modo manda qualcuno a
    sbattere contro uno scalino.
--}}
@props([
    'accessibility',
    'headingId' => 'accessibilita-locale',
    'level' => 'h2',
])

@php
    $profile = $accessibility instanceof \App\DTOs\AccessibilityProfile
        ? $accessibility
        : \App\DTOs\AccessibilityProfile::fromMixed($accessibility);

    $features = $profile->available();
@endphp

@if ($features !== [])
    <section aria-labelledby="{{ $headingId }}" {{ $attributes->class(['flex flex-col gap-3']) }}>
        <{{ $level }} id="{{ $headingId }}" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.accessibility') }}</{{ $level }}>

        <ul class="flex flex-col gap-2">
            @foreach ($features as $feature)
                <li class="flex items-start gap-2 text-sm text-ink-muted">
                    <svg class="mt-0.5 size-4 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="square" aria-hidden="true">
                        <path d="M4 12.5l5 5L20 6.5"></path>
                    </svg>
                    {{ $feature->label() }}
                </li>
            @endforeach
        </ul>
    </section>
@endif
