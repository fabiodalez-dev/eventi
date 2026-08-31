{{--
    L'anteprima obbligatoria di §14.2, che con la pubblicazione diretta (D32)
    è il contrappeso: si guarda **prima** di accendere la sorgente, e mostra
    esattamente le date che entrerebbero nel catalogo — filtro di esclusione
    già applicato, fusi già risolti.

    La stessa tabella serve la redazione (in una finestra) e il gestore del
    locale (nella pagina): due tabelle diverse divergerebbero, e chi collega un
    calendario vedrebbe qualcosa di diverso da chi lo sorveglia.
--}}
@php
    /** @var list<array{when: string, title: string, where: string|null, notes: list<string>, cancelled: bool}> $rows */
    /** @var string|null $error */
@endphp

@if ($error !== null)
    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $error }}</p>
@elseif ($rows === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('import.preview.empty') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-2 pe-4 font-medium">{{ __('import.preview.when') }}</th>
                    <th class="py-2 pe-4 font-medium">{{ __('import.preview.title') }}</th>
                    <th class="py-2 font-medium">{{ __('import.preview.where') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap py-2 pe-4 align-top tabular-nums">{{ $row['when'] }}</td>
                        <td class="py-2 pe-4 align-top">
                            {{ $row['title'] }}

                            @foreach ($row['notes'] as $note)
                                <span class="text-gray-500 dark:text-gray-400">· {{ $note }}</span>
                            @endforeach

                            @if ($row['cancelled'])
                                <span class="text-danger-600 dark:text-danger-400">· {{ __('import.preview.cancelled') }}</span>
                            @endif
                        </td>
                        <td class="py-2 align-top text-gray-500 dark:text-gray-400">
                            {{ $row['where'] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
