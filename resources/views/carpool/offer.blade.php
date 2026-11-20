{{--
    La pagina di un passaggio. Per chi guida: «Il tuo passaggio» con stato e
    numeri, le azioni, «Richieste da gestire» con Accetta/Rifiuta, i
    passeggeri confermati, le richieste chiuse, la modifica e il modello. Per
    chi cerca: il conducente in evidenza, i dettagli e la richiesta dei posti
    (o la propria richiesta, se c'è già).
--}}
@php
    $own = $offer['is_own'];
    $pendingRequests = collect($requests)->where('status', 'pending')->values();
    $acceptedRequests = collect($requests)->where('status', 'accepted')->values();
    $closedRequests = collect($requests)->whereNotIn('status', ['pending', 'accepted'])->values();
    $myRequest = $own ? null : collect($requests)->first();
    // Una richiesta chiusa non toglie il modulo a chi può chiedere di nuovo (dopo un ritiro, o dopo
    // che il conducente ha cambiato le condizioni): resta la scheda solo se è attiva o se un'altra
    // richiesta non passerebbe (rifiutata, troppi ritiri).
    if ($myRequest !== null && ! in_array($myRequest['status'], ['pending', 'accepted'], true) && ($can_request_again ?? false)) {
        $myRequest = null;
    }
    $statusTone = ['open' => 'brand', 'draft' => 'soon', 'closed' => 'muted', 'cancelled' => 'alert', 'completed' => 'muted'][$offer['status']] ?? 'muted';
    $subtitle = $offer['departure_label'] ? __('carpool.web.zone_at', ['zone' => $offer['zone'], 'time' => $offer['departure_label']]) : $offer['event_title'];
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$own ? __('carpool.web.own_title') : __('carpool.web.offer_title', ['name' => $offer['driver']['name']])" :subtitle="$subtitle"
        :eyebrow="$offer['leg_label'].' · '.__('carpool.free')"
        :back="$own ? route('carpool.index') : route('carpool.dates', $offer['occurrence_id'])" :back-label="$own ? __('carpool.web.back_mine') : __('carpool.web.back_dates')"
        :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">

        @include('carpool.partials.event')

        @if ($own)
            <x-card :title="__('carpool.web.facts')" data-offer-summary>
                <x-slot:actions><x-badge :tone="$statusTone">{{ $offer['status_label'] }}</x-badge></x-slot:actions>
                <x-stat-list :label="__('carpool.web.facts')" :items="[
                    ['label' => __('carpool.web.offered_seats'), 'value' => $offer['capacity']],
                    ['label' => __('carpool.web.free_seats'), 'value' => $offer['available']],
                    ['label' => __('carpool.web.pending_requests'), 'value' => $pendingRequests->count()],
                ]" />
                <dl class="m-0 flex flex-col gap-3 text-sm">
                    <div class="flex flex-col gap-0.5"><dt class="font-semibold text-ink">{{ __('carpool.accessibility') }}</dt><dd class="m-0 text-ink-muted">{{ $offer['accessibility_label'] }}@if ($offer['accessibility_note']) · {{ $offer['accessibility_note'] }}@endif</dd></div>
                    @if (count($offer['stops']))<div class="flex flex-col gap-0.5"><dt class="font-semibold text-ink">{{ __('carpool.web.route') }}</dt><dd class="m-0 text-ink-muted">{{ implode(' → ', $offer['stops']) }}</dd></div>@endif
                    @if ($offer['note'])<div class="flex flex-col gap-0.5"><dt class="font-semibold text-ink">{{ __('carpool.web.note_title') }}</dt><dd class="m-0 whitespace-pre-line break-words text-ink-muted">{{ $offer['note'] }}</dd></div>@endif
                </dl>
                @if (in_array($offer['status'], ['open', 'closed', 'draft'], true))
                    <x-slot:footer>
                        @if ($offer['status'] === 'draft')
                            <form method="post" action="{{ route('carpool.action', 'publish') }}" class="flex w-full flex-col gap-3">
                                @include('carpool.partials.key')
                                <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                                <label class="flex items-start gap-3 text-sm text-ink"><input required type="checkbox" name="driver_declaration" value="1" class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.driver_declaration') }}</span></label>
                                <div><x-button type="submit">{{ __('carpool.publish') }}</x-button></div>
                            </form>
                        @else
                            <form method="post" action="{{ route('carpool.action', $offer['status'] === 'open' ? 'close' : 'reopen') }}">
                                @include('carpool.partials.key')
                                <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                                <x-button type="submit" variant="secondary">{{ __('carpool.'.($offer['status'] === 'open' ? 'close' : 'reopen')) }}</x-button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('carpool.action', 'cancel') }}" data-confirm="{{ __('carpool.confirm_cancel') }}">
                            @include('carpool.partials.key')
                            <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                            <x-button type="submit" variant="danger">{{ __('carpool.cancel') }}</x-button>
                        </form>
                    </x-slot:footer>
                @endif
            </x-card>

            @if ($pendingRequests->isNotEmpty() || in_array($offer['status'], ['open', 'closed', 'draft'], true))
                <x-card padding="none" :title="__('carpool.web.requests_title')" :description="__('carpool.web.requests_description')" id="richieste" data-offer-requests>
                    @forelse ($pendingRequests as $item)
                        @include('carpool.partials.request-row')
                    @empty
                        <p class="svc-row px-5 py-4 text-sm text-ink-muted sm:px-6">{{ __('carpool.web.requests_empty') }}</p>
                    @endforelse
                </x-card>
            @endif

            @if ($acceptedRequests->isNotEmpty())
                <x-card padding="none" :title="__('carpool.web.confirmed_title')" data-offer-confirmed>
                    @foreach ($acceptedRequests as $item)
                        @include('carpool.partials.request-row')
                    @endforeach
                </x-card>
            @endif

            @if ($closedRequests->isNotEmpty())
                <x-card padding="none" tone="muted" :title="__('carpool.web.history_title')" data-offer-history>
                    @foreach ($closedRequests as $item)
                        @include('carpool.partials.request-row')
                    @endforeach
                </x-card>
            @endif
            @include('carpool.partials.pages')

            @if (in_array($offer['status'], ['draft', 'open', 'closed'], true))
                <x-card padding="sm" data-offer-update>
                    <details class="group" @if ($errors->hasAny(['zone', 'departure_at', 'capacity', 'accessibility', 'accessibility_note', 'note', 'stops.0', 'stops.1', 'stops.2'])) open @endif>
                        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3">
                            <span class="flex flex-col"><span class="font-semibold text-ink">{{ __('carpool.web.update_title') }}</span><span class="text-sm text-ink-muted">{{ __('carpool.web.update_description') }}</span></span>
                            <x-lucide name="chevron-down" class="size-5 shrink-0 text-ink-muted transition group-open:rotate-180" />
                        </summary>
                        <form method="post" action="{{ route('carpool.action', 'update') }}" class="mt-4 flex flex-col gap-4">
                            @include('carpool.partials.key')
                            <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                            <input type="hidden" name="revision" value="{{ $offer['revision'] }}">
                            @include('carpool.partials.offer-fields', ['section' => null, 'values' => $offer, 'fixedLeg' => $offer['leg'], 'prefix' => 'modifica'])
                            <div><x-button type="submit">{{ __('carpool.update') }}</x-button></div>
                        </form>
                    </details>
                </x-card>
            @endif

            <x-card padding="sm" data-offer-template>
                <details class="group">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3">
                        <span class="flex flex-col"><span class="font-semibold text-ink">{{ __('carpool.template') }}</span><span class="text-sm text-ink-muted">{{ __('carpool.web.template_description') }}</span></span>
                        <x-lucide name="chevron-down" class="size-5 shrink-0 text-ink-muted transition group-open:rotate-180" />
                    </summary>
                    <form method="post" action="{{ route('carpool.discovery', 'template') }}" class="mt-4 flex flex-col gap-4">
                        @include('carpool.partials.key')
                        <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                        @include('carpool.partials.field', ['name' => 'name', 'id' => 'modello-nome', 'label' => __('carpool.template_name'), 'required' => true, 'maxlength' => 80, 'value' => old('name')])
                        <div><x-button type="submit" variant="secondary">{{ __('carpool.save') }}</x-button></div>
                    </form>
                </details>
            </x-card>
        @else
            <x-card :title="__('carpool.web.driver_title')" data-offer-driver>
                <div class="flex min-w-0 items-center gap-4">
                    <x-avatar :name="$offer['driver']['name']" size="lg" />
                    <div class="flex min-w-0 flex-col gap-1">
                        <p class="font-display text-xl leading-tight font-extrabold text-ink">{{ $offer['driver']['name'] }}</p>
                        @if ($offer['driver']['verified'])<p class="flex items-center gap-1.5 text-sm text-ink-muted"><x-lucide name="check" class="size-4 text-accent" />{{ __('carpool.verified') }}</p>@endif
                        @if (! empty($offer['reviews']))
                            <p class="text-sm text-ink-muted">
                                @if ($offer['reviews']['count']){{ __('carpool.reviews.summary', ['average' => number_format($offer['reviews']['average'], 1, ',', ''), 'count' => $offer['reviews']['count']]) }} · @endif
                                <a class="text-brand underline" href="{{ $offer['reviews_url'] }}">{{ __('carpool.reviews.open') }}</a>
                            </p>
                        @endif
                    </div>
                </div>
                <x-stat-list :items="[['label' => __('carpool.web.free_seats'), 'value' => $offer['available']], ['label' => __('carpool.web.offered_seats'), 'value' => $offer['capacity']]]" />
                <dl class="m-0 flex flex-col gap-3 text-sm">
                    <div class="flex flex-col gap-0.5"><dt class="font-semibold text-ink">{{ __('carpool.accessibility') }}</dt><dd class="m-0 text-ink-muted">{{ $offer['accessibility_label'] }}@if ($offer['accessibility_note']) · {{ $offer['accessibility_note'] }}@endif</dd><dd class="m-0 text-xs text-ink-subtle">{{ __('carpool.accessibility_help') }}</dd></div>
                    @if (count($offer['stops']))<div class="flex flex-col gap-0.5"><dt class="font-semibold text-ink">{{ __('carpool.web.route') }}</dt><dd class="m-0 text-ink-muted">{{ implode(' → ', $offer['stops']) }}</dd></div>@endif
                    @if ($offer['note'])<div class="flex flex-col gap-0.5"><dt class="font-semibold text-ink">{{ __('carpool.web.note_title') }}</dt><dd class="m-0 whitespace-pre-line break-words text-ink-muted">{{ $offer['note'] }}</dd></div>@endif
                </dl>
            </x-card>

            @if (! $access['eligible'])
                <x-card :title="__('carpool.web.blocked_title')">
                    @include('carpool.partials.gate', ['hideHeading' => true])
                </x-card>
            @elseif ($myRequest)
                <x-card :title="__('carpool.web.your_request')" data-offer-own-request>
                    <x-slot:actions><x-badge :tone="$myRequest['status'] === 'accepted' ? 'brand' : ($myRequest['status'] === 'pending' ? 'soon' : 'muted')">{{ $myRequest['status_label'] }}</x-badge></x-slot:actions>
                    <p class="text-sm text-ink-muted">{{ trans_choice('carpool.web.seats_requested', $myRequest['seats'], ['count' => $myRequest['seats']]) }}</p>
                    <x-form-actions>
                        <x-button :href="$myRequest['url']">{{ __('carpool.web.open_request') }}</x-button>
                        @if ($myRequest['chat_id'])<x-button variant="secondary" :href="route('carpool.chat', $myRequest['chat_id'])">{{ __('carpool.web.write_to', ['name' => $offer['driver']['name']]) }}</x-button>@endif
                    </x-form-actions>
                </x-card>
            @elseif ($offer['demo'])
                <x-notice :title="__('carpool.demo_label')">{{ __('carpool.demo_notice') }}</x-notice>
            @elseif ($offer['can_request'])
                <x-card :title="__('carpool.web.request_form_title')" :description="__('carpool.web.request_form_description')" data-offer-request-form>
                    <form method="post" action="{{ route('carpool.action', 'request') }}" class="flex flex-col gap-4" x-data="{ seats: {{ (int) old('seats', 1) }} }">
                        @include('carpool.partials.key')
                        <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                        <input type="hidden" name="revision" value="{{ $offer['revision'] }}">
                        <div class="flex min-w-0 flex-col gap-1.5">
                            <label for="richiesta-posti" class="text-sm font-semibold text-ink">{{ __('carpool.seats') }}</label>
                            <p id="richiesta-posti-aiuto" class="text-xs text-ink-subtle">{{ __('carpool.group_help') }}</p>
                            <input id="richiesta-posti" required type="number" name="seats" min="1" max="{{ max(1, $offer['available']) }}" value="{{ old('seats', 1) }}" x-model="seats"
                                aria-describedby="richiesta-posti-aiuto @error('seats') richiesta-posti-errore @enderror" @error('seats') aria-invalid="true" @enderror
                                @class(['min-h-12 w-full border bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-brand focus:outline-none', 'border-live' => $errors->has('seats'), 'border-line' => ! $errors->has('seats')])>
                            @error('seats')<p id="richiesta-posti-errore" class="text-xs font-semibold text-live">{{ $message }}</p>@enderror
                        </div>
                        @if (count($offer['stops']))
                            @include('carpool.partials.field', ['name' => 'stop_index', 'id' => 'richiesta-tappa', 'label' => __('carpool.stop_select'), 'type' => 'select',
                                'options' => ['' => __('carpool.origin')] + $offer['stops'], 'value' => old('stop_index')])
                        @endif
                        <label x-show="Number(seats) > 1" class="flex items-start gap-3 text-sm text-ink"><input type="checkbox" name="companions_adult" value="1" :required="Number(seats) > 1" class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.companions') }}</span></label>
                        @include('carpool.partials.field', ['name' => 'note', 'id' => 'richiesta-nota', 'label' => __('carpool.note'), 'type' => 'textarea', 'maxlength' => 500, 'value' => old('note')])
                        <p class="text-sm text-ink-muted">{{ __('carpool.request_help') }}</p>
                        <div><x-button type="submit">{{ __('carpool.request') }}</x-button></div>
                    </form>
                </x-card>
            @endif
        @endif

        @include('carpool.partials.report')
    </x-service-page>
</x-layouts.app>
