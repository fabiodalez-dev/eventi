{{--
    Il login unico (§15.2): una porta sola per tutti, redazione e pannelli
    compresi. Dopo l'accesso si torna alla pagina da cui si era partiti, o
    nella propria area (`PostLoginDestination`).

    Il collegamento senza password sta accanto, non al posto: è la via
    raccomandata, non l'unica. Quando si arriva da un pannello, un riquadro
    dice dove si sta entrando.
--}}
@php
    $intended = (string) session('url.intended');
    $panel = $intended !== '' ? \App\Support\Auth\PostLoginDestination::panelFor($intended) : null;
    $intendedQuery = $intended !== '' ? ['intended' => $intended] : [];
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.login.lead')" :show-errors="false">
        {{-- Il riepilogo sotto il titolo, con un titolo vero quando il modulo
             non è nemmeno arrivato (sessione scaduta, troppi tentativi). --}}
        <x-form-errors :title="$errors->has('form') ? __('auth.form.title') : null" />
        @if ($panel !== null)
            <x-notice tone="info" :title="__('auth.panel.intended.'.$panel->getId())" data-login-panel="{{ $panel->getId() }}">
                <p>{{ __('auth.panel.intended.body') }}</p>
            </x-notice>
        @elseif (str_contains($intended, '/i-miei-salvataggi'))
            <x-notice tone="info">
                <p>{{ __('account.saved.login_required') }}</p>
            </x-notice>
        @endif

        <x-card as="form" method="POST" action="{{ route('account.login.store') }}" :title="__('auth.login.card_title')" :description="__('auth.login.card_description')">
            @csrf

            <x-field name="email" type="email" :label="__('account.login.email')" :required="true" autocomplete="email" />
            <x-field name="password" type="password" :label="__('account.login.password')" :required="true" autocomplete="current-password" />

            <label class="flex min-h-11 items-center gap-3 text-sm text-ink">
                <input type="checkbox" name="remember" value="1" class="size-5 shrink-0 accent-brand" @checked(old('remember'))>
                {{ __('account.login.remember') }}
            </label>

            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">{{ __('account.login.submit') }}</x-button>
                    <x-button variant="secondary" :href="route('account.magic-link', $intendedQuery)">
                        <x-lucide name="mail" class="size-4" />
                        {{ __('auth.login.magic') }}
                    </x-button>
                    {{-- Chi ha una password che non funziona trova l'uscita nello
                         stesso punto in cui si è arenato. --}}
                    <x-button variant="ghost" :href="route('account.password.request')">{{ __('auth.login.forgot') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>

        <x-card tone="muted" :title="__('auth.login.register_title')" :description="__('auth.login.register_description')">
            <x-slot:actions>
                <x-button variant="secondary" size="sm" :href="route('account.register', $intendedQuery)">{{ __('auth.login.register_action') }}</x-button>
            </x-slot:actions>
        </x-card>
    </x-service-page>
</x-layouts.app>
