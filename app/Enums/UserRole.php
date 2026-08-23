<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ruoli globali gestiti da `spatie/laravel-permission` (§3 del piano).
 * `guest` non è un caso di questo enum: è l'assenza di autenticazione, non
 * un ruolo assegnato. L'appartenenza a un locale specifico (owner/editor)
 * vive invece nella pivot `venue_user` ed è modellata da `VenueRole`.
 */
enum UserRole: string
{
    case User = 'user';
    case VenueOwner = 'venue_owner';
    case VenueEditor = 'venue_editor';
    case Moderator = 'moderator';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return __('enums.user_role.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
