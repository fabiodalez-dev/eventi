{{--
    La campagna in cima ai risultati dell'elenco. Come la card della home,
    si disegna sia dentro la pagina sia da sola per il buco della cache
    (`SponsorSlots`): un solo disegno per le due strade.
--}}
<x-sponsored-card
    :sponsorship="$sponsorship"
    :occurrence="$sponsoredOccurrence"
    placement="list_top"
    :show-poster="true"
    class="mb-4 border-b-2 border-line md:mb-6"
    level="h2"
/>
