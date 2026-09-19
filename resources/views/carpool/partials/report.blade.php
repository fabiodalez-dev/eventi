<details class="mt-8 border-t border-line py-5"><summary class="min-h-12 cursor-pointer font-semibold">{{ __('carpool.report') }}</summary>
<form action="{{ route('carpool.report') }}" method="post" class="mt-4 max-w-xl space-y-4">
    @include('carpool.partials.key')
@if(!empty($reviewId))<input type="hidden" name="review_id" value="{{ $reviewId }}">@endif
    @if(isset($offer))<input type="hidden" name="offer_id" value="{{ $offer['id'] }}">@endif
    @if(isset($ride))<input type="hidden" name="request_id" value="{{ $ride['id'] }}">@endif
    <label class="block space-y-2"><span>{{ __('carpool.reason') }}</span><select name="reason" class="min-h-12 w-full border border-line bg-canvas px-4">@foreach(__('carpool.reasons') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
    <label class="block space-y-2"><span>{{ __('carpool.details') }}</span><textarea required name="body" minlength="5" maxlength="2000" rows="4" class="w-full border border-line bg-canvas p-4"></textarea></label>
    <x-button type="submit" variant="secondary">{{ __('carpool.send') }}</x-button>
</form></details>
