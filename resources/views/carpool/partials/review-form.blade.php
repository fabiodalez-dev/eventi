{{--
    Conferma del viaggio e recensione del conducente, facoltative, dopo un
    passaggio accettato. Prima si conferma di aver viaggiato, poi si vota.
--}}
@if ($ride['can_review'] || $ride['review'])
    <x-card :title="__('carpool.reviews.title')" :description="__('carpool.reviews.optional')" data-carpool-review>
        @if (! $ride['passenger_confirmed_at'])
            <form method="post" action="{{ route('carpool.review.action', [$ride['id'], 'confirm']) }}" class="flex flex-col gap-4">
                @include('carpool.partials.key')
                <label class="flex min-h-12 items-start gap-3 text-sm text-ink"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.reviews.confirm_label') }}</span></label>
                <div><x-button type="submit">{{ __('carpool.reviews.confirm') }}</x-button></div>
            </form>
        @else
            <x-notice tone="success" :title="__('carpool.reviews.confirmed')" />
            @if (($ride['review']['status'] ?? null) === 'hidden')
                <x-notice tone="alert">{{ __('carpool.reviews.hidden') }}</x-notice>
            @endif
            @if ($ride['can_review'])
                <form method="post" action="{{ route('carpool.review.action', [$ride['id'], 'save']) }}" class="flex flex-col gap-4">
                    @include('carpool.partials.key')
                    <input type="hidden" name="revision" value="{{ $ride['review_revision'] }}">
                    @include('carpool.partials.field', ['name' => 'rating', 'id' => 'recensione-voto', 'label' => __('carpool.reviews.rating'), 'type' => 'select', 'required' => true,
                        'options' => ['' => '—'] + collect(range(1, 5))->mapWithKeys(fn ($rating) => [$rating => __('carpool.web.reviews_rating', ['rating' => $rating])])->all(), 'value' => old('rating', $ride['review']['rating'] ?? '')])
                    @include('carpool.partials.field', ['name' => 'body', 'id' => 'recensione-testo', 'label' => __('carpool.reviews.body'), 'type' => 'textarea', 'maxlength' => 1000, 'hint' => __('carpool.reviews.verified_only'), 'value' => old('body', $ride['review']['body'] ?? '')])
                    <div><x-button type="submit">{{ __('carpool.reviews.save') }}</x-button></div>
                </form>
            @endif
            @if ($ride['review'])
                <form method="post" action="{{ route('carpool.review.action', [$ride['id'], 'remove']) }}" data-confirm="{{ __('carpool.reviews.remove_confirm') }}">
                    @include('carpool.partials.key')
                    <input type="hidden" name="revision" value="{{ $ride['review_revision'] }}">
                    <x-button type="submit" variant="ghost">{{ __('carpool.reviews.remove') }}</x-button>
                </form>
            @endif
        @endif
    </x-card>
@endif
