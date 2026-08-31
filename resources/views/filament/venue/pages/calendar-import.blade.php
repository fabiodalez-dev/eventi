{{--
    La pagina che collega il calendario di un locale (§14.2).

    L'ordine degli elementi non è casuale: prima si vede lo stato di ciò che è
    già collegato, poi si incolla l'indirizzo, poi si guarda l'anteprima, e solo
    in fondo si accende. Il pulsante che accende sta dopo l'anteprima perché
    l'anteprima è la condizione per premerlo.
--}}
<x-filament-panels::page>
    @if ($source)
        <x-filament::section>
            <x-slot name="heading">{{ __('manage.calendar.status.heading') }}</x-slot>

            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('manage.calendar.status.state') }}
                    </dt>
                    <dd class="mt-1">
                        <x-filament::badge :color="$source->is_active ? 'success' : 'gray'">
                            {{ $source->is_active
                                ? __('manage.calendar.status.active')
                                : __('manage.calendar.status.suspended') }}
                        </x-filament::badge>
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('manage.calendar.status.last_run') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $source->last_run_at
                            ? $source->last_run_at->setTimezone(\App\Filament\Venue\Support\CurrentVenue::timezone())->format('d/m/Y H:i')
                            : __('manage.calendar.status.never') }}
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('manage.calendar.status.outcome') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                        {{ $source->last_status
                            ? \App\Enums\ImportRunStatus::tryFrom($source->last_status)?->label() ?? $source->last_status
                            : '—' }}
                    </dd>
                </div>

                <div class="sm:col-span-2 lg:col-span-1">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('manage.calendar.status.address') }}
                    </dt>
                    <dd class="mt-1 truncate text-sm text-gray-950 dark:text-white" title="{{ $source->url }}">
                        {{ $source->url }}
                    </dd>
                </div>
            </dl>

            @if ($source->last_error)
                {{-- L'errore si legge in italiano, non come traccia di stack:
                     chi gestisce un locale deve capire se il problema è suo
                     (indirizzo sbagliato) o nostro. --}}
                <div class="mt-4 rounded-lg bg-danger-50 p-4 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                    {{ $source->last_error }}
                </div>
            @endif
        </x-filament::section>
    @endif

    <form wire:submit="connect">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="button" wire:click="preview" color="gray" icon="heroicon-o-eye">
                {{ __('manage.calendar.actions.preview') }}
            </x-filament::button>

            <x-filament::button type="submit" :disabled="! $previewed">
                {{ $source
                    ? __('manage.calendar.actions.update')
                    : __('manage.calendar.actions.connect') }}
            </x-filament::button>
        </div>

        @unless ($previewed)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('manage.calendar.preview_required') }}
            </p>
        @endunless
    </form>

    @if ($previewed)
        <x-filament::section>
            <x-slot name="heading">{{ __('manage.calendar.preview.heading') }}</x-slot>
            <x-slot name="description">{{ __('manage.calendar.preview.description') }}</x-slot>

            @include('filament.import.preview', [
                'rows' => $previewRows,
                'error' => $previewError,
            ])
        </x-filament::section>
    @endif
</x-filament-panels::page>
