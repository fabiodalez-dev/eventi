{{--
    I numeri dei pannelli: numero grande, etichetta, e sotto la frase che lo
    spiega. Una voce per riga sul telefono; da 640 px le voci si affiancano
    andando a capo (l'unica eccezione alla colonna unica, con «Inizio / Fine»).

    Si usa come componente, `<x-pannelli::stat-cards :items="$items" … />`, o
    con `@include('filament.partials.stat-cards', [...])`.

    Props: `items` (elenco di `['label' => …, 'value' => …, 'hint' => …,
    'key' => …]`) e `label` (il nome dell'elenco per i lettori di schermo).
    Un numero si scrive con il separatore italiano («6.805», «33,3»); un valore
    `null` diventa «Ancora nessun dato», mai «0%» o «n.d.».
--}}
@props([
    'items' => [],
    'label' => null,
])
@php
    $format = static function (mixed $value): string {
        if ($value === null || $value === '') {
            return __('pannelli.stats.no_data');
        }

        if (is_int($value)) {
            return number_format($value, 0, ',', '.');
        }

        if (is_float($value)) {
            return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 1, ',', '.');
        }

        return (string) $value;
    };
@endphp
<dl
    {{ $attributes->class(['incitta-stat-cards']) }}
    data-stat-cards
    @if (filled($label)) aria-label="{{ $label }}" @endif
>
    @foreach ($items as $item)
        <div class="incitta-stat-card" data-stat="{{ $item['key'] ?? $loop->index }}">
            <dt class="incitta-stat-card-label">{{ $item['label'] }}</dt>
            <dd @class(['incitta-stat-card-value', 'incitta-stat-card-value-empty' => ($item['value'] ?? null) === null || ($item['value'] ?? null) === ''])>{{ $format($item['value'] ?? null) }}</dd>
            @if (filled($item['hint'] ?? null))
                <dd class="incitta-stat-card-hint">{{ $item['hint'] }}</dd>
            @endif
        </div>
    @endforeach
</dl>
