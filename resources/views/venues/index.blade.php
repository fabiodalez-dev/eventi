{{-- L'elenco dei locali (§11.9). --}}
<x-layouts.app :meta="$meta">
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
    </x-slot:head>

    <header class="flex flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>

        @if ($meta->description)
            <p class="max-w-prose text-sm text-ink-muted">{{ $meta->description }}</p>
        @endif
    </header>

    <form method="GET" action="{{ route('venues.index') }}" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-field
            name="q"
            :label="__('venues.filters.search')"
            :value="$term"
            :placeholder="__('venues.filters.search_placeholder')"
        />

        <x-field
            name="type"
            :label="__('venues.filters.type')"
            :options="\App\Enums\VenueType::options()"
            :placeholder-option="__('venues.filters.any_type')"
            :value="$type?->value"
        />

        <x-field
            name="municipality"
            :label="__('filters.place.municipality')"
            :options="$municipalities->mapWithKeys(fn (string $name): array => [$name => $name])->all()"
            :placeholder-option="__('filters.place.any')"
            :value="$municipality"
        />

        <div class="flex items-end gap-3">
            <button
                type="submit"
                class="bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
            >
                {{ __('filters.apply') }}
            </button>

            @if ($term !== '' || $type !== null || $municipality !== null)
                <a href="{{ route('venues.index') }}" class="text-sm font-semibold text-ink-muted underline hover:text-ink">
                    {{ __('filters.reset') }}
                </a>
            @endif
        </div>
    </form>

    @if ($venues->total() > 0)
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($venues as $venue)
                <x-venue-card :venue="$venue" :upcoming="$upcomingCounts[$venue->getKey()] ?? 0" />
            @endforeach
        </div>

        <x-pagination :paginator="$venues" :summary="true" />
    @else
        <div class="mt-8">
            <x-empty-state :title="__('venues.empty.list_title')" :description="__('venues.empty.list_body')">
                <a
                    href="{{ route('venues.index') }}"
                    class="bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
                >
                    {{ __('filters.reset') }}
                </a>
            </x-empty-state>
        </div>
    @endif
</x-layouts.app>
