{{--
    «I miei interessi»: cosa compare negli elenchi. Una card per argomento —
    modalità, categorie, esigenze di accessibilità, proposte — dentro un solo
    modulo con il pulsante in fondo; i locali nascosti e il ripristino stanno
    fuori, perché sono azioni che hanno effetto subito.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('account.content_preferences.title')" :subtitle="__('account.content_preferences.lead')" :back="route('account.profile')">
        <form method="POST" action="{{ route('account.content-preferences.update') }}" class="flex flex-col gap-6" data-content-preferences>
            @csrf @method('PATCH')

            <x-card id="modalita" :title="__('account.content_preferences.mode_title')" :description="__('account.content_preferences.mode_help')">
                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="sr-only">{{ __('account.content_preferences.mode_title') }}</legend>
                    @foreach (['all', 'selected'] as $mode)
                        <label class="flex min-h-12 items-center gap-3 font-semibold text-ink">
                            <input type="radio" name="mode" value="{{ $mode }}" class="size-5 shrink-0 accent-accent" @checked(old('mode', $selection['mode']) === $mode)>
                            {{ __('account.content_preferences.mode.'.$mode) }}
                        </label>
                    @endforeach
                </fieldset>
            </x-card>

            <x-card :title="__('account.content_preferences.categories_title')" :description="__('account.content_preferences.categories_help')" padding="none">
                @foreach ($options as $category)
                    @php($choice = old('choices.'.$category->id, in_array($category->id, $selection['hidden_categories']) ? 'hidden' : (in_array($category->id, $selection['categories']) ? 'interested' : 'neutral')))
                    <label class="svc-row flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <span class="font-semibold text-ink">{{ $category->name }}</span>
                        <select name="choices[{{ $category->id }}]" class="min-h-12 w-full min-w-0 border-2 border-line bg-canvas px-2 text-sm text-ink sm:w-56">
                            @foreach (['neutral', 'interested', 'hidden'] as $value)
                                <option value="{{ $value }}" @selected($choice === $value)>{{ __('account.content_preferences.choice.'.$value) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </x-card>

            <x-card id="esigenze" :title="__('account.access_needs.title')" :description="__('account.access_needs.help')">
                <input type="hidden" name="access_needs_present" value="1">
                <fieldset class="m-0 flex min-w-0 flex-col gap-1 border-0 p-0">
                    <legend class="sr-only">{{ __('account.access_needs.title') }}</legend>
                    @foreach ($access_options as $value => $label)
                        <label class="flex min-h-12 items-center gap-3 text-ink"><input type="checkbox" name="access_needs[]" value="{{ $value }}" class="size-5 shrink-0 accent-accent" @checked(in_array($value, old('access_needs', $selection['access_needs']), true))> {{ $label }}</label>
                    @endforeach
                </fieldset>
            </x-card>

            <x-card :title="__('account.content_preferences.ads_title')">
                <label class="flex items-start gap-3 text-ink">
                    <input type="checkbox" name="inferred_ads" value="1" class="mt-1 size-5 shrink-0 accent-accent" @checked(old('inferred_ads', $selection['inferred_ads']))>
                    <span>{{ __('account.content_preferences.ads_label') }}<span class="mt-1 block text-sm text-ink-muted">{{ __('account.content_preferences.ads_help') }}</span></span>
                </label>
                <p class="m-0 text-sm text-ink-muted">{{ __('account.content_preferences.newsletter_help') }}</p>
            </x-card>

            <x-form-actions>
                <x-button type="submit">{{ __('account.content_preferences.submit') }}</x-button>
                <x-button :href="route('home')" variant="secondary">{{ __('account.content_preferences.back_events') }}</x-button>
            </x-form-actions>
        </form>

        <x-card :title="__('account.content_preferences.reset_title')" :description="__('account.content_preferences.reset_help')" tone="muted">
            <form method="POST" action="{{ route('account.content-preferences.update') }}">
                @csrf @method('PATCH')
                <input type="hidden" name="mode" value="all">
                <input type="hidden" name="inferred_ads" value="{{ $selection['inferred_ads'] ? 1 : 0 }}">
                <x-button type="submit" variant="secondary">{{ __('account.content_preferences.reset') }}</x-button>
            </form>
        </x-card>

        <x-card id="locali-nascosti" :title="__('account.hidden_venues.title')" :description="__('account.hidden_venues.help')" padding="none">
            @forelse ($hidden_venue_options as $venue)
                <x-card.row :href="null" :title="null">
                    <x-slot:leading><x-lucide name="map-pin" class="size-5 text-ink-muted" /></x-slot:leading>
                    <a href="{{ route('venues.show', $venue['slug']) }}" class="font-semibold text-ink underline-offset-4 hover:underline">{{ $venue['name'] }}</a>
                    <x-slot:trailing>
                        <form method="POST" action="{{ route('account.hidden-venues.destroy', $venue['id']) }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary" size="sm">{{ __('account.hidden_venues.show') }}</x-button></form>
                    </x-slot:trailing>
                </x-card.row>
            @empty
                <p class="m-0 px-5 py-4 text-sm text-ink-muted sm:px-6">{{ __('account.hidden_venues.none') }}</p>
            @endforelse
        </x-card>
    </x-service-page>
</x-layouts.app>
