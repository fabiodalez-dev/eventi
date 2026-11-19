{{--
    Le raccolte di una persona: prima quelle che ha, ognuna con le prime tre
    locandine per riconoscerla, poi il modulo per crearne una nuova.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('collections.lead')" :back="route('account.profile')">
        @if ($collections->isEmpty())
            <x-empty-state :title="__('collections.none_title')" :description="__('collections.none')" />
        @else
            <x-card :title="trans_choice('collections.count', $collections->count(), ['count' => $collections->count()])" padding="none" data-collections>
                @foreach ($collections as $collection)
                    @php($posters = $collection->items->map(fn ($item) => $item->occurrence?->event)->filter()->take(3))
                    <x-card.row
                        :href="route('account.collections.show', $collection)"
                        :title="$collection->name"
                        :description="trans_choice('collections.items', $collection->items_count, ['count' => $collection->items_count]).' '.__('common.separator').' '.($collection->is_public ? __('collections.public') : __('collections.private'))"
                    >
                        <x-slot:leading>
                            <span class="flex w-[104px] items-center" aria-hidden="true">
                                @forelse ($posters as $event)
                                    <span @class(['svc-poster svc-poster--sm ring-2 ring-surface', '-ml-8' => ! $loop->first])>
                                        @if (\App\Support\Poster::imageSet($event) !== null)
                                            <x-event-artwork :event="$event" sizes="56px" />
                                        @else
                                            <span class="svc-poster__placeholder"><x-lucide name="calendar" class="size-5" /></span>
                                        @endif
                                    </span>
                                @empty
                                    <span class="svc-poster svc-poster--sm"><span class="svc-poster__placeholder"><x-lucide name="heart" class="size-5" /></span></span>
                                @endforelse
                            </span>
                        </x-slot:leading>
                    </x-card.row>
                @endforeach
            </x-card>
        @endif

        <x-card as="form" id="nuova-raccolta" method="POST" :action="route('account.collections.store')" :title="__('collections.new')" :description="__('collections.new_help')">
            @csrf
            <x-field name="name" :label="__('collections.fields.name')" :required="true" maxlength="120" />
            <x-field name="description" :label="__('collections.fields.description')" maxlength="500" />
            <label class="flex min-h-12 items-start gap-3 text-ink">
                <input type="hidden" name="is_public" value="0">
                <input type="checkbox" name="is_public" value="1" class="mt-1 size-5 shrink-0 accent-accent" @checked(old('is_public'))>
                <span>{{ __('collections.public') }}<span class="mt-1 block text-sm text-ink-muted">{{ __('collections.public_hint') }}</span></span>
            </label>
            <x-form-actions>
                <x-button type="submit">{{ __('collections.create') }}</x-button>
            </x-form-actions>
        </x-card>
    </x-service-page>
</x-layouts.app>
