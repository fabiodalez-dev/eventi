<x-filament-panels::page>
    {{-- Il calendario è un widget d'intestazione, quindi Filament lo disegna
         da sé: qui basta la pagina. La legenda dei colori sta sotto perché
         serve una volta, non a ogni sguardo. --}}
    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
        @foreach ([
            '#7a9900' => __('enums.event_status.published'),
            '#b45309' => __('enums.event_status.pending'),
            '#6b6b66' => __('enums.event_status.draft'),
            '#b91c1c' => __('enums.event_status.rejected'),
        ] as $colore => $etichetta)
            <span class="flex items-center gap-2">
                <span class="inline-block h-3 w-3" style="background: {{ $colore }}"></span>
                {{ $etichetta }}
            </span>
        @endforeach
    </div>
</x-filament-panels::page>
