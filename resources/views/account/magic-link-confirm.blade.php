{{-- Il collegamento di accesso aperto in un browser diverso da quello che lo
     ha chiesto: prima di entrare si vede su quale account, e si entra solo con
     il pulsante. La GET non consuma niente, così gli scanner di posta non
     bruciano il collegamento. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.magic.confirm_lead', ['email' => $email])">
        <x-card as="form" method="POST" action="{{ $action }}" :title="__('auth.magic.confirm_card_title')" :description="__('auth.magic.confirm_card_description')">
            @csrf

            <label class="flex min-h-11 items-center gap-3 text-sm text-ink">
                <input type="checkbox" name="remember" value="1" class="size-5 shrink-0 accent-brand">
                {{ __('account.login.remember') }}
            </label>

            <p class="text-sm text-ink-muted">{{ __('account.magic.confirm_not_you') }}</p>

            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">{{ __('account.magic.confirm_submit') }}</x-button>
                    <x-button variant="secondary" :href="route('home')">{{ __('auth.magic.confirm_cancel') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
