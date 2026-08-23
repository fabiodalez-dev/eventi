<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Models\VenueApplication;

/**
 * Registra una richiesta di accreditamento di un locale (§11.1,
 * `/registra-il-tuo-locale`).
 *
 * La pratica nasce `pending` e non crea alcun locale: l'approvazione è un atto
 * della redazione (§14.6), e fino ad allora nulla di quanto scritto qui compare
 * sul sito pubblico.
 */
final class CreateVenueApplication
{
    /**
     * @param  array{venue_name: string, contact_name: string, contact_role?: string|null, contact_phone?: string|null, contact_email: string, address?: string|null, type?: string|null, website?: string|null, message?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): VenueApplication
    {
        $website = $data['website'] ?? null;

        return VenueApplication::create([
            'user_id' => $userId,
            'venue_id' => null,
            'venue_name' => $data['venue_name'],
            'contact_name' => $data['contact_name'],
            'contact_role' => $data['contact_role'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'contact_email' => $data['contact_email'],
            'address' => $data['address'] ?? null,
            'type' => VenueType::tryFrom((string) ($data['type'] ?? '')) ?? VenueType::Altro,
            'socials' => filled($website) ? ['website' => $website] : null,
            'message' => $data['message'] ?? null,
            'status' => ApplicationStatus::Pending,
        ]);
    }
}
