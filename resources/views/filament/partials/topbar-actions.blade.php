@php $context = app(\App\Filament\Shared\TopbarActions::class)->context(); @endphp
@if ($context)
    <a class="itb-context" href="{{ $context['home'] }}" data-topbar-context="{{ $context['panel'] }}">
        <span class="itb-mark" aria-hidden="true"><x-filament::icon :icon="$context['panel'] === 'venue' ? 'heroicon-o-building-storefront' : 'heroicon-o-squares-2x2'" /></span>
        <span class="itb-identity"><small>{{ $context['label'] }}</small><strong>{{ $context['name'] }}</strong></span>
    </a>
    <div class="itb-actions" role="group" aria-label="{{ __('topbar.quick_actions') }}">
        @foreach ($context['items'] as $item)
            @if ($item['inline'])
                <a href="{{ $item['url'] }}" @class(['itb-action', 'itb-primary' => $item['key'] === 'create'])
                   data-topbar-action="{{ $item['key'] }}" aria-label="{{ __('topbar.'.$item['key']) }}">
                    <x-filament::icon :icon="$item['icon']" />
                    @if ($item['key'] === 'create')
                        <span class="itb-wide-label">{{ __('topbar.create') }}</span><span class="itb-short-label">{{ __('topbar.create_short') }}</span>
                    @else
                        <span>{{ __('topbar.'.$item['key']) }}</span>
                    @endif
                </a>
            @endif
        @endforeach
        <x-filament::dropdown placement="bottom-end" teleport>
            <x-slot name="trigger">
                <button type="button" class="itb-action itb-more" aria-label="{{ __('topbar.more') }}" data-topbar-more>
                    <x-filament::icon icon="heroicon-o-ellipsis-horizontal" />
                </button>
            </x-slot>
            <x-filament::dropdown.list>
                @foreach ($context['items'] as $item)
                    @if (! $item['inline'])
                        <x-filament::dropdown.list.item tag="a" :href="$item['url']" :icon="$item['icon']"
                            :target="$item['key'] === 'public' ? '_blank' : null"
                            :rel="$item['key'] === 'public' ? 'noopener noreferrer' : null"
                            :data-topbar-action="$item['key']">{{ __('topbar.'.$item['key']) }}</x-filament::dropdown.list.item>
                    @endif
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
@endif
