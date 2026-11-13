<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Models\Organizer;
use App\Models\User;
use Tests\Support\VenueIsolationScenario;

/**
 * Flusso: le tre porte d'ingresso — redazione (`/admin`), locali
 * (`/gestione`) e organizzazioni (`/organizza`) — provate da ogni persona
 * dello scenario. Ognuna entra solo dove ha qualcosa da gestire, e un locale
 * sospeso chiude il pannello anche a chi lo gestiva.
 */
it('apre ciascuno dei tre pannelli solo a chi ha titolo e lo chiude a tutti gli altri ruoli', function (): void {
    $s = VenueIsolationScenario::make();
    $organizerOwner = User::factory()->create();
    $organizerOwner->assignRole(UserRole::User->value);
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $s->city->id, 'owner_id' => $organizerOwner->id, 'is_active' => true]);
    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);

    $admin = '/admin';
    $venueA = '/gestione/'.$s->venueA->slug;
    $venueB = '/gestione/'.$s->venueB->slug;
    $org = '/organizza/'.$organizer->slug;

    // Chi non è entrato viene mandato all'accesso di ciascun pannello.
    $this->get($admin)->assertRedirect('/admin/login');
    $this->get($venueA)->assertRedirect('/gestione/login');
    $this->get($org)->assertRedirect('/organizza/login');

    // La redazione entra in /admin, non nei pannelli dei locali che non gestisce.
    foreach ([$super, $s->admin, $s->moderator] as $staff) {
        $this->actingAs($staff)->get($admin)->assertOk();
        $this->get($venueA)->assertForbidden();
        $this->get($org)->assertForbidden();
    }

    // Il referente e il collaboratore di A entrano nel proprio locale e basta.
    foreach ([$s->ownerA, $s->editorA] as $member) {
        $this->actingAs($member)->get($admin)->assertForbidden();
        $this->get($venueA)->assertOk()->assertSee('Circolo Aurora');
        $this->get($venueB)->assertNotFound();
        $this->get($org)->assertForbidden();
    }

    // Il referente di B, specularmente.
    $this->actingAs($s->ownerB)->get($venueB)->assertOk();
    $this->get($venueA)->assertNotFound();

    // L'organizzazione entra nel suo pannello, non in quelli dei locali né in redazione.
    $this->actingAs($organizerOwner)->get($org)->assertOk()->assertSee('Collettivo Portici');
    $this->get($admin)->assertForbidden();
    $this->get($venueA)->assertForbidden();

    // Un utente qualunque non entra da nessuna parte.
    $this->actingAs($s->plainUser);
    foreach ([$admin, $venueA, $org] as $path) {
        $this->get($path)->assertForbidden();
    }

    // Sospeso il locale, il suo referente resta fuori anche dal pannello.
    $s->venueA->update(['status' => VenueStatus::Suspended]);
    $this->actingAs($s->ownerA->fresh())->get($venueA)->assertForbidden();

    // Disattivata l'organizzazione, chi la gestiva non entra più.
    $organizer->update(['is_active' => false]);
    $this->actingAs($organizerOwner->fresh())->get($org)->assertForbidden();
});
