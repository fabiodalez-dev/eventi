{{--
    Una pagina redazionale (§11.1: `/pagine/{slug}`).

    Il corpo arriva dal database in Markdown e viene convertito scartando
    qualunque marcatura grezza (`Page::renderedBody()`): non c'è alcun percorso
    per cui un `<script>` scritto nel campo finisca in pagina.

    La data di ultimo aggiornamento non è un ornamento: su un'informativa
    privacy o su dei termini di servizio è l'informazione che dice se quello che
    si sta leggendo è ancora quello che si era accettato.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $updatedAt = $page->updated_at;
@endphp

<x-layouts.app :narrow="true" :meta="app(\App\Services\Seo\EditorialContent::class)->meta($page, $meta)">
    <x-editorial-content :model="$page" />
    <article class="mx-auto w-full max-w-prose">
        <header class="flex flex-col gap-2">
            <p class="text-eyebrow text-ink-subtle uppercase">{{ __('pages.breadcrumb') }}</p>

            <h1 class="font-display text-hero text-ink">{{ $page->title }}</h1>

            @if (filled($page->excerpt))
                <p class="text-sm text-ink-muted">{{ $page->excerpt }}</p>
            @endif

            @if ($updatedAt instanceof \DateTimeInterface)
                <p class="text-xs text-ink-subtle">
                    {{ __('pages.updated_at', ['date' => $formatter->instantDate($updatedAt)]) }}
                </p>
            @endif
        </header>

        <div class="rich-text mt-8 text-sm sm:text-base">
            {!! $page->renderedBody() !!}
        </div>

        {{-- La Cookie Policy è il posto in cui si torna per cambiare idea: il
             banner, a quel punto, è sparito da un pezzo. Il pannello compare
             solo lì, e non su ogni pagina informativa. --}}
        @if ($page->slug === config('consent.cookie_page'))
            {{-- L'elenco dei cookie viene dal registro, non dal testo della
                 pagina: una fonte sola, che si aggiorna dal pannello quando
                 cambia uno strumento. Sta sopra il pannello delle scelte
                 perché prima si legge cosa c'è, poi si decide. --}}
            <section class="rich-text mt-8 text-sm sm:text-base" aria-labelledby="appearance-cookie-policy">
                <h2 id="appearance-cookie-policy">Aspetto e preferenza del tema</h2>
                <p>Il cookie essenziale <code>incitta_appearance</code>, impostato da questo sito, ricorda il tema chiaro o scuro per un anno dall’ultima visita. Alla prima apertura rileviamo il tema del sistema; puoi cambiarlo dal selettore Aspetto o dal profilo. Non viene usato per pubblicità o tracciamento.</p>
                <p>Conserviamo una copia di supporto nella memoria locale del browser (<code>incitta:appearance:guest</code>), fino alla cancellazione dei dati del sito. La preferenza funziona anche rifiutando i cookie facoltativi. Per ripartire dalla scelta di sistema, cancella cookie e memoria locale del sito nelle impostazioni del browser.</p>
                <p>Se accedi, prevale il tema salvato nel tuo profilo, condiviso con gli altri dispositivi e con l’app Android.</p>
            </section>
            <x-cookie-table class="mt-8" />

            <x-consent-preferences />
        @endif
    </article>
</x-layouts.app>
