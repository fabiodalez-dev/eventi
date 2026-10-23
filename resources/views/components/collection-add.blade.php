{{--
    «Aggiungi a una raccolta», sotto il pulsante Salva della scheda evento.
    Chi è dentro sceglie la data (se la serie ne ha più d'una) e la raccolta,
    oppure ne crea una nuova al volo; chi è fuori vede il collegamento
    all'accesso. Un modulo normale: nessun JavaScript richiesto.
--}}
@props(['occurrences'])
@php
    $dates = collect($occurrences)->filter()->values();
    $collections = auth()->check() ? auth()->user()->eventCollections()->orderBy('name')->get(['id', 'name']) : collect();
    $formatter = app(\App\Support\DateFormatter::class);
@endphp
@if ($dates->isNotEmpty())
    @auth
        <details class="text-sm" data-collection-add>
            <summary class="ui-action inline-flex min-h-12 cursor-pointer items-center font-semibold underline">{{ __('collections.add') }}</summary>
            <form method="POST" action="{{ $collections->isEmpty() ? route('account.collections.store') : route('account.collections.items.store', $collections->first()) }}" class="mt-3 grid gap-3 border-2 border-line bg-canvas p-4" data-collection-form data-store-url="{{ route('account.collections.store') }}" data-items-url="{{ route('account.collections.items.store', ['collection' => '__ID__']) }}">
                @csrf
                @if ($dates->count() === 1)
                    <input type="hidden" name="occurrence_id" value="{{ $dates->first()->getKey() }}">
                @else
                    <label class="grid gap-1">{{ __('collections.add_which') }}
                        <select name="occurrence_id" class="min-h-12 border border-line bg-surface p-3 text-ink">
                            @foreach ($dates as $date)
                                <option value="{{ $date->getKey() }}">{{ $formatter->dayAndTime($date->business_date, $date->starts_at) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if ($collections->isNotEmpty())
                    <label class="grid gap-1">{{ __('collections.add_to') }}
                        <select name="collection" data-collection-select class="min-h-12 border border-line bg-surface p-3 text-ink">
                            @foreach ($collections as $collection)
                                <option value="{{ $collection->getKey() }}">{{ $collection->name }}</option>
                            @endforeach
                            <option value="new">{{ __('collections.add_new') }}</option>
                        </select>
                    </label>
                @endif
                <label class="grid gap-1" data-collection-new @if ($collections->isNotEmpty()) hidden @endif>{{ __('collections.add_new_name') }}
                    <input type="text" name="name" maxlength="120" class="min-h-12 border border-line bg-surface p-3 text-ink" @if ($collections->isEmpty()) required @endif>
                </label>
                <x-button type="submit" class="min-h-12 justify-self-start">{{ __('collections.add_button') }}</x-button>
            </form>
        </details>
    @else
        <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="ui-action inline-flex min-h-12 items-center text-sm font-semibold underline">{{ __('collections.add_login') }}</a>
    @endauth
@endif
