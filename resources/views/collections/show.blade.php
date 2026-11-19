{{-- `/raccolte/{token}`: una raccolta condivisa, per chi ha il link. Le date con la locandina e la nota di chi l'ha fatta. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$collection->name"
        :eyebrow="$collection->ownerLabel() ? __('collections.shared_by', ['name' => $collection->ownerLabel()]) : __('collections.shared_anonymous')"
        :subtitle="filled($collection->description) ? $collection->description : __('collections.shared_lead', ['app' => config('app.name')])"
    >
        @if ($isOwner)
            <x-slot:actions>
                <x-button variant="secondary" size="sm" :href="route('account.collections.show', $collection)">{{ __('collections.manage') }}</x-button>
            </x-slot:actions>
        @endif

        @if ($occurrences->isEmpty())
            <x-empty-state :title="__('collections.empty_title')" :description="__('collections.shared_empty')">
                <x-button variant="secondary" :href="route('events.index')">{{ __('collections.browse') }}</x-button>
            </x-empty-state>
        @else
            <x-card :title="trans_choice('collections.items', $occurrences->count(), ['count' => $occurrences->count()])" padding="none" data-collection-items>
                @foreach ($occurrences as $occurrence)
                    <div class="svc-row px-5 py-4 sm:px-6">
                        <x-service-event-row :occurrence="$occurrence">
                            @if (filled($notes[$occurrence->getKey()] ?? null))
                                <span><strong class="text-ink">{{ __('collections.note') }}:</strong> {{ $notes[$occurrence->getKey()] }}</span>
                            @endif
                        </x-service-event-row>
                    </div>
                @endforeach
            </x-card>
        @endif
    </x-service-page>
</x-layouts.app>
