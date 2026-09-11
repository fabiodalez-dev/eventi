<?php

declare(strict_types=1);

namespace App\Support;

final class PracticalIcons
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return ['information-circle' => 'Informazioni', 'identification' => 'Tessera / documento', 'user-group' => 'Persone / coppia', 'heart' => 'Assistenza e cura', 'hand-raised' => 'Accessibilità', 'eye' => 'Vista / visibilità', 'speaker-wave' => 'Ascolto / audio', 'chat-bubble-left-right' => 'Lingue / interpretariato', 'ticket' => 'Biglietto / prenotazione', 'currency-euro' => 'Costi', 'credit-card' => 'Pagamenti', 'map-pin' => 'Luogo / parcheggio', 'truck' => 'Trasporti', 'arrow-right-on-rectangle' => 'Ingresso', 'clock' => 'Orari', 'sun' => 'All’aperto', 'cloud' => 'Meteo', 'shield-check' => 'Sicurezza', 'users' => 'Famiglie', 'sparkles' => 'Comfort', 'cake' => 'Ristorazione', 'beaker' => 'Bevande / acqua', 'shopping-bag' => 'Guardaroba / oggetti', 'camera' => 'Foto', 'device-phone-mobile' => 'Telefono', 'arrow-path' => 'Rimborsi / cambi', 'musical-note' => 'Musica', 'globe-alt' => 'Online', 'check-circle' => 'Confermato'];
    }

    public static function safe(?string $icon): string
    {
        return array_key_exists($icon ?? '', self::options()) ? $icon : 'information-circle';
    }

    /** @return array<string, string> */
    public static function previews(): array
    {
        $options = [];
        foreach (self::options() as $icon => $label) {
            $options[$icon] = '<span class="practical-icon-option">'.svg('heroicon-o-'.$icon, 'practical-icon-preview', ['width' => '20', 'height' => '20'])->toHtml().'<span>'.e($label).'</span></span>';
        }

        return $options;
    }
}
