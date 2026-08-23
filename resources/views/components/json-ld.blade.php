{{--
    Dati strutturati JSON-LD (§12.2).

    La codifica usa JSON_HEX_TAG e compagnia: un titolo che contenesse
    `</script>` chiuderebbe il blocco e trasformerebbe un dato in codice.
    Qui dentro non entra mai HTML, nemmeno per sbaglio.
--}}
@props(['data'])

@php
    $graph = array_values(array_filter(is_array($data) ? $data : [$data]));
@endphp

@foreach ($graph as $node)
    <script type="application/ld+json">{!! json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endforeach
