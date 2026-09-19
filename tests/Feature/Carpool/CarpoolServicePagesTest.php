<?php

declare(strict_types=1);

use App\Models\CarpoolProfile;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['community.enabled' => true]);
});

it('lists the latest notices for the bell dropdown, newest first and only the owner ones', function (): void {
    foreach (['Primo', 'Secondo'] as $title) {
        $this->passenger->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => $title, 'body' => 'Testo '.$title]]);
    }
    $this->driver->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'Di un altro']]);

    $this->actingAs($this->passenger)->get('/avvisi/ultimi')->assertOk()
        ->assertHeader('X-Inbox-Watermark')->assertSee('Primo')->assertSee('Secondo')->assertDontSee('Di un altro');
});

it('requires login for the bell dropdown', function (): void {
    $this->get('/avvisi/ultimi')->assertRedirect();
});

it('shows the same tabs on carpool and community pages and marks the current one', function (): void {
    $this->actingAs($this->passenger)->get('/passaggi/requisiti')->assertOk()
        ->assertSee('aria-current="page"', false)->assertSeeInOrder(['I miei passaggi', 'Messaggi', 'Avvisi', 'Verifiche e requisiti']);
    $this->actingAs($this->passenger)->get('/avvisi')->assertOk()->assertSeeInOrder(['Bacheca', 'Persone', 'Il mio profilo', 'Avvisi']);
});

it('warns on the profile when a requirement for rides is missing', function (): void {
    CarpoolProfile::where('user_id', $this->passenger->id)->update(['adult_declared_at' => null]);

    $this->actingAs($this->passenger->fresh())->get('/il-mio-profilo')->assertOk()
        ->assertSee('data-carpool-requirements-warning="adult"', false)->assertSee('Non puoi ancora chiedere né offrire passaggi');
});

it('does not warn on the profile when rides are available', function (): void {
    $this->actingAs($this->passenger)->get('/il-mio-profilo')->assertOk()->assertDontSee('data-carpool-requirements-warning', false);
});
