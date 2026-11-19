{{--
    L'esito della disiscrizione a un click (§15.9).

    La pagina conferma ciò che è già avvenuto: il collegamento nell'email non
    porta a una richiesta di conferma, perché una promessa di «un click» che ne
    richiede due è il momento in cui un messaggio viene segnato come spam.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading">
        <x-card>
            <div class="flex items-start gap-3">
                <span @class(['mt-0.5 shrink-0', 'text-accent' => $stopped && $type !== null, 'text-ink-muted' => ! ($stopped && $type !== null)])><x-lucide :name="$stopped && $type !== null ? 'check' : 'info'" class="size-6" /></span>
                <p class="m-0 text-base text-ink">{{ $stopped && $type !== null ? __('notifications.unsubscribed.body', ['type' => $type->label()]) : __('notifications.unsubscribed.mandatory') }}</p>
            </div>
            <x-form-actions>
                <x-button variant="secondary" :href="$preferencesUrl">{{ __('notifications.unsubscribed.undo') }}</x-button>
                <x-button variant="ghost" :href="route('home')">{{ __('notifications.unsubscribed.home') }}</x-button>
            </x-form-actions>
        </x-card>
    </x-service-page>
</x-layouts.app>
