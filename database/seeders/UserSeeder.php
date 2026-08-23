<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Cinque utenti demo, password condivisa `password`, uno per ogni ruolo di
 * §3 del piano che ha senso avere pronto prima dei pannelli: admin,
 * moderatore, referente e collaboratore del primo locale, utente semplice.
 *
 * Il ruolo globale spatie e la riga della pivot `venue_user` sono due cose
 * diverse e servono entrambe: il primo dà al referente/collaboratore i
 * permessi di quella categoria (`venues.update`, `events.create`, ...), la
 * seconda dice a quale locale — nessuno dei due basta da solo (§3 del piano).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => UserRole::Admin->value, 'guard_name' => 'web']);
        $moderatorRole = Role::firstOrCreate(['name' => UserRole::Moderator->value, 'guard_name' => 'web']);
        $userRole = Role::firstOrCreate(['name' => UserRole::User->value, 'guard_name' => 'web']);
        $venueOwnerRole = Role::firstOrCreate(['name' => UserRole::VenueOwner->value, 'guard_name' => 'web']);
        $venueEditorRole = Role::firstOrCreate(['name' => UserRole::VenueEditor->value, 'guard_name' => 'web']);

        $admin = User::factory()->create([
            'name' => 'Amministratore inCittà',
            'email' => 'admin@incitta.test',
        ]);
        $admin->assignRole($adminRole);

        $moderator = User::factory()->create([
            'name' => 'Moderatore inCittà',
            'email' => 'moderatore@incitta.test',
        ]);
        $moderator->assignRole($moderatorRole);

        $firstVenue = Venue::query()->orderBy('id')->firstOrFail();

        $venueOwner = User::factory()->create([
            'name' => 'Referente Locale',
            'email' => 'locale@incitta.test',
        ]);
        $venueOwner->assignRole($venueOwnerRole);
        $venueOwner->venues()->attach($firstVenue, [
            'role' => VenueRole::Owner->value,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $venueEditor = User::factory()->create([
            'name' => 'Collaboratore Locale',
            'email' => 'collaboratore@incitta.test',
        ]);
        $venueEditor->assignRole($venueEditorRole);
        $venueEditor->venues()->attach($firstVenue, [
            'role' => VenueRole::Editor->value,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $user = User::factory()->create([
            'name' => 'Utente Demo',
            'email' => 'utente@incitta.test',
        ]);
        $user->assignRole($userRole);

        // I locali già approvati risultano approvati dall'admin demo.
        Venue::query()->whereNotNull('approved_at')->update(['approved_by' => $admin->getKey()]);
    }
}
