{{--
    Un'immagine servita bene: `<picture>` con AVIF e WebP, misure dichiarate,
    caricamento rinviato e segnaposto sfocato (§12.1 e §11.11).

    Perché `<picture>` e non un `<img>` con `srcset`: `srcset` sceglie fra
    **misure**, `<source type>` sceglie fra **formati**. Ne servono entrambi —
    l'AVIF a chi lo apre, il WebP a tutti gli altri, e in ciascuno dei due la
    variante grande solo a chi ha lo spazio per mostrarla.

    Le tre cose che eliminano il salto di layout, e vanno tutte e tre insieme:
    `width`/`height` sull'immagine perché il browser conosca il rapporto prima
    di scaricarla, il rapporto dichiarato sul contenitore, e il segnaposto come
    sfondo — così il rettangolo non è mai bianco né vuoto.
--}}
@props([
    /* App\Support\Media\ImageSet */
    'set',
    'alt',
    /* Misure dichiarate: sono il rapporto, non la dimensione a schermo */
    'width',
    'height',
    /* Lo spazio che l'immagine occuperà, per far scegliere al browser */
    'sizes' => '100vw',
    /* La prima riga di card è sopra la piega: quelle locandine non si rinviano */
    'eager' => false,
])

@php
    $placeholder = $set->placeholder;
    $style = $placeholder === null
        ? null
        : 'background-image:url('.$placeholder.');background-size:cover;background-position:center';
@endphp

<picture>
    @foreach ($set->sources as $type => $srcset)
        <source type="{{ $type }}" srcset="{{ $srcset }}" sizes="{{ $sizes }}">
    @endforeach

    <img
        src="{{ $set->src }}"
        alt="{{ $alt }}"
        width="{{ $width }}"
        height="{{ $height }}"
        loading="{{ $eager ? 'eager' : 'lazy' }}"
        fetchpriority="{{ $eager ? 'high' : 'auto' }}"
        decoding="async"
        @if ($style !== null) style="{{ $style }}" @endif
        {{-- Le fotografie stampano in bianco e nero (D47): la regola vale per
             ogni immagine di contenuto, segnaposto sfocato compreso. --}}
        {{ $attributes->class(['grayscale-photo']) }}
    >
</picture>
