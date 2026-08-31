<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestra il popolamento della demo (§2.2 e §7.4 del piano).
 *
 * Niente `WithoutModelEvents`: `business_date` ed `effective_ends_at` nascono
 * da `EventOccurrenceObserver` al salvataggio (§8.2, §8.3), e senza gli
 * eventi del model quelle due colonne NOT NULL resterebbero vuote.
 *
 * Ordine vincolato dalle dipendenze: ruoli e permessi servono agli utenti
 * (§3 del piano), le città servono ai locali, i locali e le categorie
 * servono agli eventi, gli utenti servono sia ai locali (per `approved_by`)
 * sia agli eventi (per `created_by`) — e il primo utente referente ha
 * bisogno del primo locale già creato.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CitySeeder::class,
            CategorySeeder::class,
            TagSeeder::class,
            VenueSeeder::class,
            UserSeeder::class,
            EventSeeder::class,
            MediaSeeder::class,

            // Le pagine informative (§11.1, §16) non dipendono da niente: stanno
            // in fondo perché il piè di pagina le mostri appena il resto esiste.
            PageSeeder::class,
        ]);
    }
}
