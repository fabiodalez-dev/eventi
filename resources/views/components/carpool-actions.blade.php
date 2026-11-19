{{--
    «Andiamo insieme» nella scheda di una data: Cerco passaggio e Offro
    passaggio. Chi non ha ancora i requisiti apre una finestra che spiega cosa
    manca, con il pulsante per completarlo e il ritorno a questa data.
--}}
@props(['occurrence'])
@if(config('carpool.enabled'))
@php($rideAccess = app(\App\Services\Carpool\CarpoolAccess::class)->state(auth()->user()))
<aside class="border-2 border-line bg-surface-sunken p-4 sm:p-5" aria-labelledby="ride-title-{{ $occurrence->id }}" data-carpool-actions>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
        <h3 id="ride-title-{{ $occurrence->id }}" class="font-display text-xl font-bold">{{ __('carpool.title') }}</h3>
        <p class="text-sm text-ink-muted">{{ __('carpool.subtitle') }}</p>
    </div>
    <div class="mt-4 flex flex-col gap-2 sm:flex-row [&>*]:flex-1">
        @if ($rideAccess['eligible'])
            <form action="{{ route('carpool.dates', $occurrence) }}" method="GET"><x-button type="submit" variant="secondary" class="w-full">{{ __('carpool.seek') }}</x-button></form>
            <form action="{{ route('carpool.create', $occurrence) }}" method="GET"><x-button type="submit" class="w-full">{{ __('carpool.offer') }}</x-button></form>
        @else
            <x-button variant="secondary" data-carpool-gate="ride-gate-{{ $occurrence->id }}" data-carpool-destination="{{ route('carpool.dates', $occurrence) }}" aria-haspopup="dialog">{{ __('carpool.seek') }}</x-button>
            <x-button data-carpool-gate="ride-gate-{{ $occurrence->id }}" data-carpool-destination="{{ route('carpool.create', $occurrence) }}" aria-haspopup="dialog">{{ __('carpool.offer') }}</x-button>
        @endif
    </div>
    @if(!$rideAccess['eligible'])
    <dialog id="ride-gate-{{ $occurrence->id }}" class="fixed inset-0 m-auto max-h-[90dvh] w-[min(92vw,36rem)] overflow-y-auto border-2 border-line bg-canvas p-6 text-ink backdrop:bg-black/50">
        <form method="dialog" class="flex justify-end"><x-button type="submit" variant="secondary" size="sm" aria-label="{{ __('common.actions.close') }}"><x-lucide name="x" class="size-5" /></x-button></form>
        @include('carpool.partials.gate', ['access' => $rideAccess, 'returnTo' => route('carpool.dates', $occurrence)])
    </dialog>
    @endif
</aside>
@endif
