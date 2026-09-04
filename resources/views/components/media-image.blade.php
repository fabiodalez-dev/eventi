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
    /* Lascia l'immagine a colori.

       Le fotografie del sito stampano in bianco e nero (D47) e la regola vale
       per ogni immagine di contenuto: e' cio' che tiene insieme pagine piene
       di foto arrivate da fonti diverse. Ma una **locandina** non e' una
       fotografia di corredo — qualcuno l'ha disegnata scegliendo quei colori,
       e sono parte di cio' che dice.

       La deroga si chiede, non si aggira: chi la usa lo dichiara qui, e
       cercare `:color="true"` dice in un colpo dove il bianco e nero non vale
       e perche'. Aggiungere `class="filter-none"` da fuori avrebbe funzionato
       uguale e non avrebbe lasciato traccia. */
    'color' => false,
    /* Misure dichiarate: sono il rapporto, non la dimensione a schermo */
    'width',
    'height',
    /* Lo spazio che l'immagine occuperà, per far scegliere al browser.

       **Il valore del set vince sul default.** Chi prepara l'immagine può
       averlo già dichiarato con `->withSizes(...)`, ed è la stessa stringa che
       il layout mette nell'`imagesizes` del preload. Se qui restasse `100vw`
       fisso, il preload annuncerebbe una variante e l'`<img>` ne chiederebbe
       un'altra: due file scaricati al posto di uno, e il preload che fa
       perdere tempo invece di guadagnarlo.

       Era esattamente il caso dell'apertura della home, l'immagine più grande
       sopra la piega: dichiarata `(min-width: 1024px) 50vw, 100vw` nel set,
       servita a schermo pieno nel markup. */
    'sizes' => null,
    /* La prima riga di card è sopra la piega: quelle locandine non si rinviano */
    'eager' => false,
])

@php
    $misure = $sizes ?? $set->sizes ?? '100vw';

    $placeholder = $set->placeholder;
    $style = $placeholder === null
        ? null
        : 'background-image:url('.$placeholder.');background-size:cover;background-position:center';
@endphp

<picture>
    @foreach ($set->sources as $type => $srcset)
        <source type="{{ $type }}" srcset="{{ $srcset }}" sizes="{{ $misure }}">
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
             ogni immagine di contenuto, segnaposto sfocato compreso — salvo
             dove si chiede il colore per una ragione dichiarata. --}}
        {{ $attributes->class(['grayscale-photo' => ! $color]) }}
    >
</picture>
