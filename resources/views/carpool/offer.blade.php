<x-layouts.app :meta="$meta">@include('carpool.nav')<div class="w-full max-w-3xl">
@include('carpool.partials.offer-context')
<div class="grid gap-10 lg:grid-cols-[1fr_20rem]">
<div class="space-y-5"><h2 class="text-section">{{ $offer['driver']['name'] }}</h2><p class="font-semibold">{{ __('carpool.available', ['count' => $offer['available']]) }}</p><p>{{ $offer['accessibility_label'] }}</p>@if($offer['accessibility_note'])<p>{{ $offer['accessibility_note'] }}</p>@endif<p class="text-sm text-ink-muted">{{ __('carpool.accessibility_help') }}</p>@if($offer['note'])<p class="whitespace-pre-line break-words">{{ $offer['note'] }}</p>@endif
@if(count($offer['stops']))<ol class="list-inside list-decimal space-y-2">@foreach($offer['stops'] as $stop)<li>{{ $stop }}</li>@endforeach</ol>@endif</div>
<div>
@if(!$access['eligible']) @include('carpool.partials.gate')
@elseif($offer['demo'] && !$offer['is_own'])
<p class="border border-line p-5 text-sm" role="note"><span class="block font-semibold">{{ __('carpool.demo_label') }}</span>{{ __('carpool.demo_notice') }}</p>
@elseif($offer['can_request'] && !count($requests))
<form method="post" action="{{ route('carpool.action', 'request') }}" class="space-y-5" x-data="{ seats: 1 }">@include('carpool.partials.key')<input type="hidden" name="offer_id" value="{{ $offer['id'] }}"><input type="hidden" name="revision" value="{{ $offer['revision'] }}">
<label class="block space-y-2"><span class="font-semibold">{{ __('carpool.seats') }}</span><input required type="number" name="seats" min="1" max="{{ $offer['available'] }}" value="1" x-model="seats" class="min-h-12 w-full border border-line bg-canvas px-4"></label><p class="text-sm text-ink-muted">{{ __('carpool.group_help') }}</p>
@if(count($offer['stops']))<label class="block space-y-2"><span>{{ __('carpool.stop_select') }}</span><select name="stop_index" class="min-h-12 w-full border border-line bg-canvas px-4"><option value="">{{ __('carpool.origin') }}</option>@foreach($offer['stops'] as $index => $stop)<option value="{{ $index }}">{{ $stop }}</option>@endforeach</select></label>@endif
<label x-show="Number(seats) > 1" class="flex items-start gap-3"><input type="checkbox" name="companions_adult" value="1" :required="Number(seats) > 1" class="mt-1.5 size-5 shrink-0"><span class="text-sm">{{ __('carpool.companions') }}</span></label>
<label class="block space-y-2"><span class="text-sm">{{ __('carpool.note') }}</span><textarea name="note" maxlength="500" rows="3" class="w-full border border-line bg-canvas p-3"></textarea></label><x-button type="submit">{{ __('carpool.request') }}</x-button><p class="text-sm text-ink-muted">{{ __('carpool.request_help') }}</p>
</form>
@endif
</div></div>
@if($offer['is_own'])
<div class="my-8 flex flex-wrap gap-3">
@if(in_array($offer['status'], ['open','closed','draft']))
@if($offer['status'] === 'draft')<form method="post" action="{{ route('carpool.action','publish') }}">@include('carpool.partials.key')<input type="hidden" name="offer_id" value="{{ $offer['id'] }}"><label class="mb-4 flex gap-3"><input required type="checkbox" name="driver_declaration" value="1"><span class="text-sm">{{ __('carpool.driver_declaration') }}</span></label><x-button type="submit">{{ __('carpool.publish') }}</x-button></form>
@else<form method="post" action="{{ route('carpool.action', $offer['status'] === 'open' ? 'close' : 'reopen') }}">@include('carpool.partials.key')<input type="hidden" name="offer_id" value="{{ $offer['id'] }}"><x-button type="submit" variant="secondary">{{ __('carpool.'.($offer['status'] === 'open' ? 'close' : 'reopen')) }}</x-button></form>@endif
<form method="post" action="{{ route('carpool.action','cancel') }}" data-confirm="{{ __('carpool.confirm_cancel') }}">@include('carpool.partials.key')<input type="hidden" name="offer_id" value="{{ $offer['id'] }}"><x-button type="submit" variant="secondary">{{ __('carpool.cancel') }}</x-button></form>
@endif
</div>
<h2 class="text-section">{{ __('carpool.pending') }}</h2>
@endif
@foreach($requests as $ride) @include('carpool.partials.request-row') @endforeach
@if($offer['is_own'])
@if(in_array($offer['status'], ['draft','open','closed']))<details class="mt-8 border-t border-line py-5"><summary class="min-h-12 cursor-pointer font-semibold">{{ __('carpool.update') }}</summary><form method="post" action="{{ route('carpool.action','update') }}" class="mt-5 max-w-2xl space-y-5">@include('carpool.partials.key')<input type="hidden" name="offer_id" value="{{ $offer['id'] }}"><input type="hidden" name="revision" value="{{ $offer['revision'] }}">@include('carpool.partials.offer-fields')<x-button type="submit">{{ __('carpool.update') }}</x-button></form></details>@endif
<details class="mt-4 border-t border-line py-5"><summary class="min-h-12 cursor-pointer font-semibold">{{ __('carpool.template') }}</summary><form method="post" action="{{ route('carpool.discovery','template') }}" class="mt-5 flex flex-wrap gap-3">@include('carpool.partials.key')<input type="hidden" name="offer_id" value="{{ $offer['id'] }}"><label>{{ __('carpool.template_name') }}<input required name="name" maxlength="80" class="ml-3 min-h-12 border border-line bg-canvas px-3"></label><x-button type="submit" variant="secondary">{{ __('carpool.save') }}</x-button></form></details>
@endif
@include('carpool.partials.report')
@include('carpool.partials.pages')
</div></x-layouts.app>
