{{--
    Un campo dei moduli dei passaggi: etichetta, aiuto, controllo ed errore
    accanto al campo. Lo stesso disegno di `<x-field>`, con in più i limiti che
    servono qui (`min`/`max` della finestra di partenza, `maxlength`) e un id
    scelto da chi lo usa, perché nella stessa pagina ci possono essere due
    moduli con gli stessi nomi (cerca e offri).

    Parametri: `name`, `id`, `label`, `type` (text, number, datetime-local,
    textarea, select), `value`, `hint`, `required`, `min`, `max`, `maxlength`,
    `rows`, `options` (valore => etichetta), `error` (chiave dell'errore, per
    difetto il nome).
--}}
@php
    $type ??= 'text';
    $error ??= $name;
    $hasError = $errors->has($error);
    $hintId = $id.'-aiuto';
    $errorId = $id.'-errore';
    $describedBy = trim((filled($hint ?? null) ? $hintId : '').' '.($hasError ? $errorId : ''));
    $control = 'min-h-12 w-full border bg-surface px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none '.($hasError ? 'border-live' : 'border-line');
@endphp
<div class="flex min-w-0 flex-col gap-1.5">
    <label for="{{ $id }}" class="text-sm font-semibold text-ink">{{ $label }}</label>
    @if (filled($hint ?? null))
        <p id="{{ $hintId }}" class="text-xs text-ink-subtle">{{ $hint }}</p>
    @endif
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows ?? 3 }}" @if ($maxlength ?? null) maxlength="{{ $maxlength }}" @endif @if ($required ?? false) required @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif class="{{ $control }}">{{ $value ?? '' }}</textarea>
    @elseif ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" @if ($required ?? false) required @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif class="{{ $control }}">
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) ($value ?? '') === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value ?? '' }}" @if (isset($min) && $min !== null) min="{{ $min }}" @endif @if (isset($max) && $max !== null) max="{{ $max }}" @endif @if ($maxlength ?? null) maxlength="{{ $maxlength }}" @endif @if ($required ?? false) required @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif class="{{ $control }}">
    @endif
    @if ($hasError)
        <p id="{{ $errorId }}" class="text-xs font-semibold text-live">{{ $errors->first($error) }}</p>
    @endif
</div>
