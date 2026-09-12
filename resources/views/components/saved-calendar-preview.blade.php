@props(['id', 'occurrences'])
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" class="m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto border-2 border-accent bg-canvas p-5 text-ink backdrop:bg-black/70">
    <form method="dialog" class="flex justify-end">
        <button class="min-h-12 min-w-12 px-3 font-bold" autofocus>{{ __('common.actions.close') }}</button>
    </form>
    <h2 id="{{ $id }}-title" class="text-lg font-bold">{{ __('account.saved.calendar_view') }}</h2>
    @foreach ($occurrences as $occurrence)
        <article class="mt-4 border-t border-line pt-4">
            <x-event-artwork :event="$occurrence->event" class="mb-3 !w-24" />
            <h3 class="text-card">{{ $occurrence->event->title }}</h3>
            <p class="mt-2 text-sm text-ink-muted">{{ $occurrence->starts_at->timezone($occurrence->event->city->timezone)->locale('it')->translatedFormat('l j F Y') }} · {{ $occurrence->is_all_day ? __('events.badge.all_day') : app(\App\Support\DateFormatter::class)->time($occurrence->starts_at) }}</p>
            @if ($occurrence->effectiveVenue())
                <p class="mt-2">{{ $occurrence->effectiveVenue()->name }}</p>
            @endif
            <div class="mt-2"><x-price-tag :event="$occurrence->event" /></div>
            <x-button :href="\App\Support\EventUrl::occurrence($occurrence)" class="mt-4">{{ __('account.saved.open_event') }}</x-button>
        </article>
    @endforeach
</dialog>
