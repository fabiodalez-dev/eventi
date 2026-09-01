<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Page;
use App\Models\Tag;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Il seed di installazione (D42, punto 5). `fakerphp/faker` sta in
 * `require-dev`: in produzione, dove il deploy installa con `--no-dev`, non
 * esiste. Un seeder che funziona in locale e muore in produzione è un guasto
 * già pagato una volta.
 */

it('popola ruoli, permessi, categorie, etichette e pagine legali', function (): void {
    Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

    /*
     * I conteggi si leggono dalla FONTE, non da un numero scritto qui:
     * aggiungere un ruolo o un permesso e' una cosa normale, e non deve
     * rendere rosso un test che verifica tutt'altro — che il seeder li porti
     * TUTTI. Un numero fisso trasforma ogni aggiunta in una correzione di
     * test, e dopo la terza si smette di leggerli.
     */
    expect(Role::query()->count())->toBe(count(\App\Enums\UserRole::cases()))
        ->and(Permission::query()->count())->toBe(count(\App\Enums\Permission::cases()))
        ->and(Category::query()->count())->toBe(14)
        ->and(Tag::query()->count())->toBe(38)
        ->and(Page::query()->count())->toBe(5);
});

it('è idempotente: rieseguirlo non duplica niente', function (): void {
    Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

    expect(Role::query()->count())->toBe(6)
        ->and(Category::query()->count())->toBe(14)
        ->and(Page::query()->count())->toBe(5);
});

it('non contiene nulla che dipenda da faker', function (): void {
    /*
     * Il controllo è sul sorgente e non sull'esecuzione di proposito: qui il
     * pacchetto c'è, quindi un `fake()` aggiunto per distrazione a uno di
     * questi quattro seeder passerebbe la suite e romperebbe la prima
     * installazione vera. Questa è la regressione da fermare.
     */
    $classes = [
        RolesAndPermissionsSeeder::class,
        CategorySeeder::class,
        TagSeeder::class,
        PageSeeder::class,
    ];

    foreach ($classes as $class) {
        $file = (string) (new ReflectionClass($class))->getFileName();
        $source = (string) file_get_contents($file);

        expect($source)->not->toContain('fake(')
            ->and($source)->not->toContain('::factory(');
    }
});

it('chiama esattamente i quattro seeder che vivono senza faker', function (): void {
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(ProductionSeeder::class))->getFileName()
    );

    expect($source)
        ->toContain('RolesAndPermissionsSeeder::class')
        ->toContain('CategorySeeder::class')
        ->toContain('TagSeeder::class')
        ->toContain('PageSeeder::class')
        ->not->toContain('CitySeeder::class')
        ->not->toContain('UserSeeder::class')
        ->not->toContain('VenueSeeder::class')
        ->not->toContain('EventSeeder::class')
        ->not->toContain('MediaSeeder::class');
});
