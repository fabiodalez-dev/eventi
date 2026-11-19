{{--
    La foto di una persona, oppure le sue iniziali quando la foto non c'è.
    Tonda in entrambi i temi. Le iniziali sono le prime lettere delle prime due
    parole del nome; il nome intero va nell'`alt` solo se la foto è l'unico
    modo di riconoscere la persona — accanto al nome scritto è decorativa.
--}}
@props(['src' => null, 'name' => '', 'size' => 'md', 'alt' => ''])
@php
    $px = ['sm' => 32, 'md' => 40, 'lg' => 72][$size] ?? 40;
    $box = ['sm' => 'size-8 text-xs', 'md' => 'size-10 text-sm', 'lg' => 'size-[72px] text-2xl'][$size] ?? 'size-10 text-sm';
    $initials = collect(preg_split('/\s+/u', trim((string) $name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<span data-avatar {{ $attributes->class(['svc-avatar', $box]) }}>
    @if (filled($src))
        <img src="{{ $src }}" alt="{{ $alt }}" width="{{ $px }}" height="{{ $px }}" loading="lazy" decoding="async">
    @else
        <span aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
    @endif
</span>
