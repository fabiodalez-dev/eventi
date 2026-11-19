{{--
    «Organizzi eventi?»: la richiesta a chi pubblica. Sta nella colonna sinistra
    della fascia del calendario, sotto la griglia del mese (da telefono in
    fondo alla fascia, dopo il prossimo evento): a destra il prossimo evento con
    «a seguire» è più alto del calendario, e lo spazio sotto il mese restava
    vuoto.
--}}
<div class="home-cta flex flex-col gap-4 border-2 border-line bg-surface p-[clamp(1rem,2vw,1.5rem)]" role="region" aria-labelledby="home-cta-title" data-home-organize>
    <div class="flex flex-col gap-2">
        <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase">{{ __('ui.cta.label') }}</span>
        <h2 id="home-cta-title" class="m-0 font-display text-[clamp(1.375rem,2.2vw,1.875rem)] leading-[0.98] font-extrabold tracking-[-0.03em] uppercase">{{ __('ui.cta.title') }}</h2>
        <p class="m-0 max-w-prose text-sm text-ink-muted">{{ __('ui.cta.lead') }}</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <x-button :href="route('submissions.create')">{{ __('events.submit.title') }}</x-button>
        <x-button :href="route('venue-applications.create')" variant="secondary">{{ __('venues.claim.title') }}</x-button>
        <x-button :href="route('organizer-applications.create')" variant="secondary">{{ __('forms.organizer_application.title') }}</x-button>
    </div>
</div>
