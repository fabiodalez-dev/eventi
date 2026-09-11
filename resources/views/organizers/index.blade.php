<x-layouts.app :meta="$meta">
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
    </x-slot:head>

    <h1 class="text-hero">{{ $meta->heading }}</h1>
    <p class="mt-4 max-w-prose text-ink-muted">{{ __('organizers.lead') }}</p>

    <form method="GET" role="search" class="my-6 flex flex-wrap items-end gap-3">
        <label class="flex-1">
            {{ __('organizers.search_label') }}
            <input class="mt-2 block min-h-12 w-full border-2 border-line bg-canvas p-3" name="q" type="search" value="{{ $term }}" maxlength="120">
        </label>
        <x-button type="submit">{{ __('common.actions.search') }}</x-button>
    </form>

    <div class="divide-y divide-line">
        @forelse($organizers as $organizer)
            <a class="block py-5 text-xl font-bold underline" href="{{ route('organizers.show', $organizer) }}">{{ $organizer->name }}</a>
        @empty
            <p>{{ __('organizers.empty') }}</p>
        @endforelse
    </div>

    <x-pagination :paginator="$organizers" :summary="true" />
</x-layouts.app>
