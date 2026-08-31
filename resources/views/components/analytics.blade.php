{{--
    L'analitica senza cookie (§16: «Analytics privacy-first»).

    **Quando è spenta questo file non produce un solo byte.** Non uno script
    vuoto, non un commento, non un `preconnect`: chi legge il sorgente di una
    pagina di questo sito, con `ANALYTICS_*` vuote, non trova alcun riferimento
    a un dominio che non sia il nostro. È la differenza fra "non traccia" e
    "non contatta nessuno".

    È spenta in tre casi, e bastano: le variabili d'ambiente non ci sono, oppure
    ci sono ma chi sta guardando non ha acconsentito, oppure non ha ancora
    deciso — che ai fini del consenso preventivo è la stessa cosa di un
    rifiuto.
--}}
@php $analytics = app(\App\Services\Analytics\AnalyticsScript::class); @endphp

@if ($analytics->enabled())
    {{-- `defer` perché un contatore non deve mai stare fra chi legge e il
         disegno della pagina. Nessun `async`: con `defer` l'esecuzione avviene
         dopo il parsing, in ordine, e senza rubare tempo al primo disegno. --}}
    <script defer @foreach ($analytics->attributes() as $name => $value) {{ $name }}="{{ $value }}" @endforeach></script>
@endif
