{{-- «Conferma il tuo indirizzo»: l'account c'è, manca la prova della casella. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('account.verify.title')" :eyebrow="__('auth.verify.eyebrow')" :subtitle="__('auth.verify.instructions')">
        <x-card :title="__('auth.verify.card_title')">
            <p class="flex items-center gap-3 font-semibold break-all text-ink">
                <x-lucide name="mail" class="size-5 shrink-0 text-accent" />
                <span>{{ $email }}</span>
            </p>
            <p class="text-sm text-ink-muted">{{ __('account.verify.benefits') }}</p>
        </x-card>

        <x-card tone="muted" :title="__('auth.verify.help_title')" :description="__('account.verify.help')">
            <x-slot:footer>
                <x-form-actions>
                    <form method="POST" action="{{ route('account.verification.send') }}">
                        @csrf
                        <x-button type="submit" :block="true">{{ __('account.verify.resend') }}</x-button>
                    </form>
                    <x-button variant="secondary" :href="route('home')">{{ __('account.verify.explore') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
