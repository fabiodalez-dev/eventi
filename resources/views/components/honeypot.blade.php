{{--
    Campo esca contro i robot (§14.7).

    Non è nascosto con `type="hidden"` — quelli i robot li riconoscono — ma
    tolto dal flusso visivo e dalla navigazione da tastiera, e dichiarato
    invisibile agli screen reader. Una persona non lo vede e non ci arriva mai;
    un compilatore automatico lo riempie.
--}}
<div aria-hidden="true" class="absolute h-px w-px overflow-hidden opacity-0" style="left: -9999px">
    <label for="{{ \App\Support\Honeypot::FIELD }}">
        {{ __('forms.honeypot') }}
    </label>
    <input
        type="text"
        id="{{ \App\Support\Honeypot::FIELD }}"
        name="{{ \App\Support\Honeypot::FIELD }}"
        tabindex="-1"
        autocomplete="off"
        value=""
    >
</div>
