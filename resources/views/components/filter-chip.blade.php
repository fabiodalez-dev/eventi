{{--
    Una pillola di filtro. È **un link**, non un pulsante: accendere un filtro
    cambia l'indirizzo della pagina, e deve poterlo fare anche chi ha
    JavaScript disattivato o chi apre il filtro in una scheda nuova (§11.3).

    `aria-pressed` non si usa sui link: lo stato acceso si dichiara con
    `aria-current`, che è ciò che uno screen reader legge come "pagina corrente".
--}}
@props([
    'href',
    'active' => false,
    'count' => null,
])

<a
    href="{{ $href }}"
    @if ($active) aria-current="true" @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-pill px-3.5 py-1.5 text-sm font-semibold whitespace-nowrap transition',
        'bg-brand text-on-brand' => $active,
        'bg-surface text-ink-muted ring-1 ring-line hover:text-ink hover:ring-line-strong' => ! $active,
    ]) }}
>
    {{ $slot }}

    @if ($count !== null)
        <span class="{{ $active ? 'text-on-brand/80' : 'text-ink-subtle' }} text-xs">{{ $count }}</span>
    @endif

    @if ($active)
        <span aria-hidden="true">&times;</span>
    @endif
</a>
