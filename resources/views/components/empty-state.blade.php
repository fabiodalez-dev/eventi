{{--
    Stato vuoto **legittimo**: una ricerca senza risultati, un locale senza date
    in programma, il calendario di un giorno futuro ancora scoperto — cioè le
    pagine in cui l'utente ha chiesto qualcosa di preciso e ha diritto a una
    risposta.

    NON va usato per riempire una sezione temporale vuota della home: se "in
    corso" o "stasera" non contengono nulla, quella sezione non si disegna
    affatto (§8.6). Mai un contenitore vuoto, mai la scritta "nessun evento"
    dove nessuno l'ha chiesta.
--}}
@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class([
    'flex flex-col items-center rounded-card border border-dashed border-line bg-surface-sunken px-6 py-12 text-center',
]) }}>
    <h2 class="text-card text-ink">{{ $title ?? __('ui.empty_state.default_title') }}</h2>

    @if ($description)
        <p class="mt-2 max-w-prose text-sm text-ink-muted">{{ $description }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
            {{ $slot }}
        </div>
    @endif
</div>
