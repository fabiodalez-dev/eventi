<x-layouts.app :meta="$meta">@include('carpool.nav')<div class="w-full max-w-3xl">
@include('carpool.partials.offer-context')
@include('carpool.partials.request-row')
@if(!$ride['is_driver'] && $ride['status'] === 'accepted' && $ride['can_withdraw'] && $ride['seats'] > 1)
<form method="post" action="{{ route('carpool.action','reduce') }}" class="my-6 flex flex-wrap items-end gap-3">@include('carpool.partials.key')<input type="hidden" name="request_id" value="{{ $ride['id'] }}"><label>{{ __('carpool.seats') }}<input required type="number" name="seats" min="1" max="{{ $ride['seats']-1 }}" value="{{ $ride['seats']-1 }}" class="ml-3 min-h-12 w-24 border border-line bg-canvas px-3"></label><x-button variant="secondary" type="submit">{{ __('carpool.reduce') }}</x-button></form>
@endif
@include('carpool.partials.review-form')
@if($ride['can_feedback'])<details class="my-8 border-t border-line py-5"><summary class="min-h-12 cursor-pointer font-semibold">{{ __('carpool.feedback') }}</summary><p class="my-3 text-sm text-ink-muted">{{ __('carpool.feedback_help') }}</p><form method="post" action="{{ route('carpool.discovery','feedback') }}" class="max-w-xl space-y-4">@include('carpool.partials.key')<input type="hidden" name="request_id" value="{{ $ride['id'] }}"><label class="block">{{ __('carpool.feedback') }}<select name="kind" class="mt-2 min-h-12 w-full border border-line bg-canvas px-3">@foreach(\App\Enums\RideFeedbackKind::cases() as $kind)<option value="{{ $kind->value }}">{{ $kind->label() }}</option>@endforeach</select></label><label class="block">{{ __('carpool.details') }}<textarea name="body" maxlength="1000" class="mt-2 w-full border border-line bg-canvas p-3"></textarea></label><x-button type="submit">{{ __('carpool.save') }}</x-button></form></details>@endif
@include('carpool.partials.report')
</div>
</x-layouts.app>
