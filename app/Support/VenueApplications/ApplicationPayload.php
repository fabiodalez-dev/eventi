<?php

declare(strict_types=1);

namespace App\Support\VenueApplications;

use App\Enums\ApplicationKind;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\VenueApplication;
use App\Support\Api\ApiDate;

/**
 * Una richiesta di registrazione o rivendicazione come la legge l'app: la
 * card di «Le mie richieste» (`GET /v1/me/venue-applications`) e la risposta
 * all'invio di un modulo (P13) hanno la stessa forma.
 *
 * Le note interne della redazione, i recapiti del referente e l'immagine non
 * escono mai.
 */
final class ApplicationPayload
{
    /**
     * @param  list<int|string>  $venues  i locali che l'account gestisce
     * @param  list<int|string>  $organizers  le organizzazioni che l'account gestisce
     * @return array<string, mixed>
     */
    public static function item(VenueApplication $application, User $user, array $venues, array $organizers): array
    {
        $kind = $application->kind();
        ['state' => $state, 'panel' => $panel, 'public' => $public, 'next' => $next, 'confirm' => $confirm] = ApplicationState::describe($application, $user, $venues, $organizers);

        return [
            'id' => (int) $application->getKey(),
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'name' => (string) $application->venue_name,
            'type' => $kind === ApplicationKind::Organizer ? null : $application->type->value,
            'type_label' => $kind === ApplicationKind::Organizer ? null : $application->type->label(),
            'address' => $kind === ApplicationKind::Organizer ? null : $application->address,
            'state' => $state,
            'state_label' => __('venue_applications.account.state.'.$state),
            'next' => $next,
            'created_at' => ApiDate::attribute($application, 'created_at', 'UTC'),
            'reviewed_at' => in_array($state, ['approved', 'rejected'], true) ? ApiDate::instant($application->reviewed_at, 'UTC') : null,
            'can_confirm' => $confirm,
            'panel_url' => $panel,
            'public_url' => $public,
            'venue_slug' => $application->venue?->status === VenueStatus::Approved ? $application->venue->slug : null,
            'organizer_slug' => $application->organizer?->is_active ? $application->organizer->slug : null,
            /*
             * Per l'app (P13), che apre il pannello nella sua gestione: il
             * soggetto che `panel_url` apre sul sito, solo quando l'account lo
             * gestisce davvero.
             */
            'subject' => $panel === null ? null : self::subject($application, $kind),
            /*
             * Dove porta «Scrivi una nuova richiesta» dopo un rifiuto, come la
             * card del sito: di nuovo la rivendicazione se il locale si può
             * ancora rivendicare, altrimenti il modulo del suo tipo. Solo per
             * le respinte, le sole che mostrano il pulsante: così la domanda
             * sul locale costa una query solo per quelle.
             */
            'retry' => $state === 'rejected' ? self::retry($application, $kind) : null,
        ];
    }

    /** @return array{type: string, id: int}|null */
    private static function subject(VenueApplication $application, ApplicationKind $kind): ?array
    {
        $target = $kind === ApplicationKind::Organizer ? $application->organizer : $application->venue;

        return $target === null ? null : ['type' => $kind === ApplicationKind::Organizer ? 'organizer' : 'venue', 'id' => (int) $target->getKey()];
    }

    /** @return array{form: string, venue_slug: string|null} */
    private static function retry(VenueApplication $application, ApplicationKind $kind): array
    {
        if ($kind === ApplicationKind::Organizer) {
            return ['form' => 'organizer', 'venue_slug' => null];
        }

        if ($kind === ApplicationKind::Claim && $application->venue?->isClaimable() === true) {
            return ['form' => 'claim', 'venue_slug' => $application->venue->slug];
        }

        return ['form' => 'venue', 'venue_slug' => null];
    }
}
