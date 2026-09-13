<?php

declare(strict_types=1);

use App\Enums\ContactMode;
use App\Mail\PublicContact;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'contact_mode' => ContactMode::Members, 'contact_email' => 'venue-private@example.test']);
    $this->url = '/api/v1/venues/'.$this->venue->slug.'/contact';
    Mail::fake();
});

it('uses account identity and only the configured recipient', function (): void {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.enabled', true)->assertDontSee('venue-private@example.test');
    $this->postJson($this->url, ['name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Vorrei informazioni sull’accesso.'])->assertForbidden();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson($this->url, ['name' => 'Impostore', 'email' => 'other@example.test', 'recipient' => 'attacker@example.test', 'message' => 'Vorrei informazioni sull’accesso.'])->assertOk();
    Mail::assertSent(PublicContact::class, fn ($mail): bool => $mail->hasTo('venue-private@example.test') && $mail->senderEmail === $user->email && $mail->senderName === $user->name);
});

it('requires verified captcha and correct hostname for guests', function (): void {
    $this->venue->update(['contact_mode' => ContactMode::Everyone]);
    config(['contact.recaptcha_site_key' => 'test-site', 'contact.recaptcha_secret_key' => 'test-secret']);
    $payload = ['name' => 'Anna', 'email' => 'anna@example.test', 'message' => 'Vorrei informazioni sull’accesso.', 'g-recaptcha-response' => 'token'];
    Http::fake(['*' => Http::sequence()->push(['success' => true, 'hostname' => 'attacker.example'])->push(['success' => true, 'hostname' => 'eventi.fabiodalez.it'])]);
    $this->postJson($this->url, $payload)->assertUnprocessable();
    Mail::assertNothingSent();
    $this->postJson($this->url, $payload)->assertOk();
    Mail::assertSentCount(1);
});

it('fails closed when disabled or captcha is unconfigured and validates content', function (): void {
    $this->venue->update(['contact_mode' => ContactMode::Everyone]);
    config(['contact.recaptcha_site_key' => null, 'contact.recaptcha_secret_key' => null]);
    $this->getJson($this->url)->assertJsonPath('data.guests', false);
    $this->postJson($this->url, ['name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Vorrei informazioni sull’accesso.'])->assertForbidden();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($this->url, ['message' => 'no'])->assertUnprocessable();
    $this->venue->update(['contact_mode' => ContactMode::Disabled]);
    $this->postJson($this->url, ['message' => 'Vorrei informazioni sull’accesso.'])->assertNotFound();
    Mail::assertNothingSent();
});

it('also supports active organizers and hides their private delivery address', function (): void {
    $owner = User::factory()->create();
    $organizer = Organizer::query()->create(['city_id' => $this->city->id, 'owner_id' => $owner->id, 'name' => 'Organizzatore test', 'slug' => 'organizzatore-test', 'is_active' => true, 'contact_mode' => ContactMode::Members, 'contact_email' => 'organizer-private@example.test']);
    $url = '/api/v1/organizers/'.$organizer->slug.'/contact';
    $this->getJson($url)->assertOk()->assertJsonPath('data.enabled', true)->assertDontSee('organizer-private@example.test');
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($url, ['message' => 'Vorrei informazioni sul programma.'])->assertOk();
    Mail::assertSent(PublicContact::class, fn ($mail): bool => $mail->hasTo('organizer-private@example.test'));
    $organizer->update(['is_active' => false]);
    $this->postJson($url, ['message' => 'Vorrei informazioni sul programma.'])->assertNotFound();
});
