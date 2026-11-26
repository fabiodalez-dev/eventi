<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Community\CommunityAccess;
use App\Services\Management\ManagementSubjects;
use App\Services\Management\PendingInvitations;
use App\Support\Api\ApiDate;

/**
 * L'utente autenticato, e nient'altro di lui.
 *
 * L'etichetta del ruolo serve a riconoscere il proprio profilo nell'app.
 * I collegamenti al pannello non autorizzano operazioni: ogni destinazione applica le proprie policy.
 */
final class UserResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(User $user): array
    {
        $timezone = (string) $user->timezone;
        $role = collect([UserRole::SuperAdmin, UserRole::Admin, UserRole::Moderator, UserRole::VenueOwner, UserRole::VenueEditor])
            ->first(fn (UserRole $role): bool => $user->hasRole($role), UserRole::User);

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            // Nome e cognome separati, quando la persona li ha dati: l'app li usa
            // per precompilare chi prenota. Altrimenti null, e resta `name`.
            'first_name' => filled($user->first_name) ? (string) $user->first_name : null,
            'last_name' => filled($user->last_name) ? (string) $user->last_name : null,
            'email' => (string) $user->email,
            'role_label' => $role->label(),
            'management_links' => self::managementLinks($user),
            // Quanti locali e organizzazioni gestisce, e in quante date non ancora finite fa il controllo ingressi:
            // l'app mostra «Gestione eventi» solo se uno dei tre è maggiore di zero. Il dettaglio è in `GET /management/subjects`.
            // `invitations`: inviti ricevuti ancora senza risposta, il dettaglio in `GET /me/invitations`.
            'management' => self::management($user),
            'email_verified' => $user->email_verified_at !== null,
            'whatsapp_verified' => $user->isWhatsappVerified(),
            'community_access' => app(CommunityAccess::class)->state($user),
            'carpool_access' => app(CarpoolAccess::class)->state($user),
            'community_handle' => $user->communityProfile?->handle,
            'whatsapp_prompted' => $user->whatsapp_prompted_at !== null,
            'timezone' => $timezone,
            'locale' => (string) $user->locale,
            // `light` o `dark`, mai null: senza una scelta vale `dark`, come nelle versioni precedenti dell'app, che leggono questo campo come stringa obbligatoria.
            'appearance' => $user->appearance ?? 'dark',
            // La scelta della persona: `light`, `dark` oppure null (nessuna scelta, si segue il tema del sistema).
            'appearance_choice' => $user->appearance,
            // La vista degli elenchi sul telefono: `full`, `grid`, `list`, oppure null (nessuna scelta: vale quella del dispositivo).
            'card_layout' => $user->card_layout,
            'marketing_opt_in' => $user->marketing_opt_in_at !== null,
            'created_at' => ApiDate::attribute($user, 'created_at', $timezone),
            'updated_at' => ApiDate::attribute($user, 'updated_at', $timezone),
        ];
    }

    /** @return array{venues: int, organizers: int, checkin: int, invitations: int, editorial: bool} */
    private static function management(User $user): array
    {
        $counts = app(ManagementSubjects::class)->counts($user);

        return [
            'venues' => (int) $counts['venues'],
            'organizers' => (int) $counts['organizers'],
            'checkin' => (int) $counts['checkin'],
            // Inviti in un locale e al personale d'ingresso ancora senza risposta: la voce «I tuoi inviti» del profilo (P8).
            'invitations' => (int) app(PendingInvitations::class)->count($user),
            // Moderatore, admin o super admin: la riga «Amministrazione» del profilo apre la redazione
            // nell'app quando anche `features.editorial` di `GET /config` è acceso. Il dettaglio è in `GET /editorial/desk`.
            'editorial' => $user->isEditorialStaff(),
        ];
    }

    /** @return list<array{label: string, url: string, icon: string}> */
    private static function managementLinks(User $user): array
    {
        $links = [];
        if ($user->isEditorialStaff()) {
            $links[] = ['label' => 'Amministrazione', 'url' => url('/admin'), 'icon' => 'admin'];
        }
        if ($user->hasRole(UserRole::SuperAdmin)) {
            $links[] = ['label' => 'Impostazioni newsletter', 'url' => url('/admin/newsletter'), 'icon' => 'newsletter'];
        }
        if ($user->venues()->whereIn('venues.status', VenueStatus::valoriSenzaProvvedimento())->exists()) {
            $links[] = ['label' => 'Gestisci i locali', 'url' => url('/gestione'), 'icon' => 'venue'];
        }
        $organizer = $user->managedOrganizers()->exists();
        if ($organizer) {
            $links[] = ['label' => 'Gestisci gli eventi', 'url' => url('/organizza'), 'icon' => 'events'];
        }
        // Solo i locali senza provvedimento: un referente di un locale sospeso non gestisce più la biglietteria (D52).
        if ($organizer || $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]) || $user->activeOwnedVenues()->exists()) {
            $links[] = ['label' => 'Gestione biglietti', 'url' => route('ticketing.manage.index'), 'icon' => 'tickets'];
        }

        return $links;
    }
}
