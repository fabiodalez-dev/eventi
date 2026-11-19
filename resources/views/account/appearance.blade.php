{{--
    Aspetto: la pagina di riferimento delle pagine di servizio. Colonna unica
    del layout `service`, testata di `<x-service-page>`, contenuto in card.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="__('ui.appearance_page.title')"
        :subtitle="__('ui.appearance_page.subtitle')"
        :back="auth()->check() ? route('account.profile') : null"
    >
        <x-card :title="__('ui.appearance_page.theme_title')" :description="__('ui.appearance_page.theme_description')">
            {{-- Il selettore porta un proprio margine sopra, pensato per la
                 pagina nuda: dentro la card le distanze le dà la card. --}}
            <div class="[&>form]:mt-0">
                <x-appearance-picker />
            </div>
        </x-card>

        <div>
            <x-button variant="ghost" :href="route('home')">
                <x-lucide name="arrow-left" class="size-4" />
                {{ __('ui.appearance_page.back_events') }}
            </x-button>
        </div>
    </x-service-page>
</x-layouts.app>
