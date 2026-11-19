{{-- Accesso senza password (§15.2), la via raccomandata: per un uso saltuario
     come questo, una password da ricordare è attrito puro. --}}
@php($intendedQuery = filled(session('url.intended')) ? ['intended' => session('url.intended')] : [])
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.magic.lead', ['minutes' => config('account.magic_link_minutes')])" :show-errors="false">
        {{-- Il riepilogo sotto il titolo, con un titolo vero quando il modulo
             non è nemmeno arrivato (sessione scaduta, troppi tentativi). --}}
        <x-form-errors :title="$errors->has('form') ? __('auth.form.title') : null" />
        <x-card as="form" method="POST" action="{{ route('account.magic-link.store') }}" :title="__('auth.magic.card_title')" :description="__('auth.magic.card_description')">
            @csrf

            <x-field name="email" type="email" :label="__('account.login.email')" :required="true" autocomplete="email" />

            {{-- Vale se il collegamento si apre in questo browser; altrove lo si
                 sceglie nella pagina di conferma. --}}
            <label class="flex min-h-11 items-center gap-3 text-sm text-ink">
                <input type="checkbox" name="remember" value="1" class="size-5 shrink-0 accent-brand" @checked(old('remember'))>
                {{ __('account.login.remember') }}
            </label>

            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">
                        <x-lucide name="mail" class="size-4" />
                        {{ __('account.magic.submit') }}
                    </x-button>
                    <x-button variant="secondary" :href="route('login', $intendedQuery)">{{ __('auth.magic.with_password') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
