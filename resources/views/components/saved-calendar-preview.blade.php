{{--
    L'anteprima di un giorno del calendario dei salvati: lo stesso dialogo del
    pannello dei salvati (filo del tema, titolo e chiusura in testa), con le
    righe evento del sistema di servizio, locandina compresa.
--}}
@props(['id', 'occurrences'])
<dialog id="{{ $id }}" data-light-dismiss aria-labelledby="{{ $id }}-title" class="m-auto max-h-[85dvh] w-[min(34rem,calc(100vw-1.5rem))] overflow-y-auto border-2 border-line bg-canvas p-0 text-ink backdrop:bg-ink/70">
    <div class="flex items-center justify-between gap-3 border-b-2 border-line px-[clamp(1rem,3vw,1.5rem)] py-4">
        <h2 id="{{ $id }}-title" class="m-0 font-display text-[1.125rem] leading-none font-extrabold text-ink">{{ __('account.saved.calendar_view') }}</h2>
        <form method="dialog">
            <x-button type="submit" variant="secondary" size="sm" autofocus>{{ __('common.actions.close') }}</x-button>
        </form>
    </div>
    <div class="flex flex-col px-[clamp(1rem,3vw,1.5rem)] py-2">
        @foreach ($occurrences as $occurrence)
            <div @class(['py-3', 'border-t border-line' => ! $loop->first])>
                <x-service-event-row :occurrence="$occurrence">
                    <x-price-tag :event="$occurrence->event" />
                    <x-slot:actions>
                        <x-button size="sm" :href="\App\Support\EventUrl::occurrence($occurrence)">{{ __('account.saved.open_event') }}</x-button>
                    </x-slot:actions>
                </x-service-event-row>
            </div>
        @endforeach
    </div>
</dialog>
