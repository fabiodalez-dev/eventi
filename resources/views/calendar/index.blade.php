{{--
    Il calendario mensile (§11.8).

    Griglia scritta a mano (`<x-month-calendar>`): quarantadue caselle non
    valgono i duecentocinquanta chilobyte di una libreria di calendari, che
    porterebbe con sé un proprio fuso orario e una propria idea di "oggi" —
    cioè la seconda, dopo quella di `EventOccurrenceQuery`.

    Lo script (`resources/js/calendar.js`, senza Alpine) fa tre cose, nessuna
    necessaria: rivela i titoli sul telefono, cambia mese con le frecce della
    tastiera e apre l'anteprima di un giorno in una finestra. Senza JavaScript
    restano i conteggi, i collegamenti ai giorni e quelli ai mesi vicini.
--}}
<x-layouts.app :meta="$meta">
    <x-slot:head>
        @vite('resources/js/calendar.js')
    </x-slot:head>

    <header class="flex flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ __('calendar.title') }}</h1>

        @if ($total > 0)
            <p class="text-sm text-ink-muted">
                {{ trans_choice('calendar.total', $total, ['count' => $total, 'month' => $label]) }}
            </p>
        @endif
    </header>

    <x-month-calendar
        class="mt-6"
        :cells="$cells"
        :label="$label"
        :previous="$previous"
        :next="$next"
        :current="$month->format('Y-m') !== $today->format('Y-m') ? route('calendar.index') : null"
        :preview="true"
    />

    <dialog id="calendar-day-preview" data-light-dismiss aria-labelledby="calendar-day-title" class="m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-4xl overflow-y-auto border-2 border-accent bg-canvas p-5 text-ink backdrop:bg-black/70" data-loading="{{ __('tonight.count_loading') }}" data-error="{{ __('tonight.count_error') }}">
        <form method="dialog" class="flex justify-end"><x-button type="submit" variant="secondary" size="sm" autofocus>{{ __('common.actions.close') }}</x-button></form>
        <h2 id="calendar-day-title" class="mb-4 text-lg font-bold"></h2>
        <div data-day-results aria-live="polite"></div>
    </dialog>

    @if ($total === 0)
        <div class="mt-8">
            {{-- Non è un contenitore vuoto: è la risposta a una domanda precisa
                 ("che c'è a settembre?"), e porta dove qualcosa c'è (§8.6). --}}
            <x-empty-state
                :title="__('calendar.empty.title')"
                :description="__('calendar.empty.body')"
            >
                @if ($next !== null)
                    <x-button :href="$next">{{ __('calendar.next') }}</x-button>
                @endif

                <x-button :href="route('events.index')" variant="secondary">{{ __('events.redirects.to_all') }}</x-button>
            </x-empty-state>
        </div>
    @endif
</x-layouts.app>
