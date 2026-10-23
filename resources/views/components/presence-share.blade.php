{{-- «Condividi “sono qui”» sulla pagina della singola data, solo il giorno stesso. --}}
@props(['occurrence'])
@php
    $start = $occurrence->starts_at;
    $end = $occurrence->effective_ends_at ?? $occurrence->ends_at ?? $start->copy()->addHours(3);
    $disponibile = $start->lessThanOrEqualTo(now()->addHours(\App\Http\Controllers\Web\PresenceController::WINDOW_HOURS)) && $end->isFuture();
@endphp
@if ($disponibile)
    @auth
        @php $attiva = \App\Models\PresenceShare::query()->where('user_id', auth()->id())->where('occurrence_id', $occurrence->getKey())->active()->latest('id')->first(); @endphp
        <section class="flex flex-col gap-3 border-2 border-line bg-canvas p-4" aria-labelledby="sono-qui">
            <h2 id="sono-qui" class="font-display text-lg font-extrabold uppercase">{{ __('presence.title') }}</h2>
            <p class="text-sm text-ink-muted">{{ __('presence.hint') }}</p>
            @if ($attiva)
                <div data-ticket-share class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-2">
                        <input type="text" readonly value="{{ $attiva->url() }}" class="min-h-12 min-w-0 flex-1 border border-line bg-surface px-3 text-sm" aria-label="{{ __('presence.title') }}" data-share-url>
                        <x-button type="button" variant="secondary" data-share-copy data-copied="{{ __('ticketing.share.copied') }}">{{ __('ticketing.share.copy') }}</x-button>
                        <x-button type="button" variant="secondary" data-share-native data-share-title="{{ __('presence.title') }}" hidden>{{ __('ticketing.share.native') }}</x-button>
                    </div>
                    <form method="POST" action="{{ route('presence.destroy', $attiva->token) }}">@csrf @method('DELETE')<button type="submit" class="inline-flex min-h-12 items-center text-sm font-semibold underline">{{ __('presence.revoke') }}</button></form>
                </div>
            @else
                <form method="POST" action="{{ route('presence.store', $occurrence->getKey()) }}" class="grid gap-3">
                    @csrf
                    <x-field name="display_name" :label="__('presence.display_name')" :value="auth()->user()->first_name" maxlength="60" />
                    <x-field name="note" :label="__('presence.note')" :hint="__('presence.note_hint')" maxlength="200" />
                    <x-button type="submit" class="justify-self-start min-h-12">{{ __('presence.create') }}</x-button>
                </form>
            @endif
        </section>
    @endauth
@endif
