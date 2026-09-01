<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ruoli e permessi di §3 del piano. `guest` non compare: è l'assenza di
 * autenticazione, non un ruolo da creare. L'appartenenza a un locale
 * specifico (owner/editor) resta nella pivot `venue_user`, non qui.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (UserRole::cases() as $role) {
            Role::query()->firstOrCreate(['name' => $role->value, 'guard_name' => 'web']);
        }

        $this->assign(UserRole::User, []);

        $this->assign(UserRole::VenueOwner, [
            PermissionEnum::ViewVenues,
            PermissionEnum::UpdateVenues,
            PermissionEnum::ManageVenueCollaborators,
            PermissionEnum::ViewVenueSensitiveData,
            PermissionEnum::ViewEvents,
            PermissionEnum::CreateEvents,
            PermissionEnum::UpdateEvents,
            PermissionEnum::DeleteEvents,
            PermissionEnum::PublishEvents,

            // «Collega il tuo calendario» (§14.2): il referente dichiara la
            // sorgente del **proprio** locale. Il permesso da solo non apre
            // niente — `ImportSourcePolicy` verifica comunque che la sorgente
            // sia di un locale di cui questa persona è referente.
            PermissionEnum::ManageImportSources,
        ]);

        // L'editor gestisce solo gli eventi del locale: nessun permesso su
        // `venues.*`, così non può toccare collaboratori né dati sensibili
        // né i dati del referente (§3, §18 scenario F).
        $this->assign(UserRole::VenueEditor, [
            PermissionEnum::ViewVenues,
            PermissionEnum::ViewEvents,
            PermissionEnum::CreateEvents,
            PermissionEnum::UpdateEvents,
            PermissionEnum::DeleteEvents,
            PermissionEnum::PublishEvents,
        ]);

        $this->assign(UserRole::Moderator, [
            PermissionEnum::ViewVenues,
            PermissionEnum::ModerateVenues,
            PermissionEnum::ViewEvents,
            PermissionEnum::ModerateEvents,
            PermissionEnum::ViewVenueApplications,
            PermissionEnum::ReviewVenueApplications,
            PermissionEnum::ViewReports,
            PermissionEnum::ResolveReports,
            PermissionEnum::ApproveTags,

            // Gli invii previsti si guardano, non si annullano: annullare una
            // notifica è un'azione sui dati di qualcun altro (§15.5).
            PermissionEnum::ViewScheduledNotifications,
        ]);

        // Le sponsorizzazioni NON sono fra i permessi del moderatore: sono
        // sopra, con admin e super admin. Decidere cosa compare a pagamento e
        // con quale priorità è una scelta commerciale, e a schermo somiglia
        // troppo a quella editoriale perché le due possano stare nelle stesse
        // mani senza dirlo.

        // Admin: "tutto il prodotto" (§3). Super admin lo contiene e vi
        // aggiunge l'infrastruttura, che non ha permessi qui perché non
        // governa nessuno dei model coperti dalle Policy.
        $this->assign(UserRole::Admin, PermissionEnum::cases());
        $this->assign(UserRole::SuperAdmin, PermissionEnum::cases());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<PermissionEnum>  $permissions
     */
    private function assign(UserRole $role, array $permissions): void
    {
        $roleModel = Role::query()->where('name', $role->value)->where('guard_name', 'web')->firstOrFail();

        $roleModel->syncPermissions(array_map(
            static fn (PermissionEnum $permission): string => $permission->value,
            $permissions,
        ));
    }
}
