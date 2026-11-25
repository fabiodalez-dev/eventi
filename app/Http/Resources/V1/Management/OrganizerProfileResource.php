<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Management;

use App\Actions\UpdateOrganizerProfile;
use App\Models\Organizer;

/**
 * Il profilo dell'organizzazione come lo compila la pagina «Profilo» del
 * pannello: presentazione, sito, email, modulo di contatto, più la pagina
 * pubblica e il calendario da sottoscrivere.
 */
final class OrganizerProfileResource
{
    /** @return array<string, mixed> */
    public static function toArray(Organizer $organizer): array
    {
        return [
            'id' => (int) $organizer->getKey(),
            'slug' => (string) $organizer->slug,
            'name' => (string) $organizer->name,
            'description' => $organizer->description,
            'website' => $organizer->website,
            'email' => $organizer->email,
            'contact' => ContactSettingsResource::toArray($organizer),
            'public_url' => route('organizers.show', $organizer->slug),
            'calendar_url' => route('organizers.calendar', $organizer->slug),
            'limits' => [
                'description' => UpdateOrganizerProfile::DESCRIPTION_MAX,
            ],
        ];
    }
}
