{{--
    "In corso adesso" e "Inizia tra poco" (§11.2). Il componente arriva dopo il
    primo disegno della pagina; se non c'è nulla in nessuna delle due finestre
    non disegna alcuna sezione (§8.6) e resta un contenitore senza altezza.
--}}
<div>
    @if ($ongoing->isNotEmpty())
        <section class="mt-section" aria-labelledby="sezione-ongoing">
            <x-section-heading
                id="sezione-ongoing"
                :title="__('events.sections.ongoing')"
                :description="__('events.sections.ongoing_lead')"
                tone="live"
            />

            <x-event-grid :occurrences="$ongoing" context="ongoing" :eager="true" />
        </section>
    @endif

    @if ($startingSoon->isNotEmpty())
        <section class="mt-section" aria-labelledby="sezione-starting-soon">
            <x-section-heading
                id="sezione-starting-soon"
                :title="__('events.sections.starting_soon')"
                :description="__('events.sections.starting_soon_lead')"
                tone="soon"
            />

            <x-event-grid :occurrences="$startingSoon" context="starting_soon" />
        </section>
    @endif
</div>
