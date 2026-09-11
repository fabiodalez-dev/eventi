@php($event = $getRecord())
<div class="event-ticketing-intro">
    <p>Il ticketing inCittà gestisce biglietti gratuiti o con pagamento all’ingresso, senza incassi online. Si attiva per ogni data: apri la gestione biglietti per impostare prenotazioni, capienza e disponibilità. Il prezzo si indica nella scheda evento.</p>
    @if ($event instanceof \App\Models\Event)
        @if (! $event->venue?->ticketing_enabled)
            <p>Il locale deve essere abilitato al ticketing dalla redazione.</p>
            @if (auth()->user()?->hasAnyRole(['admin', 'super_admin']) && $event->venue)
                <x-filament::button color="gray" tag="a" :href="\App\Filament\Admin\Resources\Venues\VenueResource::getUrl('edit', ['record' => $event->venue, 'tab' => 'ticketing::data::tab'])">Apri il locale per abilitare il ticketing</x-filament::button>
            @endif
        @endif
        <div class="event-ticketing-links">
            <x-filament::button color="gray" tag="a" :href="route('ticketing.manage.index')">Biglietti e partecipanti</x-filament::button>
            @forelse ($event->occurrences()->orderBy('starts_at')->get() as $date)
                @can('manage', [\App\Models\Booking::class, $date])
                    <x-filament::button color="gray" tag="a" :href="route('ticketing.manage.show', $date)">
                        {{ $date->starts_at->timezone($event->city?->timezone ?? 'Europe/Rome')->format('d/m/Y H:i') }} — Gestisci biglietti
                    </x-filament::button>
                @endcan
            @empty
                <p>Salva l’evento e aggiungi almeno una data per configurare il ticketing.</p>
            @endforelse
        </div>
    @else
        <p>Salva l’evento e aggiungi una data per configurare il ticketing.</p>
    @endif
</div>
