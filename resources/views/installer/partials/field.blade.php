{{-- Un campo del wizard: etichetta, controllo, aiuto e messaggio di errore. --}}
@php
    $type ??= 'text';
    $required ??= true;
    $help ??= null;
    $value ??= '';
    $attributes ??= [];
@endphp
<div class="field">
    <label for="{{ $name }}">{{ $label }}</label>
    <input
        type="{{ $type }}"
        id="{{ $name }}"
        name="{{ $name }}"
        value="{{ $type === 'password' ? '' : old($name, $value) }}"
        @if ($required) required @endif
        @foreach ($attributes as $attribute => $attributeValue) {{ $attribute }}="{{ $attributeValue }}" @endforeach
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
    >
    @if ($help)
        <span class="help">{{ $help }}</span>
    @endif
    @error($name)
        <span class="error" id="{{ $name }}-error">{{ $message }}</span>
    @enderror
</div>
