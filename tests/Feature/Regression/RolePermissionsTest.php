<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\CalendarImport;
use App\Filament\Venue\Pages\Collaborators;
use App\Models\Category;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `RolesAndPermissionsSeeder` **non gira con le migrazioni** (`RUNBOOK.md`).
 * È già successo: si aggiunge una risorsa al pannello, si aggiunge il permesso
 * all'enum e alla Policy, e in produzione la pagina nuova risponde 403 a un
 * amministratore che dovrebbe vederla — mentre in locale i test passano,
 * perché ogni test semina i ruoli da capo.
 *
 * Il presidio non può accorgersi che il seeder non è stato **eseguito** sul
 * server, ma può accorgersi che non è stato **aggiornato**: l'elenco delle
 * risorse si legge dal pannello, non da una lista scritta a mano, così una
 * risorsa nuova entra in questo test il giorno in cui viene creata.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = City::factory()->padova()->create();
    Category::factory()->create(['default_duration_minutes' => 180]);
});

/**
 * @return list<string>
 */
function elenchiDelPannelloAdmin(): array
{
    Filament::setCurrentPanel('admin');

    $urls = [];

    /** @var class-string<resource> $resource */
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pagine = $resource::getPages();

        if (isset($pagine['index'])) {
            $urls[] = $resource::getUrl('index', panel: 'admin');
        }
    }

    return $urls;
}

it('trova nel pannello le risorse che ci si aspetta, e non zero', function (): void {
    expect(elenchiDelPannelloAdmin())->toHaveCount(count(Filament::getPanel('admin')->getResources()))
        ->and(count(elenchiDelPannelloAdmin()))->toBeGreaterThanOrEqual(10);
});

it('apre a un amministratore ogni elenco del pannello, senza permessi mancanti', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    $negati = [];

    foreach (elenchiDelPannelloAdmin() as $url) {
        if ($this->actingAs($admin)->get($url)->getStatusCode() !== 200) {
            $negati[] = $url;
        }
    }

    expect($negati)->toBe([]);
});

it('chiude a chi non è della redazione ogni elenco del pannello', function (): void {
    $utente = User::factory()->create();
    $utente->assignRole(UserRole::User->value);

    $aperti = [];

    foreach (elenchiDelPannelloAdmin() as $url) {
        if ($this->actingAs($utente)->get($url)->getStatusCode() === 200) {
            $aperti[] = $url;
        }
    }

    expect($aperti)->toBe([]);
});

/**
 * Un permesso che esiste nell'enum, che le Policy interrogano, e che il
 * seeder non assegna a nessuno, è un permesso che nessuno ha: la pagina che
 * lo pretende resta chiusa per sempre e nessun test se ne accorge.
 */
it('assegna a qualcuno ogni permesso dichiarato nell enum', function (): void {
    $senzaRuolo = [];

    foreach (PermissionEnum::cases() as $permesso) {
        $assegnato = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permesso->value))
            ->exists();

        if (! $assegnato) {
            $senzaRuolo[] = $permesso->value;
        }
    }

    expect($senzaRuolo)->toBe([]);
});

it('crea nel database tutti e soli i permessi e i ruoli dichiarati negli enum', function (): void {
    expect(Permission::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionEnum::values())->sort()->values()->all())
        ->and(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(UserRole::cases())->map(fn (UserRole $r): string => $r->value)->sort()->values()->all());
});

it('non duplica niente se lo si riesegue, come il runbook promette', function (): void {
    $permessiPrima = Permission::query()->count();
    $ruoliPrima = Role::query()->count();

    (new RolesAndPermissionsSeeder)->run();
    (new RolesAndPermissionsSeeder)->run();

    expect(Permission::query()->count())->toBe($permessiPrima)
        ->and(Role::query()->count())->toBe($ruoliPrima);
});

/**
 * Le due pagine del pannello dei locali che decidono da sé chi entra
 * (`canAccess()`): il permesso da solo non basta, la Policy verifica anche che
 * il locale sia il proprio (§7 delle convenzioni).
 */
it('apre al referente le pagine del pannello locali che hanno un canAccess proprio', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $venue->members()->attach($owner->getKey(), ['role' => VenueRole::Owner->value, 'accepted_at' => now()]);

    $this->actingAs($owner);
    Filament::setTenant($venue->fresh());

    expect(Collaborators::canAccess())->toBeTrue()
        ->and(CalendarImport::canAccess())->toBeTrue();
});

it('chiude al collaboratore le stesse pagine, che sono del referente', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $editor = User::factory()->create();
    $editor->assignRole(UserRole::VenueEditor->value);
    $venue->members()->attach($editor->getKey(), ['role' => VenueRole::Editor->value, 'accepted_at' => now()]);

    $this->actingAs($editor);
    Filament::setTenant($venue->fresh());

    expect(Collaborators::canAccess())->toBeFalse()
        ->and(CalendarImport::canAccess())->toBeFalse();
});
