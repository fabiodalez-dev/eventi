@props([
    'venue',
    'href' => null,
    /* Numero di date già in programma, se chi disegna la lista lo ha contato:
       la card non interroga il motore temporale per conto proprio. */
    'upcoming' => null,
    'level' => 'h3',
])

@php
    $url = $href ?? (\Illuminate\Support\Facades\Route::has('venues.show')
        ? route('venues.show', $venue)
        : null);

    $cover = \App\Support\Media\ImageSet::forCollection($venue, 'cover');
    $logo = \App\Support\Media\ImageSet::forCollection($venue, 'logo');

    $initials = \Illuminate\Support\Str::of($venue->name)
        ->squish()
        ->explode(' ')
        ->take(2)
        ->map(fn (string $word): string => \Illuminate\Support\Str::upper(mb_substr($word, 0, 1)))
        ->implode('');

    $distance = $venue->getAttribute(\App\Queries\EventOccurrenceQuery::DISTANCE_ALIAS);

    $distanceLabel = $distance === null
        ? null
        : ((float) $distance >= 1000
            ? __('common.units.km', ['value' => \Illuminate\Support\Number::format((float) $distance / 1000, maxPrecision: 1, locale: app()->getLocale())])
            : __('common.units.meters', ['value' => \Illuminate\Support\Number::format(round((float) $distance), locale: app()->getLocale())]));
@endphp

<article {{ $attributes->class([
    'group relative flex h-full flex-col overflow-hidden bg-canvas border-2 border-line transition duration-300 ease-out-soft',
    'hover:-translate-y-0.5 hover:border-accent' => $url !== null,
]) }}>
    <div class="relative aspect-[16/9] w-full overflow-hidden poster-placeholder">
        @if ($cover !== null)
            <x-media-image
                :set="$cover"
                :alt="__('venues.card.cover_alt', ['venue' => $venue->name])"
                width="1200"
                height="675"
                sizes="(min-width: 1024px) 380px, 90vw"
                class="size-full object-cover transition duration-500 ease-out-soft group-hover:scale-[1.03]"
            />
        @endif

        @if ($venue->is_verified)
            <x-badge tone="brand" size="sm" class="absolute top-2 right-2">
                {{ __('venues.badge.verified') }}
            </x-badge>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-1.5 p-card">
        <div class="flex items-center gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center overflow-hidden bg-brand-soft text-sm font-bold text-on-brand-soft border-2 border-line">
                @if ($logo !== null)
                    <x-media-image
                        :set="$logo"
                        :alt="__('venues.card.logo_alt', ['venue' => $venue->name])"
                        width="80"
                        height="80"
                        sizes="40px"
                        class="size-full object-cover"
                    />
                @else
                    <span aria-hidden="true">{{ $initials }}</span>
                @endif
            </span>

            <div class="min-w-0">
                <{{ $level }} class="text-card text-ink truncate">
                    @if ($url !== null)
                        <a href="{{ $url }}" class="after:absolute after:inset-0 after:content-['']">{{ $venue->name }}</a>
                    @else
                        {{ $venue->name }}
                    @endif
                </{{ $level }}>

                <p class="truncate text-sm text-ink-subtle">
                    {{ $venue->type->label() }}
                    @if ($venue->municipality)
                        <span aria-hidden="true">{{ __('common.separator') }}</span> {{ $venue->municipality }}
                    @endif
                </p>
            </div>
        </div>

        @if ($venue->short_description)
            <p class="line-clamp-title text-sm text-ink-muted">{{ $venue->short_description }}</p>
        @endif

        <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
            @if ($upcoming !== null && $upcoming > 0)
                <x-badge tone="neutral" size="sm">{{ trans_choice('venues.card.upcoming', $upcoming) }}</x-badge>
            @endif

            @if ($venue->is_nonprofit)
                <x-badge tone="free" size="sm">{{ __('venues.badge.nonprofit') }}</x-badge>
            @endif

            @if ($venue->requires_membership)
                <x-badge tone="muted" size="sm">{{ __('venues.badge.membership') }}</x-badge>
            @endif

            @if ($distanceLabel !== null)
                <span class="text-xs text-ink-subtle">{{ __('venues.card.distance', ['distance' => $distanceLabel]) }}</span>
            @endif
        </div>
    </div>
</article>
