<x-layouts.app :narrow="true" :meta="$meta"><div class="mx-auto w-full max-w-4xl">@include('carpool.nav')
<p class="mb-3 text-sm font-semibold text-accent">{{ __('carpool.free') }}</p><h1 class="text-hero">{{ __('carpool.offer') }}</h1><p class="mt-4 mb-8 text-ink-muted">{{ $event_title }} · {{ $event_date }}</p>
@if(!$access['eligible']) @include('carpool.partials.gate')
@else
<form method="post" action="{{ route('carpool.action', 'offer') }}" class="max-w-2xl space-y-6" data-ride-offer-form>
@include('carpool.partials.key')<input type="hidden" name="occurrence_id" value="{{ $occurrence_id }}">
@if(count($templates))<label class="block space-y-2"><span>{{ __('carpool.templates') }}</span><select data-ride-template class="min-h-12 w-full border border-line bg-canvas px-4"><option value="">{{ __('carpool.all') }}</option>@foreach($templates as $template)<option value="{{ json_encode($template['settings']) }}">{{ $template['name'] }}</option>@endforeach</select></label>@endif
<fieldset class="flex flex-wrap gap-5">@foreach(\App\Enums\RideLeg::cases() as $leg)<label class="flex min-h-12 items-center gap-3"><input type="radio" name="leg" value="{{ $leg->value }}" @checked(old('leg', 'outbound') === $leg->value)>{{ $leg->label() }}</label>@endforeach</fieldset>
@include('carpool.partials.offer-fields')
<label class="flex items-start gap-3"><input required type="checkbox" name="driver_declaration" value="1" class="mt-1.5 size-5 shrink-0"><span class="text-sm">{{ __('carpool.driver_declaration') }}</span></label>
<div class="flex flex-wrap gap-3"><x-button type="submit">{{ __('carpool.create') }}</x-button><x-button type="submit" variant="secondary" name="draft" value="1">{{ __('carpool.draft') }}</x-button></div>
</form>@endif
</div></x-layouts.app>
