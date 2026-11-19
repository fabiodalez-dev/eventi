{{--
    «Cosa vuoi seguire»: categorie e locali da cui ricevere riepiloghi (le
    stesse scelte alimentano il feed), poi le ricerche salvate. Una colonna,
    una card per elenco; gli elenchi lunghi scorrono dentro la loro card.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('subscriptions.interests_help')" :back="route('account.profile')">
        <form method="POST" action="{{ route('account.notifications.interests.update') }}" class="flex flex-col gap-6">
            @csrf @method('PATCH')
            @foreach (['categories' => $categories, 'venues' => $venues] as $group => $options)
                <x-card :id="'segui-'.$group" :title="__('subscriptions.'.$group)" :description="trans_choice('notifications.interests.selected', collect($options)->where('selected', true)->count(), ['count' => collect($options)->where('selected', true)->count()])" padding="none">
                    <fieldset class="m-0 max-h-[26rem] min-w-0 overflow-y-auto border-0 p-0">
                        <legend class="sr-only">{{ __('subscriptions.'.$group) }}</legend>
                        @foreach ($options as $option)
                            <label class="svc-row flex min-h-12 cursor-pointer items-center gap-3 px-5 py-2 text-ink sm:px-6">
                                <input type="checkbox" name="{{ $group }}[]" value="{{ $option['id'] }}" @checked($option['selected']) class="size-5 shrink-0 accent-accent">
                                <span class="min-w-0 break-words">{{ $option['name'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                </x-card>
            @endforeach
            <x-form-actions>
                <x-button type="submit">{{ __('notifications.preferences.submit') }}</x-button>
            </x-form-actions>
        </form>

        <x-card id="ricerche-salvate" :title="__('search.saved.title')" :description="__('search.saved.help')" padding="none">
            @forelse ($searches as $search)
                <x-card.row :title="null" icon="search">
                    <a href="{{ $search->url ?? route('events.index', $search->queryString()) }}" class="font-semibold text-ink underline-offset-4 hover:underline">{{ $search->name }}</a>
                    <span class="text-xs">{{ $search->last_checked_at ? __('search.saved.checked', ['date' => $search->last_checked_at->timezone(auth()->user()->timezone ?: config('app.timezone'))->format('d/m/Y H:i')]) : __('search.saved.never') }}</span>
                    <x-slot:trailing>
                        <form method="POST" action="{{ route('account.searches.destroy', $search) }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary" size="sm">{{ __('search.saved.remove') }}</x-button></form>
                    </x-slot:trailing>
                </x-card.row>
            @empty
                <p class="m-0 px-5 py-4 text-sm text-ink-muted sm:px-6">{{ __('search.saved.none') }}</p>
            @endforelse
        </x-card>

        <x-card padding="none">
            <x-card.row :href="route('account.notifications')" :title="__('subscriptions.delivery')" :description="__('notifications.interests.delivery_hint')" icon="mail" />
        </x-card>
    </x-service-page>
</x-layouts.app>
