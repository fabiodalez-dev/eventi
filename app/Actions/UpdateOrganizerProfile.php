<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organizer;
use App\Support\Description;

/**
 * Salva il profilo pubblico dell'organizzazione: presentazione, sito, email e
 * modulo di contatto. La pagina «Profilo» del pannello dell'organizzatore e
 * `PATCH /management/organizers/{organizer}/profile` passano da qui.
 *
 * Salva solo i campi che riceve. La presentazione passa dallo stesso filtro
 * dell'editor del pannello; la conferma del recapito del modulo di contatto
 * la gestisce il modello. L'autorizzazione (`OrganizerPolicy::updateProfile`)
 * la controlla chi chiama.
 */
final class UpdateOrganizerProfile
{
    public const FIELDS = ['description', 'website', 'email', 'contact_mode', 'contact_email'];

    /** La presentazione: lo stesso tetto del pannello. */
    public const DESCRIPTION_MAX = 20000;

    /**
     * @param  array<string, mixed>  $state  i campi del modulo; quelli assenti restano come sono
     */
    public function handle(Organizer $organizer, array $state): Organizer
    {
        $state = array_intersect_key($state, array_flip(self::FIELDS));

        if (is_string($state['description'] ?? null)) {
            $state['description'] = Description::sanitize($state['description']);
        }

        $organizer->update($state);

        return $organizer;
    }
}
