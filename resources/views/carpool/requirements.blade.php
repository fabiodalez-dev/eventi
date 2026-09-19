<x-layouts.app :meta="$meta">@include('carpool.nav')<div class="w-full max-w-3xl">
<h1 class="text-hero">{{ __('carpool.requirements') }}</h1>
@if(!$access['eligible']) @include('carpool.partials.gate', ['hideHeading' => true])
@else
<div class="my-8 space-y-5"><p>{{ __('carpool.verified') }}</p><p>{{ __('carpool.adult_status') }} · {{ \Carbon\CarbonImmutable::parse($access['adult_declared_at'])->format('d/m/Y') }}</p><x-button :href="route('carpool.index')">{{ __('carpool.mine') }}</x-button></div>
<form method="post" action="{{ route('carpool.action', 'preferences') }}" class="my-6 space-y-4">@include('carpool.partials.key')<input type="hidden" name="push_enabled" value="0"><label class="flex min-h-12 items-center gap-3"><input type="checkbox" name="push_enabled" value="1" @checked($access['push_enabled']) class="size-5">{{ __('carpool.push') }}</label><x-button type="submit" variant="secondary">{{ __('carpool.save') }}</x-button></form>
<details class="border-t border-line py-6"><summary class="min-h-12 cursor-pointer">{{ __('carpool.adult_revoke') }}</summary><p class="my-3 text-sm text-ink-muted">{{ __('carpool.adult_revoke_help') }}</p><form method="post" action="{{ route('carpool.action', 'revoke-adult') }}">@include('carpool.partials.key')<label class="my-4 flex gap-3"><input required type="checkbox" name="confirm" value="1">{{ __('carpool.adult_revoke') }}</label><x-button type="submit" variant="secondary">{{ __('carpool.save') }}</x-button></form></details>
@endif
<a href="{{ route('carpool.terms') }}" class="inline-flex min-h-12 items-center underline">{{ __('carpool.terms') }}</a>
</div></x-layouts.app>
