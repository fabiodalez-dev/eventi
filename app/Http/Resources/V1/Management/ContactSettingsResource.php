<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Management;

use App\Enums\ContactMode;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Contact\ContactEmailConfirmation;

/**
 * Il modulo di contatto pubblico di una scheda (locale o organizzazione), come
 * lo mostra la sezione «Modulo di contatto» dei pannelli: chi può scrivere,
 * l'indirizzo che riceve e lo stato del doppio opt-in.
 */
final class ContactSettingsResource
{
    /**
     * @return array{mode: string, email: string|null, confirmed: bool, pending: bool, can_resend: bool, link_days: int, note: string|null, modes: list<array{value: string, label: string}>}
     */
    public static function toArray(Venue|Organizer $target): array
    {
        $email = filled($target->contact_email) ? (string) $target->contact_email : null;
        $confirmed = $target->hasConfirmedContactEmail();

        return [
            'mode' => $target->contact_mode->value,
            'email' => $email,
            // Il modulo compare sulla pagina pubblica solo con il recapito confermato.
            'confirmed' => $confirmed,
            'pending' => $email !== null && ! $confirmed,
            // `false` anche quando i tetti giornalieri sono esauriti: il pulsante non si mostra.
            'can_resend' => $email !== null && ! $confirmed && app(ContactEmailConfirmation::class)->canSend($target),
            'link_days' => ContactEmailConfirmation::LINK_DAYS,
            // La frase sotto la scelta quando i messaggi da chiunque non si possono ancora accendere.
            'note' => config('contact.recaptcha_site_key') && config('contact.recaptcha_secret_key')
                ? null
                : (string) __('contact.members_only', ['app' => config('app.name')]),
            'modes' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(ContactMode::options()),
                array_values(ContactMode::options()),
            ),
        ];
    }
}
