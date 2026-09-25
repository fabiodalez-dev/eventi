<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Enums\UserRole;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Community\Community;
use App\Services\Reviews\CatalogReviews;
use App\Support\EventUrl;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Chi amministra è esonerato dalla verifica del numero.
 *
 * Il numero WhatsApp serve a dimostrare che dietro un profilo pubblico c'è una
 * persona raggiungibile: una garanzia che per chi ha già le chiavi del pannello
 * non aggiunge niente. Senza l'esonero l'amministratore non vedeva nemmeno le
 * funzioni che deve sorvegliare, e le vedeva come non funzionanti invece che
 * come vietate.
 *
 * Quello che questi test proteggono è il confine: **l'esonero riguarda i
 * permessi, non i fatti**. Nessuno deve poter dedurre da qui che un numero non
 * verificato risulti verificato.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-20 12:00');
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-25 21:00', '2026-09-25 23:00');
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
});

afterEach(fn () => Carbon::setTestNow());

it('non finge che il numero sia verificato', function (): void {
    expect($this->admin->isWhatsappVerified())->toBeFalse()
        ->and($this->admin->canParticipateInCommunity())->toBeTrue();

    // L'API continua a dire il fatto, non il permesso.
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.whatsapp_verified', false);
});

it('mostra all’amministratore il pulsante «Ci vado», non l’invito a verificare', function (): void {
    /* Il profilo pubblico serve comunque — vedi il test seguente — quindi
       l'amministratore appena creato viene mandato a crearlo. Ciò che conta qui
       è che non gli si chieda più di verificare un numero. */
    app(Community::class)->profile($this->admin, ['handle' => 'chi-vede-il-pulsante',
        'display_name' => 'Chi vede il pulsante', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $this->actingAs($this->admin->fresh())->get(EventUrl::occurrence($this->occurrence))->assertOk()
        ->assertSee(__('community.attendance.going'))
        ->assertDontSee(__('community.attendance.verify_to_go'))
        ->assertDontSee(__('community.attendance.join'));
});

it('manda l’amministratore senza profilo a crearlo, non in un vicolo cieco', function (): void {
    $this->actingAs($this->admin)->get(EventUrl::occurrence($this->occurrence))->assertOk()
        ->assertSee(__('community.attendance.profile_to_go'))
        ->assertDontSee(__('community.attendance.verify_to_go'));
});

it('lascia all’amministratore la dichiarazione «ci vado» e il passo indietro', function (): void {
    /* Il profilo pubblico serve comunque, e non è l'esonero a deciderlo:
       «ci vado» mette un nome in un elenco, e senza profilo non c'è nome da
       mettere. Vale per tutti allo stesso modo. */
    app(Community::class)->profile($this->admin, ['handle' => 'chi-sorveglia',
        'display_name' => 'Chi sorveglia', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    app(Community::class)->attendance($this->admin->fresh(), $this->occurrence, true);

    $this->assertDatabaseHas('saved_events', ['user_id' => $this->admin->getKey(),
        'occurrence_id' => $this->occurrence->getKey(), 'visibility' => 'private']);

    $this->assertDatabaseHas('community_attendances', ['user_id' => $this->admin->id, 'occurrence_id' => $this->occurrence->id]);

    app(Community::class)->attendance($this->admin->fresh(), $this->occurrence, false);
    $this->assertDatabaseMissing('community_attendances', ['user_id' => $this->admin->id, 'occurrence_id' => $this->occurrence->id]);

    $this->assertDatabaseHas('saved_events', ['user_id' => $this->admin->getKey(),
        'occurrence_id' => $this->occurrence->getKey(), 'visibility' => 'private']);
});

it('lascia all’amministratore il profilo della comunità e una recensione', function (): void {
    $profilo = app(Community::class)->profile($this->admin, ['handle' => 'chi-amministra',
        'display_name' => 'Chi amministra', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    expect($profilo->handle)->toBe('chi-amministra');

    $organizzatore = Organizer::query()->create(['name' => 'Collettivo di prova',
        'city_id' => $this->city->getKey(), 'owner_id' => User::factory()->create()->getKey(), 'is_active' => true]);

    expect(app(CatalogReviews::class)->canWrite($this->admin->fresh(), $organizzatore))->toBeTrue();
});

it('non esonera chi è sospeso né chi non ha confermato l’indirizzo', function (): void {
    $sospeso = User::factory()->create();
    $sospeso->assignRole(UserRole::Admin->value);
    $sospeso->forceFill(['community_suspended_at' => now()])->save();

    $senzaEmail = User::factory()->unverified()->create();
    $senzaEmail->assignRole(UserRole::Admin->value);

    expect($sospeso->fresh()->canParticipateInCommunity())->toBeFalse()
        ->and($senzaEmail->fresh()->canParticipateInCommunity())->toBeFalse();
});

it('non esonera chi non amministra', function (): void {
    $redazione = User::factory()->create();
    $redazione->assignRole(UserRole::VenueOwner->value);

    expect($redazione->fresh()->canParticipateInCommunity())->toBeFalse();
});
