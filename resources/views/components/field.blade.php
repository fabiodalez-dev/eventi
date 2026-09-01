{{--
    Un campo di modulo: etichetta, controllo, aiuto ed errore.

    L'errore è collegato al controllo con `aria-describedby` e il controllo
    dichiara `aria-invalid`: senza, chi usa uno screen reader sente il campo ma
    non sa perché il modulo non è passato.
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'placeholder' => null,
    'rows' => 5,
    /* Elenco valore => etichetta per i campi a scelta */
    'options' => null,
    'placeholderOption' => null,
    'autocomplete' => null,
])

@php
    $id = 'campo-'.\Illuminate\Support\Str::slug($name);
    $errorId = $id.'-errore';
    $hintId = $id.'-aiuto';
    $hasError = $errors->has($name);
    $current = old($name, $value);

    $describedBy = trim(($hint ? $hintId.' ' : '').($hasError ? $errorId : ''));

    $control = 'w-full border bg-surface px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none '
        .($hasError ? 'border-live' : 'border-line');
@endphp

<div {{ $attributes->class(['flex flex-col gap-1.5']) }}>
    <label for="{{ $id }}" class="text-sm font-semibold text-ink">
        {{ $label }}
        @if ($required)
            <span class="text-live" aria-hidden="true">*</span>
            <span class="sr-only">{{ __('forms.required') }}</span>
        @endif
    </label>

    @if ($hint)
        <p id="{{ $hintId }}" class="text-xs text-ink-subtle">{{ $hint }}</p>
    @endif

    @if ($options !== null)
        <select
            id="{{ $id }}"
            name="{{ $name }}"
            @if ($required) required @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            class="{{ $control }}"
        >
            @if ($placeholderOption !== null)
                <option value="">{{ $placeholderOption }}</option>
            @endif

            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            rows="{{ $rows }}"
            @if ($required) required @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            class="{{ $control }}"
        >{{ $current }}</textarea>
    @else
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ $current }}"
            @if ($required) required @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            class="{{ $control }}"
        >
    @endif

    @error($name)
        <p id="{{ $errorId }}" class="text-xs font-semibold text-live">{{ $message }}</p>
    @enderror
</div>
