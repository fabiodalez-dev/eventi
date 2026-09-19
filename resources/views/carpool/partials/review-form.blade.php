@if($ride['can_review'] || $ride['review'])
<section class="my-8 space-y-4 border-t border-line pt-6"><h2 class="text-section">{{ __('carpool.reviews.title') }}</h2><p class="text-ink-muted">{{ __('carpool.reviews.optional') }}</p>
@if(!$ride['passenger_confirmed_at'])
<form method="post" action="{{ route('carpool.review.action', [$ride['id'], 'confirm']) }}" class="space-y-4">@include('carpool.partials.key')<label class="flex min-h-12 items-center gap-3"><input type="checkbox" name="confirm" value="1" required>{{ __('carpool.reviews.confirm_label') }}</label><x-button type="submit">{{ __('carpool.reviews.confirm') }}</x-button></form>
@else
<p class="text-sm font-semibold">{{ __('carpool.reviews.confirmed') }}</p>
@if(($ride['review']['status'] ?? null) === 'hidden')<p>{{ __('carpool.reviews.hidden') }}</p>@endif
@if($ride['can_review'])<form method="post" action="{{ route('carpool.review.action', [$ride['id'], 'save']) }}" class="space-y-4">@include('carpool.partials.key')<input type="hidden" name="revision" value="{{ $ride['review_revision'] }}">
<label class="block font-semibold">{{ __('carpool.reviews.rating') }}<select name="rating" required class="mt-2 block min-h-12 w-full rounded-xl border border-line bg-canvas px-4"><option value="">—</option>@foreach(range(1,5) as $rating)<option value="{{ $rating }}" @selected(($ride['review']['rating'] ?? null) === $rating)>{{ $rating }}/5</option>@endforeach</select></label>
<label class="block font-semibold">{{ __('carpool.reviews.body') }}<textarea name="body" maxlength="1000" rows="3" class="mt-2 block w-full rounded-xl border border-line bg-canvas p-4">{{ $ride['review']['body'] ?? '' }}</textarea></label><p class="text-sm text-ink-muted">{{ __('carpool.reviews.verified_only') }}</p><x-button type="submit">{{ __('carpool.reviews.save') }}</x-button></form>@endif
@if($ride['review'])<form method="post" action="{{ route('carpool.review.action', [$ride['id'], 'remove']) }}" onsubmit="return confirm(@js(__('carpool.reviews.remove_confirm')))">@include('carpool.partials.key')<input type="hidden" name="revision" value="{{ $ride['review_revision'] }}"><x-button type="submit" variant="secondary">{{ __('carpool.reviews.remove') }}</x-button></form>@endif
@endif
</section>
@endif
