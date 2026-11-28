<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Carpool;
use App\Filament\Admin\Pages\Community;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use App\Support\PhoneMask;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EditorialSession;

/*
 * La revisione di sicurezza della seconda ondata della redazione, per
 * community e passaggi: rotte dei passaggi spente con la funzione, una
 * regola sola per la sospensione da tutta la community (data e tolta, app
 * e pannello), errori delle consegne senza numeri di telefono, ricerca per
 * email che non dice chi è registrato, lettura delle evidenze nel registro
 * di sicurezza.
 */
beforeEach(function (): void {
    Notification::fake();
    cpSetup($this);
    (new RolesAndPermissionsSeeder)->run();
    $this->moderator = EditorialSession::user(UserRole::Moderator);
    $this->admin = EditorialSession::user(UserRole::Admin);
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function secAs(mixed $test, User $person): mixed
{
    return EditorialSession::as($test, EditorialSession::token($person));
}

/** Una persona con il profilo pubblico della community. */
function secPerson(string $name, ?UserRole $role = null): User
{
    $user = User::factory()->create(['name' => $name]);
    if ($role !== null) {
        $user->assignRole($role->value);
    }
    $user->communityProfile()->create(['handle' => 'sec_'.$user->id, 'display_name' => $name, 'visibility' => 'public']);

    return $user->fresh();
}

/** Il pannello `admin` con la guardia del sito, per una persona dello staff. */
function secPanel(mixed $test, User $staff): void
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    auth()->shouldUse('web');
    $test->actingAs($staff, 'web');
}

it('risponde 404 su tutte le rotte dei passaggi quando la funzione è spenta, non su quelle della community', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $person = carpoolPerson();
    config(['carpool.enabled' => false]);

    secAs($this, $this->admin)->getJson('/api/v1/editorial/carpool/cases')->assertNotFound();
    secAs($this, $this->admin)->getJson('/api/v1/editorial/carpool/cases/'.$case->id)->assertNotFound();
    secAs($this, $this->admin)->postJson('/api/v1/editorial/carpool/cases/'.$case->id.'/evidence', ['reason' => 'Verifica della segnalazione'])->assertNotFound();
    secAs($this, $this->admin)->postJson('/api/v1/editorial/carpool/users/'.$person->id.'/suspend', ['scope' => 'carpool', 'reason' => 'Comportamento scorretto'])->assertNotFound();
    expect($person->fresh()->carpool_suspended_at)->toBeNull();
    // La community non dipende dai passaggi.
    secAs($this, $this->admin)->getJson('/api/v1/editorial/community/profiles')->assertOk();

    config(['carpool.enabled' => true]);
    secAs($this, $this->admin)->getJson('/api/v1/editorial/carpool/cases')->assertOk();
});

it('sospende e riattiva da tutta la community con la stessa regola nelle due rotte dell\'app', function (): void {
    $person = secPerson('Persona sospesa');
    $peer = secPerson('Collega amministratore', UserRole::Admin);
    $reason = ['reason' => 'Messaggi offensivi ripetuti'];
    $global = ['scope' => 'global', 'reason' => 'Messaggi offensivi ripetuti'];

    // Il moderatore non ha `users.manage`: né sospende né riattiva, da nessuna delle due vie.
    secAs($this, $this->moderator)->postJson('/api/v1/editorial/community/users/'.$person->id.'/suspend', $reason)->assertForbidden();
    secAs($this, $this->moderator)->postJson('/api/v1/editorial/carpool/users/'.$person->id.'/suspend', $global)->assertForbidden();

    secAs($this, $this->admin)->postJson('/api/v1/editorial/community/users/'.$person->id.'/suspend', $reason)->assertOk();
    expect($person->fresh()->community_suspended_at)->not->toBeNull();

    // Una sospensione messa da un amministratore non la toglie il moderatore, da nessuna delle due vie.
    secAs($this, $this->moderator)->postJson('/api/v1/editorial/community/users/'.$person->id.'/unsuspend', $reason)->assertForbidden();
    secAs($this, $this->moderator)->postJson('/api/v1/editorial/carpool/users/'.$person->id.'/restore', $global)->assertForbidden();
    expect($person->fresh()->community_suspended_at)->not->toBeNull();
    $row = collect(secAs($this, $this->moderator)->getJson('/api/v1/editorial/community/profiles?q=Persona')->assertOk()->json('data'))->firstWhere('user_id', $person->id);
    expect($row['abilities'])->toMatchArray(['suspend' => false, 'unsuspend' => false]);
    $carpoolRow = collect(secAs($this, $this->moderator)->getJson('/api/v1/editorial/carpool/users?q=Persona')->assertOk()->json('data'))->firstWhere('id', $person->id);
    expect($carpoolRow['abilities'])->toMatchArray(['suspend_global' => false, 'restore_global' => false]);

    secAs($this, $this->admin)->postJson('/api/v1/editorial/carpool/users/'.$person->id.'/restore', $global)->assertOk()->assertJsonPath('data.community_suspended', false);
    expect($person->fresh()->community_suspended_at)->toBeNull();

    // Il rango vale anche per togliere: un pari grado sospeso dal super admin resta sospeso per l'admin.
    secAs($this, $this->superAdmin)->postJson('/api/v1/editorial/carpool/users/'.$peer->id.'/suspend', $global)->assertOk();
    secAs($this, $this->admin)->postJson('/api/v1/editorial/community/users/'.$peer->id.'/unsuspend', $reason)->assertForbidden();
    secAs($this, $this->admin)->postJson('/api/v1/editorial/carpool/users/'.$peer->id.'/restore', $global)->assertForbidden();
    expect($peer->fresh()->community_suspended_at)->not->toBeNull();
    secAs($this, $this->superAdmin)->postJson('/api/v1/editorial/community/users/'.$peer->id.'/unsuspend', $reason)->assertOk();
    expect($peer->fresh()->community_suspended_at)->toBeNull();
});

it('applica la stessa regola alla sospensione globale nel pannello', function (): void {
    $person = secPerson('Persona del pannello');
    $person->forceFill(['community_suspended_at' => now()])->save();

    // Le azioni del pannello chiamano gli stessi due servizi: per il moderatore 403 da tutte e due le pagine.
    expect(fn () => app(CommunityModeration::class)->apply($this->moderator, 'user', $person->id, true, 'Chiarimento avvenuto'))->toThrow(HttpException::class)
        ->and(fn () => app(CommunitySafety::class)->restrict($this->moderator, $person, 'global', false, 'Chiarimento avvenuto'))->toThrow(HttpException::class);
    expect($person->fresh()->community_suspended_at)->not->toBeNull();

    // E non mostrano il pulsante: «Riattiva» nella community, «Ripristina tutta la community» nei passaggi.
    secPanel($this, $this->moderator);
    $this->get('/admin/community')->assertOk();
    Livewire::test(Community::class)->assertSee('Persona del pannello')->assertDontSee(__('community.unsuspend'));
    $this->get('/admin/carpool')->assertOk();
    Livewire::test(Carpool::class)->set('section', 'users')->set('search', 'Persona del pannello')
        ->assertSee('Persona del pannello')->assertDontSee(__('carpool.admin.restore_global'));

    secPanel($this, $this->admin);
    $this->get('/admin/community')->assertOk();
    Livewire::test(Community::class)->assertSee(__('community.unsuspend'));
    app(CommunityModeration::class)->apply($this->admin, 'user', $person->id, true, 'Chiarimento avvenuto');
    expect($person->fresh()->community_suspended_at)->toBeNull();
});

it('copre i numeri di telefono negli errori delle consegne, nell\'app e nel pannello', function (): void {
    $id = (int) DB::table('community_delivery_outbox')->insertGetId([
        'user_id' => $this->passenger->id, 'notification_id' => (string) Str::uuid(), 'dedupe_key' => (string) Str::uuid(), 'payload' => '{}',
        'available_at' => now(), 'attempts' => 8, 'last_error' => '(#131026) Recipient +39 333 123 4567 is not a valid WhatsApp user',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = collect(secAs($this, $this->moderator)->getJson('/api/v1/editorial/carpool/deliveries')->assertOk()->json('data'))->firstWhere('id', $id);
    expect($row['last_error'])->toBe('(#131026) Recipient •••67 is not a valid WhatsApp user');

    secPanel($this, $this->admin);
    $this->get('/admin/carpool')->assertOk();
    Livewire::test(Carpool::class)->set('section', 'deliveries')->assertSee('•••67')->assertDontSee('333 123 4567');
});

it('copre i numeri con prefisso e separatori e lascia stare date e codici brevi', function (): void {
    expect(PhoneMask::mask('to 393331234567 failed'))->toBe('to •••67 failed')
        ->and(PhoneMask::mask('tel (0049) 30-1234-5678'))->toBe('tel •••78')
        ->and(PhoneMask::mask('HTTP 429 at 2026-11-26 10:00:00'))->toBe('HTTP 429 at 2026-11-26 10:00:00')
        ->and(PhoneMask::mask('error 131047 re-engagement'))->toBe('error 131047 re-engagement')
        ->and(PhoneMask::mask(null))->toBeNull();
});

it('non dice se un\'email è registrata cercando fra le persone dei passaggi', function (): void {
    $registered = User::factory()->create(['email' => 'registrata@example.test', 'name' => 'Non usa i passaggi']);
    $this->driver->forceFill(['email' => 'conducente@example.test'])->save();
    $search = fn (string $q): array => secAs($this, $this->moderator)->getJson('/api/v1/editorial/carpool/users?q='.urlencode($q))->assertOk()->json();

    // Registrata ma fuori dai passaggi, e mai registrata: la stessa risposta vuota.
    expect($search('registrata@example.test'))->toBe($search('nessuno@example.test'))
        ->and($search('registrata@example.test')['data'])->toBe([]);
    // Pezzi di email non trovano nessuno; l'email intera di chi usa i passaggi sì.
    expect($search('conducente@')['data'])->toBe([])
        ->and($search('example.test')['data'])->toBe([])
        ->and(collect($search('conducente@example.test')['data'])->pluck('id')->all())->toBe([$this->driver->id]);

    secPanel($this, $this->admin);
    $this->get('/admin/carpool')->assertOk();
    Livewire::test(Carpool::class)->set('section', 'users')->set('search', 'registrata@example.test')->assertDontSee($registered->name)
        ->set('search', 'example.test')->assertDontSee($this->driver->name);
});

it('scrive nel registro di sicurezza ogni lettura delle evidenze, dall\'app e dal sito', function (): void {
    $case = cpCase($this, cpAccepted($this));

    secAs($this, $this->admin)->postJson('/api/v1/editorial/carpool/cases/'.$case->id.'/evidence', ['reason' => 'Verifica della segnalazione'])->assertOk();
    $app = Activity::query()->where('log_name', 'sicurezza')->where('description', 'evidenze_passaggio_lette')->sole();
    expect($app->causer_id)->toBe($this->admin->id)
        ->and($app->getProperty('caso_id'))->toBe($case->id)
        ->and($app->getProperty('richiesta_id'))->toBe($case->ride_request_id)
        ->and($app->getProperty('motivo'))->toBe('Verifica della segnalazione')
        ->and($app->getProperty('canale'))->toBe('app');

    // Il sito passa dallo stesso punto (`CommunitySafety::inspect`).
    app(CommunitySafety::class)->inspect($this->superAdmin, $case, 'Seconda verifica', export: true);
    $site = Activity::query()->where('log_name', 'sicurezza')->where('description', 'evidenze_passaggio_esportate')->sole();
    expect($site->causer_id)->toBe($this->superAdmin->id)->and($site->getProperty('caso_id'))->toBe($case->id);
});
