<details id="ride-search" class="mt-10 border-t border-line py-6"><summary class="min-h-12 cursor-pointer text-section">{{ __('carpool.search_create') }}</summary>
<form method="post" action="{{ route('carpool.discovery', 'search') }}" class="mt-5 max-w-2xl space-y-5">
@include('carpool.partials.key')<input type="hidden" name="occurrence_id" value="{{ $occurrence_id }}">
<div class="grid gap-4 sm:grid-cols-2"><label>{{ __('carpool.zone') }}<input name="zone" maxlength="120" class="mt-2 min-h-12 w-full border border-line bg-canvas px-3"></label><label>{{ __('carpool.seats') }}<input required type="number" name="seats" min="1" max="8" value="1" class="mt-2 min-h-12 w-full border border-line bg-canvas px-3"></label>
@foreach(['earliest', 'latest'] as $bound)<label>{{ __('carpool.'.$bound) }}<input required type="datetime-local" name="{{ $bound }}_at" class="mt-2 min-h-12 w-full border border-line bg-canvas px-3"></label>@endforeach
<label>{{ __('carpool.seek') }}<select name="leg" class="mt-2 min-h-12 w-full border border-line bg-canvas px-3">@foreach(\App\Enums\RideLeg::cases() as $leg)<option value="{{ $leg->value }}">{{ $leg->label() }}</option>@endforeach</select></label>
<label>{{ __('carpool.accessibility') }}<select name="accessibility" class="mt-2 min-h-12 w-full border border-line bg-canvas px-3">@foreach(\App\Enums\RideAccessibility::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</select></label></div>
<input type="hidden" name="is_public" value="0"><label class="flex min-h-12 items-center gap-3"><input type="checkbox" name="is_public" value="1" class="size-5">{{ __('carpool.search_public') }}</label>
<input type="hidden" name="alerts_enabled" value="1"><x-button type="submit">{{ __('carpool.save') }}</x-button>
</form></details>
