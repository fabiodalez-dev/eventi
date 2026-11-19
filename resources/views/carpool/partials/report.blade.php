{{--
    «Segnala un problema»: chiuso per difetto, si apre sul posto. Va
    all'assistenza con il riferimento all'offerta, alla richiesta o alla
    recensione della pagina. Con `bare` niente card intorno: serve dentro una
    card che c'è già (la recensione).
--}}
@php($bare ??= false)
@unless ($bare)<x-card tone="muted" padding="sm" data-carpool-report>@endunless
    <details class="group" @if ($bare) data-carpool-report @endif>
        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 font-semibold text-ink">
            <span class="flex items-center gap-2"><x-lucide name="alert-circle" class="size-5 text-ink-muted" />{{ __('carpool.report') }}</span>
            <x-lucide name="chevron-down" class="size-5 text-ink-muted transition group-open:rotate-180" />
        </summary>
        <form action="{{ route('carpool.report') }}" method="post" class="mt-3 flex flex-col gap-4">
            @include('carpool.partials.key')
            @if (! empty($reviewId))<input type="hidden" name="review_id" value="{{ $reviewId }}">@endif
            @if (isset($offer) && empty($reviewId))<input type="hidden" name="offer_id" value="{{ $offer['id'] }}">@endif
            @if (isset($ride) && empty($reviewId))<input type="hidden" name="request_id" value="{{ $ride['id'] }}">@endif
            <p class="text-sm text-ink-muted">{{ __('carpool.web.report_description') }}</p>
            @include('carpool.partials.field', ['name' => 'reason', 'id' => 'segnala-motivo-'.($reviewId ?? 'pagina'), 'label' => __('carpool.reason'), 'type' => 'select', 'options' => __('carpool.reasons'), 'value' => old('reason')])
            @include('carpool.partials.field', ['name' => 'body', 'id' => 'segnala-testo-'.($reviewId ?? 'pagina'), 'label' => __('carpool.details'), 'type' => 'textarea', 'rows' => 4, 'required' => true, 'maxlength' => 2000, 'value' => old('body')])
            <div><x-button type="submit" variant="secondary">{{ __('carpool.send') }}</x-button></div>
        </form>
    </details>
@unless ($bare)</x-card>@endunless
