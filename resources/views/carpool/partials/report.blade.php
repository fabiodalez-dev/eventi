{{--
    «Segnala un problema»: chiuso per difetto, si apre sul posto. Va
    all'assistenza con il riferimento all'offerta, alla richiesta o alla
    recensione della pagina. Con `bare` niente card intorno: serve dentro una
    card che c'è già (la recensione). La card si apre e si chiude nello
    stesso ramo: aprirla e chiuderla in due `@unless` separati spezzava
    l'annidamento degli `if` compilati e con `bare` non usciva niente.
--}}
@php($bare ??= false)
@if ($bare)
    @include('carpool.partials.report-form')
@else
    <x-card tone="muted" padding="sm" data-carpool-report>
        @include('carpool.partials.report-form')
    </x-card>
@endif
