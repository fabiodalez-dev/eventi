{{--
    La riga finale di un modulo: il pulsante principale per primo, poi le
    alternative. Sul telefono i pulsanti si impilano a tutta larghezza — due
    pulsanti affiancati di larghezze diverse sullo schermo stretto si leggono
    come uno solo — e dal tablet in su tornano in riga.
--}}
<div data-form-actions {{ $attributes->class('flex w-full flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center [&>*]:w-full sm:[&>*]:w-auto') }}>
    {{ $slot }}
</div>
