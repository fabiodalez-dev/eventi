@props(['occurrence'])
@if(config('carpool.enabled'))
@php($rideAccess = app(\App\Services\Carpool\CarpoolAccess::class)->state(auth()->user()))
<section class="mt-6 border-t border-line pt-5" aria-labelledby="ride-title-{{ $occurrence->id }}">
    <h3 id="ride-title-{{ $occurrence->id }}" class="font-display text-xl font-bold">{{ __('carpool.title') }}</h3>
    <p class="mt-2 text-sm text-ink-muted">{{ __('carpool.subtitle') }}</p>
    <div class="mt-4 flex flex-wrap gap-3">
        <x-button variant="secondary" :href="route('carpool.dates',$occurrence)" :data-carpool-gate="$rideAccess['eligible'] ? null : 'ride-gate-'.$occurrence->id">{{ __('carpool.seek') }}</x-button>
        <x-button variant="secondary" :href="route('carpool.create',$occurrence)" :data-carpool-gate="$rideAccess['eligible'] ? null : 'ride-gate-'.$occurrence->id">{{ __('carpool.offer') }}</x-button>
    </div>
    @if(!$rideAccess['eligible'])
    <dialog id="ride-gate-{{ $occurrence->id }}" class="fixed inset-0 m-auto max-h-[90dvh] w-[min(92vw,36rem)] overflow-y-auto border border-line bg-canvas p-6 text-ink backdrop:bg-black/50">
        <form method="dialog" class="flex justify-end"><button type="submit" class="min-h-12 px-3" aria-label="{{ __('common.actions.close') }}">×</button></form>
        @include('carpool.partials.gate', ['access' => $rideAccess, 'returnTo' => route('carpool.dates', $occurrence)])
    </dialog>
    @endif
</section>
@endif
