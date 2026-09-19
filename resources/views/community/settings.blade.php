<x-layouts.app :meta="$meta">
    @include('community.nav')<div class="max-w-3xl"><h1 class="text-hero">{{ $meta->heading }}</h1>
        @unless($verified)<p class="my-6 text-ink-muted">{{ __('community.verification_required') }}</p><x-button :href="route('community.whatsapp')">{{ __('community.whatsapp.title') }}</x-button>
        @else
        <form method="post" enctype="multipart/form-data" action="{{ route('community.settings.update') }}" class="mt-8 space-y-6">@csrf
            @foreach(['display_name' => 80, 'handle' => 40] as $field => $length)<label class="block font-semibold">{{ __('community.'.$field) }}<input name="{{ $field }}" value="{{ old($field, $profile[$field] ?? '') }}" maxlength="{{ $length }}" required class="mt-2 w-full border-2 border-line bg-canvas p-3"></label>@endforeach
            <p class="text-sm text-ink-muted">{{ __('community.handle_help') }}</p>
            <label class="block font-semibold">{{ __('community.bio') }}<textarea name="bio" maxlength="500" rows="3" class="mt-2 w-full border-2 border-line bg-canvas p-3">{{ old('bio', $profile['bio'] ?? '') }}</textarea></label>
            <label class="block font-semibold">{{ __('community.city') }}<select name="city_id" class="mt-2 w-full border-2 border-line bg-canvas p-3"><option value="">{{ __('community.no_city') }}</option>@foreach($cities as $city)<option value="{{ $city->id }}" @selected(old('city_id', $profile['city_id'] ?? null) == $city->id)>{{ $city->name }}</option>@endforeach</select></label>
            <label class="block font-semibold">{{ __('community.avatar') }}<input class="mt-2 block w-full border border-line p-3" type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></label>
            @if($profile['avatar_url'] ?? null)<img src="{{ $profile['avatar_url'] }}" alt="" width="80" height="80" class="size-20 rounded-full object-cover"><label class="flex gap-2"><input type="checkbox" name="remove_avatar" value="1">{{ __('community.remove_avatar') }}</label>@endif
            <fieldset class="space-y-3 border-y border-line py-6"><legend class="font-semibold">{{ __('community.visibility') }}</legend>
                @foreach(\App\Enums\ProfileVisibility::cases() as $visibility)<label class="flex min-h-11 items-center gap-3"><input type="radio" name="visibility" value="{{ $visibility->value }}" @checked(old('visibility', $profile['visibility'] ?? 'members') === $visibility->value)>{{ __('community.profile_visibility.'.$visibility->value) }}</label>@endforeach
                <input type="hidden" name="indexable" value="0"><label class="flex items-start gap-3"><input type="checkbox" name="indexable" value="1" class="mt-1" @checked(old('indexable', $profile['indexable'] ?? false))><span>{{ __('community.indexable') }}</span></label><p class="text-sm text-ink-muted">{{ __('community.index_help') }}</p>
            </fieldset>
            <fieldset class="space-y-3"><legend class="font-semibold">{{ __('community.venues') }}</legend><p class="text-sm text-ink-muted">{{ __('community.venues_help') }}</p><input type="hidden" name="venue_ids[]" value="">
                @forelse($venues as $venue)<label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="venue_ids[]" value="{{ $venue['id'] }}" @checked(in_array($venue['id'], old('venue_ids', $venue_ids)))>{{ $venue['name'] }}</label>@empty<p class="text-sm text-ink-muted">{{ __('community.no_venues') }}</p>@endforelse
            </fieldset>
            <div class="flex flex-wrap gap-3"><x-button type="submit">{{ __('community.save') }}</x-button>@if($profile)<x-button variant="secondary" :href="route('community.profile', $profile['handle'])">{{ __('community.visit_profile') }}</x-button>@endif</div>
        </form>
        <a href="{{ route('community.whatsapp') }}" class="mt-8 inline-flex min-h-11 items-center text-sm underline">{{ __('community.whatsapp.title') }}</a>
        @endunless
    </div>
</x-layouts.app>
