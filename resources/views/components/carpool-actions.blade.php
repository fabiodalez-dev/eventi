@props(['occurrence'])
@if(config('carpool.enabled'))
@php($rideAccess = app(\App\Services\Carpool\CarpoolAccess::class)->state(auth()->user()))
<aside class="border-2 border-line bg-surface-sunken p-4 sm:p-5" aria-labelledby="ride-title-{{ $occurrence->id }}">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
        <h3 id="ride-title-{{ $occurrence->id }}" class="font-display text-xl font-bold">{{ __('carpool.title') }}</h3>
        <p class="text-sm text-ink-muted">{{ __('carpool.subtitle') }}</p>
    </div>
    <div class="mt-4 grid gap-2 sm:grid-cols-2">
        @if ($rideAccess['eligible'])
            <form action="{{ route('carpool.dates', $occurrence) }}" method="GET">
                <button type="submit" class="ui-action flex min-h-12 w-full items-center justify-center border-2 border-line bg-surface px-4 py-2 text-center font-display text-sm font-extrabold leading-tight text-ink transition hover:border-accent hover:bg-canvas active:bg-ink/15">
                    {{ __('carpool.seek') }}
                </button>
            </form>
            <form action="{{ route('carpool.create', $occurrence) }}" method="GET">
                <button type="submit" class="ui-action flex min-h-12 w-full items-center justify-center bg-brand px-4 py-2 text-center font-display text-sm font-extrabold leading-tight text-on-brand transition hover:bg-brand-strong active:bg-brand-strong">
                    {{ __('carpool.offer') }}
                </button>
            </form>
        @else
            <button type="button" class="ui-action flex min-h-12 w-full items-center justify-center border-2 border-line bg-surface px-4 py-2 text-center font-display text-sm font-extrabold leading-tight text-ink transition hover:border-accent hover:bg-canvas active:bg-ink/15" data-carpool-gate="ride-gate-{{ $occurrence->id }}" data-carpool-destination="{{ route('carpool.dates', $occurrence) }}" aria-haspopup="dialog">
                {{ __('carpool.seek') }}
            </button>
            <button type="button" class="ui-action flex min-h-12 w-full items-center justify-center bg-brand px-4 py-2 text-center font-display text-sm font-extrabold leading-tight text-on-brand transition hover:bg-brand-strong active:bg-brand-strong" data-carpool-gate="ride-gate-{{ $occurrence->id }}" data-carpool-destination="{{ route('carpool.create', $occurrence) }}" aria-haspopup="dialog">
                {{ __('carpool.offer') }}
            </button>
        @endif
    </div>
    @if(!$rideAccess['eligible'])
    <dialog id="ride-gate-{{ $occurrence->id }}" class="fixed inset-0 m-auto max-h-[90dvh] w-[min(92vw,36rem)] overflow-y-auto border border-line bg-canvas p-6 text-ink backdrop:bg-black/50">
        <form method="dialog" class="flex justify-end"><button type="submit" class="ui-action flex min-h-12 min-w-12 items-center justify-center border-2 border-line bg-surface px-3 text-ink transition hover:border-accent hover:bg-canvas" aria-label="{{ __('common.actions.close') }}">×</button></form>
        @include('carpool.partials.gate', ['access' => $rideAccess, 'returnTo' => route('carpool.dates', $occurrence)])
    </dialog>
    @endif
</aside>
@endif
