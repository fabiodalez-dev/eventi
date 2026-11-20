{{--
    La campagna della pagina iniziale, subito dopo la fascia dei numeri.

    Sta in un file a parte perché si disegna in due momenti: dentro la pagina,
    per chi è collegato, e da sola, quando la pagina degli anonimi la riceve
    dal buco della cache a ogni visita (`SponsorSlots`). Un solo disegno, così
    le due strade non possono divergere.
--}}
<section class="home-recommendation border-b-2 border-line" aria-labelledby="home-recommendation-title">
    <h2 id="home-recommendation-title" class="font-display text-3xl font-extrabold tracking-tight">{{ __('inCittà consiglia') }}</h2>
    <x-sponsored-card
        :sponsorship="$cardSponsorship"
        placement="home_card"
        class="home-recommendation__event"
        level="h3"
    />
</section>
