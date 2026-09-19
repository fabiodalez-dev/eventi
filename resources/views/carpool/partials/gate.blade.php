@php($rideReturn = $returnTo ?? url()->current())
<div class="max-w-xl space-y-5 py-8">
    @unless($hideHeading ?? false)<h2 class="text-section">{{ __('carpool.requirements') }}</h2>@endunless
    <p class="text-ink-muted">{{ __('carpool.onboarding') }}</p>
    @switch($access['reason'])
        @case('login') <x-button :href="route('login', ['intended' => $rideReturn])" data-carpool-intent>{{ __('carpool.login') }}</x-button> @break
        @case('email') <x-button :href="route('verification.notice', ['intended' => $rideReturn])" data-carpool-intent>{{ __('carpool.email') }}</x-button> @break
        @case('whatsapp') <x-button :href="route('community.whatsapp', ['intended' => $rideReturn])" data-carpool-intent>{{ __('carpool.whatsapp') }}</x-button> @break
        @case('suspended') <p>{{ __('carpool.suspended') }}</p><x-button :href="route('carpool.cases')">{{ __('carpool.support') }}</x-button> @break
        @default
        <form method="post" action="{{ route('carpool.action', 'declare') }}" class="space-y-5">
            @include('carpool.partials.key')
            <input type="hidden" name="return_to" value="{{ preg_match('~^/passaggi/date/[1-9][0-9]*(/offri)?$~D', parse_url($rideReturn, PHP_URL_PATH) ?? '') ? parse_url($rideReturn, PHP_URL_PATH) : '' }}">
            <input type="hidden" name="version" value="{{ $access['terms_version'] }}">
            <label class="flex min-h-12 items-start gap-3"><input required type="checkbox" name="adult" value="1" class="mt-1.5 size-5 shrink-0"><span>{{ __('carpool.adult') }}</span></label>
            <label class="flex min-h-12 items-start gap-3"><input required type="checkbox" name="terms" value="1" class="mt-1.5 size-5 shrink-0"><span>{{ __('carpool.terms_accept') }}. <a href="{{ route('carpool.terms') }}" target="_blank" rel="noopener" class="underline">{{ __('carpool.terms') }}</a></span></label>
            <x-button type="submit">{{ __('carpool.enable') }}</x-button>
        </form>
    @endswitch
</div>
