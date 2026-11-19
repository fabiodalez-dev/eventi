{{--
    Cosa manca per usare i passaggi, con il pulsante per completarlo. Sta
    dentro una card nelle pagine dei passaggi e nella finestra della scheda
    evento. `returnTo` è la pagina a cui tornare dopo l'accesso o le verifiche.
--}}
@php($rideReturn = $returnTo ?? url()->current())
<div class="flex min-w-0 flex-col gap-4" data-carpool-gate-body>
    @unless ($hideHeading ?? false)
        <h2 class="font-display text-lg leading-tight font-extrabold text-ink">{{ __('carpool.requirements') }}</h2>
    @endunless
    <p class="text-sm text-ink-muted">{{ __('carpool.onboarding') }}</p>
    @switch($access['reason'])
        @case('login')
            <div><x-button :href="route('login', ['intended' => $rideReturn])" data-carpool-intent>{{ __('carpool.login') }}</x-button></div>
            @break
        @case('email')
            <div><x-button :href="route('verification.notice', ['intended' => $rideReturn])" data-carpool-intent>{{ __('carpool.email') }}</x-button></div>
            @break
        @case('whatsapp')
            <div><x-button :href="route('community.whatsapp', ['intended' => $rideReturn])" data-carpool-intent>{{ __('carpool.whatsapp') }}</x-button></div>
            @break
        @case('suspended')
            <p class="text-sm text-ink">{{ __('carpool.suspended') }}</p>
            <div><x-button variant="secondary" :href="route('carpool.cases')">{{ __('carpool.support') }}</x-button></div>
            @break
        @default
            <form method="post" action="{{ route('carpool.action', 'declare') }}" class="flex flex-col gap-4">
                @include('carpool.partials.key')
                <input type="hidden" name="return_to" value="{{ preg_match('~^/passaggi/date/[1-9][0-9]*(/offri)?$~D', parse_url($rideReturn, PHP_URL_PATH) ?? '') ? parse_url($rideReturn, PHP_URL_PATH) : '' }}">
                <input type="hidden" name="version" value="{{ $access['terms_version'] }}">
                <label class="flex min-h-12 items-start gap-3 text-sm text-ink"><input required type="checkbox" name="adult" value="1" class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.adult') }}</span></label>
                <label class="flex min-h-12 items-start gap-3 text-sm text-ink"><input required type="checkbox" name="terms" value="1" class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.terms_accept') }}. <a href="{{ route('carpool.terms') }}" target="_blank" rel="noopener" class="text-brand underline">{{ __('carpool.terms') }}</a></span></label>
                <div><x-button type="submit">{{ __('carpool.enable') }}</x-button></div>
            </form>
    @endswitch
</div>
