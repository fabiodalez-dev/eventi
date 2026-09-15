<section class="home-cta grid gap-5 border-b-2 border-line px-gutter py-8 md:grid-cols-2" aria-label="{{ __('ui.cta.label') }}">
    <h2 class="m-0 font-display text-3xl font-extrabold tracking-tight">{{ __('ui.cta.title') }}</h2>
    <div>
        <p class="max-w-prose text-sm text-ink-muted">{{ __('ui.cta.lead') }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-button :href="route('submissions.create')">{{ __('events.submit.title') }}</x-button>
            <x-button :href="route('venue-applications.create')">{{ __('venues.claim.title') }}</x-button>
        </div>
    </div>
</section>
