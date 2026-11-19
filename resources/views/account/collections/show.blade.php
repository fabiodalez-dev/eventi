{{--
    Una raccolta, vista da chi l'ha fatta: le date con la locandina (e la nota,
    se c'è), poi le impostazioni — nome, descrizione, condivisione — e in fondo
    la cancellazione, separata e in rosso.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$collection->name"
        :subtitle="filled($collection->description) ? $collection->description : null"
        :back="route('account.collections.index')"
        :back-label="__('collections.back')"
        :eyebrow="$collection->is_public ? __('collections.public') : __('collections.private')"
    >
        @if ($collection->is_public)
            <x-slot:actions>
                <x-button variant="secondary" size="sm" :href="$collection->publicUrl()">
                    <x-lucide name="external-link" class="size-4" />
                    {{ __('collections.open_public') }}
                </x-button>
            </x-slot:actions>
        @endif

        @if ($occurrences->isEmpty())
            <x-empty-state :title="__('collections.empty_title')" :description="__('collections.empty')">
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
                            <x-slot:actions>
                                <form method="POST" action="{{ route('account.collections.items.destroy', [$collection, $occurrence->getKey()]) }}">
                                    @csrf @method('DELETE')
                                    <x-button type="submit" variant="secondary" size="sm">{{ __('collections.remove') }}</x-button>
                                </form>
                            </x-slot:actions>
                        </x-service-event-row>
                    </div>
                @endforeach
            </x-card>
        @endif

        <x-card as="form" id="impostazioni-raccolta" method="POST" :action="route('account.collections.update', $collection)" :title="__('collections.settings')">
            @csrf @method('PATCH')
            <x-field name="name" :label="__('collections.fields.name')" :value="$collection->name" :required="true" maxlength="120" />
            <x-field name="description" :label="__('collections.fields.description')" :value="$collection->description" maxlength="500" />
            <label class="flex min-h-12 items-start gap-3 text-ink">
                <input type="hidden" name="is_public" value="0">
                <input type="checkbox" name="is_public" value="1" class="mt-1 size-5 shrink-0 accent-accent" @checked($collection->is_public)>
                <span>{{ __('collections.public') }}<span class="mt-1 block text-sm text-ink-muted">{{ __('collections.public_hint') }}</span></span>
            </label>
            @if ($collection->is_public)
                <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold text-ink">{{ __('collections.link') }}</span>
                    <a href="{{ $collection->publicUrl() }}" class="text-sm break-all text-brand" data-collection-link>{{ $collection->publicUrl() }}</a>
                </div>
            @endif
            <x-form-actions>
                <x-button type="submit">{{ __('collections.save') }}</x-button>
            </x-form-actions>
        </x-card>

        <x-card tone="alert" :title="__('collections.delete')" :description="__('collections.delete_confirm')">
            <form method="POST" action="{{ route('account.collections.destroy', $collection) }}" data-confirm="{{ __('collections.delete_confirm') }}">
                @csrf @method('DELETE')
                <x-button type="submit" variant="danger">{{ __('collections.delete') }}</x-button>
            </form>
        </x-card>
    </x-service-page>
</x-layouts.app>
