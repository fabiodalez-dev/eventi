{{--
    Registrazione (§15.2).

    La pagina dice a che cosa serve un account — promemoria, salvataggi su ogni
    dispositivo, feed — e non finge che senza account il sito non funzioni: **il
    cuore funziona già** (§15.1), e chi arriva qui lo sa perché ha appena
    salvato tre date.
--}}
@php($intendedQuery = filled(session('url.intended')) ? ['intended' => session('url.intended')] : [])
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.register.lead')" :show-errors="false">
        {{-- Il riepilogo sotto il titolo, con un titolo vero quando il modulo
             non è nemmeno arrivato (sessione scaduta, troppi tentativi). --}}
        <x-form-errors :title="$errors->has('form') ? __('auth.form.title') : null" />
        <x-card as="form" method="POST" action="{{ route('account.register.store') }}" :title="__('auth.register.card_title')" :description="__('auth.register.card_description')">
            @csrf
            <x-honeypot />

            <x-field name="first_name" :label="__('account.register.first_name')" autocomplete="given-name" />
            <x-field name="last_name" :label="__('account.register.last_name')" autocomplete="family-name" />
            <x-field name="email" type="email" :label="__('account.register.email')" :required="true" autocomplete="email" />
            <x-field name="password" type="password" :label="__('account.register.password')" :required="true" autocomplete="new-password" />
            <x-field name="password_confirmation" type="password" :label="__('auth.register.password_confirmation')" :required="true" autocomplete="new-password" />

            <label class="flex min-h-11 items-center gap-3 text-sm text-ink">
                <input type="checkbox" name="remember" value="1" class="size-5 shrink-0 accent-brand" @checked(old('remember'))>
                {{ __('account.login.remember') }}
            </label>

            @newsletter
            <label class="flex items-start gap-3 py-2 text-sm text-ink">
                <input type="checkbox" name="marketing_opt_in" value="1" class="mt-0.5 size-5 shrink-0 accent-brand" @checked(old('marketing_opt_in'))>
                <span class="flex flex-col gap-0.5">
                    {{ __('account.register.marketing') }}
                    <span class="text-xs text-ink-muted">{{ __('account.register.marketing_hint') }}</span>
                </span>
            </label>
            @endnewsletter

            <x-turnstile action="registrazione-utente" />

            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">{{ __('account.register.submit') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>

        <x-card tone="muted" :title="__('auth.register.have_account')">
            <x-slot:actions>
                <x-button variant="secondary" size="sm" :href="route('login', $intendedQuery)">{{ __('auth.register.login_action') }}</x-button>
            </x-slot:actions>
        </x-card>
    </x-service-page>
</x-layouts.app>
