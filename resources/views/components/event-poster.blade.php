{{--
    La locandina di un evento, intera, che si apre a schermo pieno.

    **Perché intera e non ritagliata.** In cima alla pagina la locandina fa da
    sfondo: ritagliata, coperta da un gradiente e inizialmente in bianco e nero, perché lì
    serve a dare un tono, non a essere letta. Ma una locandina è un manifesto —
    qualcuno l'ha disegnata, ci ha messo il nome degli ospiti, l'orario, il
    prezzo, a volte l'unica informazione che da nessun'altra parte esiste. Chi
    la vuole leggere deve poterla vedere per intero.

    **Dal bianco e nero al colore.** La locandina entra nello stesso linguaggio
    fotografico delle anteprime e, appena è pronta, recupera gradualmente i
    colori originali. Il movimento è breve e viene disattivato quando il
    sistema chiede animazioni ridotte.

    **Un `<dialog>` e non un riquadro fatto a mano.** Porta con sé il fondo
    oscurato, la chiusura con Esc, il fuoco intrappolato dentro e il ritorno
    del fuoco al pulsante che l'ha aperto: tutte cose che un `<div>` con
    `position: fixed` va poi a rifare peggio.
--}}
@props(['event', 'set'])

@php
    $titolo = $event->content_details['poster_alt'] ?? __('events.card.poster_alt', ['title' => $event->title]);
    $id = 'locandina-'.$event->getKey();
@endphp

<figure class="flex flex-col gap-2">
    <button
        type="button"
        data-apre-dialogo="{{ $id }}"
        class="event-poster-frame group relative block w-full overflow-hidden border-2 border-line bg-canvas transition-colors hover:border-accent"
        aria-label="{{ __('events.poster.open') }}"
    >
        <x-media-image
            :set="$set"
            :alt="$titolo"
            width="800"
            height="1131"
            sizes="(min-width: 1024px) 22rem, 100vw"
            :color="true" :show-placeholder="false"
            data-poster-reveal
            class="poster-reveal"
        />

        {{-- L'invito compare al passaggio, ma su un telefono il passaggio non
             esiste: resta sempre visibile sotto il tocco, e su schermo grande
             si accende quando serve. --}}
        <span
            aria-hidden="true"
            class="ui-tag absolute right-0 bottom-0 bg-accent px-3 py-2 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-opacity md:opacity-0 md:group-hover:opacity-100 md:group-focus-visible:opacity-100"
        >
            {{ __('events.poster.open') }}
        </span>
    </button>

    <figcaption class="text-xs text-ink-subtle">{{ __('events.poster.caption') }}</figcaption>
</figure>

{{--
    **Il dialogo occupa lo schermo, e il contenuto si centra dentro.**

    Un `<dialog>` si centra da solo grazie al `margin: auto` che il browser gli
    dà — ma il reset di Tailwind azzera i margini di tutto, e con `inset: 0`
    quel che resta è un riquadro incollato in alto a sinistra. Era largo 635 px
    su una finestra da 1440, con 805 px di vuoto a destra.

    Rimettere `margin: auto` funzionerebbe, ma solo finché il dialogo è più
    piccolo dello schermo: una locandina molto alta tornerebbe a sbordare. Con
    il dialogo a schermo pieno e un contenitore flex dentro, il centro è il
    centro in ogni caso — e il clic sul vuoto attorno all'immagine continua a
    chiudere, perché quel vuoto è ancora il dialogo.
--}}
<dialog
    id="{{ $id }}"
    class="poster-lightbox fixed inset-0 m-0 max-w-none bg-transparent p-0 backdrop:bg-[rgba(11,11,11,0.92)]"
    aria-label="{{ $titolo }}"
>
    {{-- **La × in alto a destra.**

         È il primo posto in cui si guarda per uscire da un'immagine aperta a
         schermo pieno, prima ancora di cercare un pulsante. Sta sopra
         l'immagine e non sotto perché li' la si trova senza cercarla, e perché
         una locandina alta spinge un pulsante in fondo fuori dallo schermo.

         Resta anche il tocco sul fondo scuro, e resta Esc: tre modi per la
         stessa cosa non sono ridondanza quando il gesto e' «uscire» — chi non
         trova il primo prova il secondo, e nessuno resta chiuso dentro. --}}
    <button
        type="button"
        data-chiude-dialogo
        aria-label="{{ __('common.actions.close') }}"
        class="poster-lightbox-close absolute z-10 flex size-12 items-center justify-center border-2 border-ink bg-canvas text-ink transition-colors hover:bg-accent hover:text-on-accent"
    >
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" class="size-5" aria-hidden="true">
            <path d="M4 4l12 12M16 4L4 16" stroke-linecap="square" />
        </svg>
    </button>

    <div data-dialog-backdrop class="poster-lightbox-frame">
        {{-- Senza ritaglio: `object-contain` e non `cover`, perché una
             locandina tagliata è una locandina non letta. --}}
        {{-- Larghezza e altezza dichiarate anche qui, dove sembrano
             superflue: il dialogo si apre su un'immagine non ancora scaricata,
             e senza misure il riquadro cresce di colpo sotto gli occhi. È la
             stessa regola che vale per tutte le immagini del sito (§11.11), e
             un test la fa rispettare su ognuna. --}}
        <img
            src="{{ $set->src }}"
            alt="{{ $titolo }}"
            width="{{ $set->width ?? 800 }}"
            height="{{ $set->height ?? 1131 }}"
            class="poster-lightbox-image"
        />
    </div>
</dialog>
