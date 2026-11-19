{{--
    Il riepilogo degli errori di un modulo: uno solo per pagina, subito sotto
    il titolo. Ogni campo mostra comunque il proprio errore accanto a sé (vedi
    `<x-field>`); questo riquadro serve a chi non vede il campo sbagliato
    perché sta più in basso, e a chi legge con uno screen reader, che lo sente
    per primo grazie a `role="alert"`.

    `bag` sceglie il sacchetto con nome (`$errors->getBag('…')`) quando la
    pagina ha più moduli. Senza errori non disegna niente.
--}}
@props(['title' => null, 'bag' => 'default'])
@php($messages = isset($errors) ? $errors->getBag($bag)->all() : [])
@if ($messages !== [])
    <div role="alert" data-form-errors {{ $attributes->class('svc-notice svc-notice--alert flex gap-3 p-4') }}>
        <span class="mt-0.5 shrink-0 text-alert"><x-lucide name="alert-circle" class="size-5" /></span>
        <div class="flex min-w-0 flex-col gap-1.5">
            <p class="font-semibold text-ink">{{ $title ?? trans_choice('ui.service.form_errors_title', count($messages)) }}</p>
            <ul class="flex list-disc flex-col gap-1 pl-5 text-sm text-ink-muted">
                @foreach (array_unique($messages) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
