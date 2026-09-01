<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * I dati di base di un'installazione vera (D42, punto 5).
 *
 * Solo i quattro seeder che vivono **senza faker**: `fakerphp/faker` sta in
 * `require-dev` e in produzione non esiste, perché il deploy installa con
 * `--no-dev`. È già costato un guasto, e un seeder che funziona in locale e
 * muore in produzione è il modo più costoso di scoprirlo.
 *
 * Fuori di qui restano `CitySeeder`, `UserSeeder`, `VenueSeeder`, `MediaSeeder`
 * ed `EventSeeder`: chiamano `fake()` direttamente o passano da factory le cui
 * `definition()` lo fanno per loro, anche quando ogni attributo è esplicito.
 * Sono i dati della **demo**, che si popola con `migrate:fresh --seed` in
 * sviluppo, dove faker c'è.
 *
 * La città e l'amministratore non sono seed: sono le risposte del wizard, e
 * l'installer le scrive con `City::create()` e `User::create()` diretti.
 *
 * Resta utilizzabile a mano: `php artisan db:seed --class=ProductionSeeder --force`.
 * Tutti e quattro sono idempotenti, quindi rieseguirlo non duplica niente e
 * non calpesta le correzioni fatte dalla redazione.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CategorySeeder::class,
            TagSeeder::class,
            PageSeeder::class,
        ]);
    }
}
