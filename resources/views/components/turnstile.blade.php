{{--
    Il riquadro Cloudflare Turnstile (§14.7).

    Non disegna nulla quando le chiavi non sono configurate: è ciò che permette
    a sviluppo, test e integrazione continua di compilare i moduli senza un
    servizio esterno raggiungibile.

    Lo script si carica `defer`, dopo il modulo: la verifica avviene mentre la
    persona compila, e la sua assenza non deve ritardare il primo disegno della
    pagina. Non porta `integrity`: quel file è mutabile per scelta di
    Cloudflare — è così che distribuisce le contromisure nuove — e una firma
    fissa lo spegnerebbe al primo aggiornamento loro.

    Il messaggio di errore sta accanto al riquadro perché è la validazione del
    server a scriverlo: il gettone si verifica lì, non nel browser.

    **`data-action` dice a quale modulo appartiene il gettone**, e il server lo
    ricontrolla. Senza, un gettone risolto sul modulo meno sorvegliato varrebbe
    per tutti gli altri: se ne risolve uno dove costa meno e lo si spende dove
    serve.
--}}
@props(['action' => null])
@if (\App\Support\Turnstile::enabled())
    <div class="flex flex-col gap-1.5">
        <div
            class="cf-turnstile"
            data-sitekey="{{ \App\Support\Turnstile::siteKey() }}"
            @if ($action) data-action="{{ $action }}" @endif
            data-language="{{ str_replace('_', '-', app()->getLocale()) }}"
            data-theme="auto"
        ></div>

        @error(\App\Support\Turnstile::FIELD)
            <p role="alert" class="text-sm font-semibold text-live">{{ $message }}</p>
        @enderror
    </div>

    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
    @endonce
@endif
