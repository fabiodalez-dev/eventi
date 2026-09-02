{{-- La configurazione della posta (solo super amministratore).

     Il riquadro in cima dice da dove sta uscendo la posta ADESSO, prima di
     ogni campo: chi apre questa pagina di solito ci arriva perche' qualcosa
     non e' arrivato, e la prima domanda e' sempre quella. --}}
<x-filament-panels::page>
    @php
        $impostazioni = app(\App\Settings\MailSettings::class);
        $inVigore = $impostazioni->active();
    @endphp

    <div @class([
        'flex flex-col gap-1 rounded-lg border p-4',
        'border-success-600/40 bg-success-50 dark:bg-success-500/10' => $inVigore,
        'border-gray-300 bg-gray-50 dark:border-white/10 dark:bg-white/5' => ! $inVigore,
    ])>
        <p class="text-sm font-semibold text-gray-950 dark:text-white">
            {{ $inVigore ? __('mail_settings.status.custom') : __('mail_settings.status.env') }}
        </p>

        <p class="text-sm text-gray-600 dark:text-gray-400">
            @if ($inVigore)
                {{ __('mail_settings.status.custom_detail', [
                    'host' => $impostazioni->host,
                    'porta' => $impostazioni->port,
                ]) }}
            @else
                {{ __('mail_settings.status.env_detail', [
                    'trasporto' => config('mail.default'),
                    'mittente' => config('mail.from.address'),
                ]) }}
            @endif
        </p>

        @if ($impostazioni->verified_at !== null && ! $impostazioni->verified())
            {{-- Caso che confonde piu' di tutti: una prova c'e' stata, ma
                 riguardava una configurazione diversa da quella scritta ora. --}}
            <p class="text-sm text-warning-600 dark:text-warning-400">
                {{ __('mail_settings.status.stale_check') }}
            </p>
        @endif
    </div>

    {{ $this->form }}
</x-filament-panels::page>
