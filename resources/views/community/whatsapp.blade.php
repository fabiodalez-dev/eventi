{{--
    La verifica del numero WhatsApp. Prima che cosa serve e che fine fa il
    numero, poi il passo da fare: il numero, oppure il codice appena arrivato,
    oppure — a verifica fatta — lo stato e la revoca, chiusa finché non serve.
--}}
@php
    $control = 'min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-base text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none';
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="__('community.whatsapp.lead')"
        :back="route('account.profile')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('settings')"
        :tabs-label="__('community.nav')"
    >
        @if ($exempt)
            <x-notice>{{ __('community.whatsapp.exempt') }}</x-notice>
        @endif

        @if ($verified)
            <x-notice tone="success" :title="__('community.whatsapp.active')">
                <div><x-button size="sm" :href="route('community.settings')">{{ __('community.settings') }}</x-button></div>
            </x-notice>
        @elseif (! $available)
            <x-notice>{{ __('community.whatsapp.unavailable') }}</x-notice>
        @else
            @if ($challenge_id)
                <x-card as="form" data-community-form method="post" action="{{ route('community.whatsapp.confirm') }}" :title="__('community.whatsapp.code_title')" :description="__('community.whatsapp.sent')">
                    @csrf
                    <input type="hidden" name="challenge_id" value="{{ $challenge_id }}">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-semibold text-ink" for="code">{{ __('community.whatsapp.code') }}</label>
                        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required class="{{ $control }} text-xl tracking-[0.3em] tabular-nums">
                    </div>
                    <x-form-actions><x-button type="submit">{{ __('community.whatsapp.confirm') }}</x-button></x-form-actions>
                </x-card>
            @endif
            <x-card as="form" data-community-form method="post" action="{{ route('community.whatsapp.send') }}" :title="__($challenge_id ? 'community.whatsapp.resend_title' : 'community.whatsapp.phone_title')" :tone="$challenge_id ? 'muted' : 'default'">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-semibold text-ink" for="phone">{{ __('community.whatsapp.phone') }}</label>
                    <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}" placeholder="{{ __('community.whatsapp.phone_hint') }}" required maxlength="30" class="{{ $control }}">
                </div>
                <x-form-actions><x-button type="submit" :variant="$challenge_id ? 'secondary' : 'primary'">{{ __('community.whatsapp.send') }}</x-button></x-form-actions>
            </x-card>
        @endif

        <x-card tone="muted" :title="__('community.whatsapp.privacy_title')">
            <p class="m-0 text-sm leading-relaxed text-ink-muted">{{ __('community.whatsapp.privacy') }}</p>
            <p class="m-0 text-sm leading-relaxed text-ink-muted">{{ __('community.whatsapp.badge_help') }}</p>
        </x-card>

        @if ($verified)
            <x-card tone="alert" :title="__('community.whatsapp.revoke')" :description="__('community.whatsapp.revoke_help')">
                <form data-community-form method="post" action="{{ route('community.whatsapp.revoke') }}">
                    @csrf @method('DELETE')
                    <x-button type="submit" variant="danger">{{ __('community.whatsapp.revoke') }}</x-button>
                </form>
            </x-card>
        @else
            <form data-community-form method="post" action="{{ route('community.whatsapp.skip') }}">
                @csrf
                <x-button type="submit" variant="ghost">{{ __('community.whatsapp.later') }}</x-button>
            </form>
        @endif
    </x-service-page>
</x-layouts.app>
