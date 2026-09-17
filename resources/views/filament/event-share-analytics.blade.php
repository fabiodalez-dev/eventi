<x-filament-panels::page>
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('analytics.period') }}">
        @foreach (\App\Enums\StatsPeriod::options() as $value => $label)
            <x-filament::button :color="$this->period === $value ? 'primary' : 'gray'"
                wire:click="setPeriod('{{ $value }}')" wire:loading.attr="disabled">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    @include('filament.partials.event-share-report', ['rows' => $this->shareRows()])
</x-filament-panels::page>
