{{--
    «Controlla la posta» dopo la registrazione (§15.2, D64).

    È la stessa pagina per un indirizzo nuovo e per uno già registrato: la
    differenza sta solo nel messaggio che arriva nella casella. Per questo non
    mostra l'indirizzo né dice «account creato».
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('auth.register.sent_lead', ['minutes' => config('account.magic_link_minutes')])">
        <x-card :title="__('auth.register.sent_card_title')">
            <p class="text-base text-ink">{{ __('account.register.sent_same_browser') }}</p>
            <p class="text-sm text-ink-muted">{{ __('account.register.sent_help') }}</p>

            {{-- Il reinvio è rifare la registrazione con la stessa password: il
                 collegamento che riparte lascia valida la password scelta. Un
                 collegamento di accesso qualunque non lo sa fare dall'app, e qui
                 non lo si propone, perché sito e app dicano la stessa cosa. --}}
            <x-slot:footer>
                <x-form-actions>
                    <x-button variant="secondary" :href="route('account.register')">{{ __('account.register.sent_again') }}</x-button>
                    <x-button variant="ghost" :href="route('home')">{{ __('auth.register.sent_home') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
