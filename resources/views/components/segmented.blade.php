{{--
    Una scelta segmentata fatta di collegamenti: Prossimi / Passati, Lista /
    Calendario, i periodi di un elenco. Ogni voce: `label`, `href`, `active` e
    facoltativamente `count`. È navigazione — ogni scelta ha il suo indirizzo,
    funziona senza JavaScript e si può condividere — quindi la voce scelta è
    `aria-current="page"`, non un pulsante premuto.

    Sul telefono, se le voci non ci stanno, il gruppo scorre in orizzontale.
--}}
@props(['label', 'items' => []])
<nav aria-label="{{ $label }}" data-segmented {{ $attributes->class('min-w-0') }}>
    <ul class="svc-segmented m-0 list-none">
        @foreach ($items as $item)
            <li class="flex">
                <a href="{{ $item['href'] }}" @if ($item['active'] ?? false) aria-current="page" @endif>
                    {{ $item['label'] }}
                    @if (isset($item['count']) && $item['count'] !== null)
                        <span class="tabular-nums opacity-75">{{ $item['count'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
