<x-layouts.app :meta="$meta"><div class="flex flex-col gap-6">
    <h1 class="text-hero">{{ __('ticketing.manage') }}</h1>
    <form method="GET" data-ticket-search class="grid gap-3 sm:grid-cols-[1fr_auto_auto] items-end">
        <x-field name="q" label="Cerca evento o locale" :value="request('q')" autocomplete="off" />
        <label class="text-sm font-semibold">Periodo
            <select name="period" class="block min-h-12 w-full border border-line bg-canvas px-3">
                <option value="upcoming" @selected($period === 'upcoming')>Prossimi e in corso</option>
                <option value="past" @selected($period === 'past')>Passati</option>
            </select>
        </label>
        <x-button type="submit">Cerca</x-button>
    </form>
    <p data-search-status role="status" class="text-sm text-ink-muted"></p>
    <div data-ticket-results class="flex flex-col gap-4">
    @forelse ($dates as $date)
        <a href="{{ route('ticketing.manage.show', $date) }}" class="flex items-center gap-4 border-t-2 border-line py-4 hover:text-brand"><x-event-artwork :event="$date->event" class="!w-20 shrink-0" /><span class="min-w-0"><strong class="text-xl">{{ $date->event->title }}</strong><br>{{ $date->starts_at->timezone($date->event->city->timezone)->format('d/m/Y H:i') }} · {{ $date->effectiveVenue()?->name }}<br><span class="text-sm">{{ $date->booking_enabled ? __('ticketing.enabled') : __('ticketing.settings') }}</span></span></a>
    @empty
        <p>Non ci sono eventi {{ $period === 'past' ? 'passati' : 'prossimi o in corso' }}{{ request('q') ? ' corrispondenti alla ricerca' : '' }}.</p>
    @endforelse
    {{ $dates->links() }}
    </div>
</div></x-layouts.app>
