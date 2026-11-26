<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\VenueApplicationRejected;
use App\Notifications\VenueModerated;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R4 — Locali, richieste, organizzatori nell'app della redazione
 * (R-VN-01…16, R-AP-01…06, R-OR-01…02). La griglia di ogni area: moderatore,
 * admin, super admin, referente di un locale, utente comune, token non
 * sbloccato, password non recente sulle azioni pericolose. Le regole sono
 * quelle del pannello: il moderatore legge e modera, admin e super admin
 * modificano, gestiscono le persone, il ticketing e gli organizzatori.
 */
beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);

    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->pending = Venue::factory()->create(['city_id' => $this->s->city->id, 'name' => 'Osteria in attesa', 'status' => VenueStatus::Pending]);
});

/** Una richiesta `GET`/`POST`/… con il token sbloccato di una persona. */
function r4(User $user, bool $unlocked = true, int $passwordMinutesAgo = 0): mixed
{
    return EditorialSession::as(test(), EditorialSession::token($user, unlocked: $unlocked, passwordMinutesAgo: $passwordMinutesAgo));
}

it('apre l’elenco dei locali a tutta la redazione e lo chiude a referenti e utenti', function (): void {
    foreach ([$this->s->ownerA, $this->s->editorA, $this->s->plainUser] as $outsider) {
        r4($outsider)->getJson('/api/v1/editorial/venues')->assertNotFound();
    }
    r4($this->s->moderator, unlocked: false)->getJson('/api/v1/editorial/venues')
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');

    $moderator = r4($this->s->moderator)->getJson('/api/v1/editorial/venues?tab=pending')->assertOk();
    expect($moderator->json('data'))->toHaveCount(1)
        ->and($moderator->json('data.0.name'))->toBe('Osteria in attesa')
        ->and($moderator->json('data.0.abilities'))->toMatchArray(['approve' => true, 'reject' => true, 'suspend' => false, 'update' => false, 'members' => false, 'destroy' => false, 'ticketing' => false])
        ->and($moderator->json('meta.tabs'))->toMatchArray(['all' => 3, 'pending' => 1, 'approved' => 2, 'suspended' => 0]);

    foreach ([$this->s->admin, $this->superAdmin] as $staff) {
        $row = r4($staff)->getJson('/api/v1/editorial/venues?q=Aurora')->assertOk()->json('data.0');
        expect($row['name'])->toBe('Circolo Aurora')
            ->and($row['abilities'])->toMatchArray(['approve' => false, 'suspend' => true, 'update' => true, 'members' => true, 'destroy' => true, 'ticketing' => true]);
    }
});

it('filtra e ordina come il pannello', function (): void {
    $this->s->venueA->update(['is_verified' => true]);

    $verified = r4($this->s->admin)->getJson('/api/v1/editorial/venues?filter[is_verified]=1')->assertOk();
    expect(collect($verified->json('data'))->pluck('name')->all())->toBe(['Circolo Aurora']);

    $byStatus = r4($this->s->admin)->getJson('/api/v1/editorial/venues?filter[status]=pending')->assertOk();
    expect(collect($byStatus->json('data'))->pluck('id')->all())->toBe([$this->pending->id]);

    $sorted = r4($this->s->admin)->getJson('/api/v1/editorial/venues?sort=-name&per_page=2')->assertOk();
    expect(collect($sorted->json('data'))->pluck('name')->all())->toBe(['Teatro Belzoni', 'Osteria in attesa'])
        ->and($sorted->json('meta.per_page'))->toBe(2)
        ->and($sorted->json('meta.last_page'))->toBe(2);
});

it('lascia moderare al moderatore e chiede la password recente per sospendere', function (): void {
    $base = '/api/v1/editorial/venues/';

    r4($this->s->moderator)->postJson($base.$this->pending->id.'/approve')->assertOk()
        ->assertJsonPath('data.venue.status.value', 'approved');
    $approved = Activity::query()->where('subject_type', $this->pending->getMorphClass())->where('subject_id', $this->pending->id)->latest('id')->firstOrFail();
    expect($approved->getProperty('canale'))->toBe('app');

    // Già approvato: il pannello non mostra il pulsante, l'API risponde 409.
    r4($this->s->moderator)->postJson($base.$this->pending->id.'/approve')->assertStatus(409);

    r4($this->s->moderator)->postJson($base.$this->s->venueB->id.'/reject', [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    r4($this->s->moderator)->postJson($base.$this->s->venueB->id.'/reject', ['reason' => 'Scheda copiata da un altro locale'])->assertOk();
    expect($this->s->venueB->refresh()->status)->toBe(VenueStatus::Rejected)
        ->and($this->s->venueB->rejection_reason)->toBe('Scheda copiata da un altro locale');

    r4($this->s->moderator, passwordMinutesAgo: 16)->postJson($base.$this->s->venueA->id.'/suspend', ['reason' => 'Segnalazioni ripetute'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    r4($this->s->moderator)->postJson($base.$this->s->venueA->id.'/suspend', ['reason' => 'Segnalazioni ripetute'])->assertOk();
    expect($this->s->venueA->refresh()->status)->toBe(VenueStatus::Suspended)
        // La sospensione chiude al referente il pannello del locale.
        ->and(User::query()->findOrFail($this->s->ownerA->id)->canAccessTenant($this->s->venueA))->toBeFalse();
    Notification::assertSentTo($this->s->ownerA, VenueModerated::class);

    r4($this->s->moderator)->postJson($base.$this->s->venueA->id.'/verify')->assertOk()->assertJsonPath('data.venue.is_verified', true);
    r4($this->s->moderator)->postJson($base.$this->s->venueA->id.'/verify', ['verified' => false])->assertOk()->assertJsonPath('data.venue.is_verified', false);

    foreach ([$this->s->ownerA, $this->s->plainUser] as $outsider) {
        r4($outsider)->postJson($base.$this->s->venueB->id.'/approve')->assertNotFound();
    }
});

it('lascia modificare, aprire il ticketing ed eliminare solo ad admin e super admin', function (): void {
    $url = '/api/v1/editorial/venues/'.$this->s->venueA->id;

    r4($this->s->moderator)->getJson($url)->assertOk()->assertJsonPath('data.name', 'Circolo Aurora');
    r4($this->s->moderator)->getJson($url.'/form')->assertForbidden();
    r4($this->s->moderator)->patchJson($url, ['name' => 'Altro nome'])->assertForbidden();
    r4($this->s->moderator)->putJson($url.'/ticketing', ['enabled' => true])->assertForbidden();
    r4($this->s->moderator)->deleteJson($url)->assertForbidden();

    $form = r4($this->s->admin)->getJson($url.'/form')->assertOk();
    expect($form->json('data.editorial.name'))->toBe('Circolo Aurora')
        ->and($form->json('data.editorial_choices.statuses'))->not->toBeEmpty();

    r4($this->s->admin)->patchJson($url, ['name' => ''])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    r4($this->s->admin)->patchJson($url, ['lat' => null])->assertStatus(422);
    r4($this->s->admin)->patchJson($url, ['name' => 'Circolo Aurora Nuova', 'short_description' => 'Musica e incontri', 'is_nonprofit' => true, 'plan' => 'premium'])
        ->assertOk()->assertJsonPath('data.editorial.name', 'Circolo Aurora Nuova')->assertJsonPath('data.short_description', 'Musica e incontri');
    expect($this->s->venueA->refresh()->is_nonprofit)->toBeTrue()
        ->and($this->s->venueA->plan->value)->toBe('premium');

    r4($this->superAdmin)->putJson($url.'/ticketing', ['enabled' => true])->assertOk()->assertJsonPath('data.ticketing_enabled', true);

    r4($this->s->admin, passwordMinutesAgo: 16)->deleteJson($url)->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    r4($this->s->admin)->deleteJson($url)->assertOk();
    expect(Venue::withTrashed()->findOrFail($this->s->venueA->id)->trashed())->toBeTrue();
    r4($this->s->admin)->getJson($url)->assertNotFound();
});

it('crea un locale con i campi obbligatori del modulo del pannello', function (): void {
    $payload = [
        'name' => 'Bottega del Borgo',
        'city_id' => $this->s->city->id,
        'type' => 'bar',
        'address' => 'Via Roma 1',
        'municipality' => 'Padova',
        'zone' => 'Centro',
        'province_code' => 'PD',
        'lat' => 45.40,
        'lng' => 11.87,
    ];

    r4($this->s->moderator)->postJson('/api/v1/editorial/venues', $payload)->assertForbidden();
    $missing = r4($this->s->admin)->postJson('/api/v1/editorial/venues', ['name' => 'Solo nome'])->assertStatus(422);
    expect(array_keys($missing->json('error.fields')))->toContain('city_id', 'address', 'municipality', 'province_code', 'lat', 'lng');

    // «Trova» e «Centra sulla città» del modulo: lo stesso geocodificatore e lo stesso centro del pannello.
    r4($this->s->moderator)->postJson('/api/v1/editorial/venues/locate', ['address' => 'Via Roma 1', 'municipality' => 'Padova'])->assertForbidden();
    r4($this->s->admin)->postJson('/api/v1/editorial/venues/locate', ['address' => 'Via Roma 1', 'municipality' => 'Padova'])
        ->assertOk()->assertJsonPath('data.lat', 45.4111)->assertJsonPath('data.lng', 11.8765);
    $choices = r4($this->s->admin)->getJson('/api/v1/editorial/venues/choices')->assertOk();
    expect($choices->json('data.centers.'.$this->s->city->id.'.0.label'))->toBe((string) $this->s->city->center_lat)
        ->and(collect($choices->json('data.municipalities.'.$this->s->city->id))->pluck('value')->all())->toContain('Padova', 'Abano Terme');

    $created = r4($this->s->admin)->postJson('/api/v1/editorial/venues', $payload)->assertCreated();
    $venue = Venue::query()->findOrFail($created->json('data.id'));
    expect($venue->status)->toBe(VenueStatus::Draft)
        ->and($venue->plan->value)->toBe('free')
        ->and($venue->zone)->toBe('Centro')
        ->and($venue->slug)->not->toBeEmpty();
});

it('gestisce le persone del locale solo da admin, con il registro di sicurezza', function (): void {
    $base = '/api/v1/editorial/venues/'.$this->s->venueB->id.'/members';
    $candidate = User::factory()->create(['email' => 'nuova@locale.example', 'email_verified_at' => now()]);

    r4($this->s->moderator)->getJson($base)->assertForbidden();
    r4($this->s->ownerB)->getJson($base)->assertNotFound();

    $list = r4($this->s->admin)->getJson($base)->assertOk();
    expect(collect($list->json('data.members'))->pluck('id')->all())->toContain($this->s->ownerB->id);

    expect(collect(r4($this->s->admin)->getJson($base.'/candidates?q=nuova')->assertOk()->json('data'))->pluck('id')->all())->toBe([$candidate->id]);
    r4($this->s->admin)->postJson($base, ['user_id' => $candidate->id, 'role' => 'editor'])->assertCreated();
    $granted = Activity::query()->where('log_name', 'sicurezza')->where('description', 'accesso_locale_concesso')->latest('id')->firstOrFail();
    expect($granted->getProperty('canale'))->toBe('app')
        ->and((int) $granted->causer_id)->toBe($this->s->admin->id);

    // Chi è già referente non riceve un secondo invito.
    r4($this->s->admin)->postJson($base.'/invite', ['name' => 'Referente', 'email' => $this->s->ownerB->email, 'role' => 'owner'])->assertStatus(409);
    r4($this->s->admin)->postJson($base.'/invite', ['name' => 'Ospite Nuovo', 'email' => 'ospite@nuovo.example'])->assertCreated();
    expect(User::query()->where('email', 'ospite@nuovo.example')->exists())->toBeTrue();

    r4($this->superAdmin)->patchJson($base.'/'.$candidate->id, ['role' => 'owner', 'accepted_at' => now()->toIso8601String()])->assertOk();
    expect($candidate->refresh()->hasRole(UserRole::VenueOwner->value))->toBeTrue();

    r4($this->s->admin)->deleteJson($base.'/'.$candidate->id)->assertOk();
    expect($this->s->venueB->membersIncludingInvited()->whereKey($candidate->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'accesso_locale_revocato')->exists())->toBeTrue();
});

it('mostra eventi e cronologia del locale e ripristina i contenuti di prima', function (): void {
    $url = '/api/v1/editorial/venues/'.$this->s->venueA->id;

    $events = r4($this->s->moderator)->getJson($url.'/events?filter[status]=draft')->assertOk();
    expect(collect($events->json('data'))->pluck('id')->all())->toBe([$this->s->draftEventA->id]);

    $this->actingAs($this->s->admin);
    $this->s->venueA->update(['short_description' => 'Prima versione']);
    $this->s->venueA->update(['short_description' => 'Seconda versione']);
    app('auth')->forgetGuards();

    $moderator = r4($this->s->moderator)->getJson($url.'/activity')->assertOk();
    expect($moderator->json('data.0.abilities.restore'))->toBeFalse()
        ->and($moderator->json('data.0.changes'))->toContain('Seconda versione');

    $row = r4($this->s->admin)->getJson($url.'/activity')->assertOk()->json('data.0');
    expect($row['abilities']['restore'])->toBeTrue();
    r4($this->s->moderator)->postJson($url.'/activity/'.$row['id'].'/restore')->assertForbidden();
    r4($this->s->admin)->postJson($url.'/activity/'.$row['id'].'/restore')->assertOk();
    expect($this->s->venueA->refresh()->short_description)->toBe('Prima versione');
});

it('dice quando l’indirizzo non si trova', function (): void {
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturnNull();
    app()->instance(AddressGeocoder::class, $geocoder);

    r4($this->s->admin)->postJson('/api/v1/editorial/venues/locate', ['address' => 'Via che non esiste'])
        ->assertStatus(422)->assertJsonPath('error.fields.address.0', __('admin.map.not_located'));
});

it('esporta l’elenco dei locali in un foglio Excel', function (): void {
    $response = r4($this->s->moderator)->get('/api/v1/editorial/venues/export?tab=approved');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($response->headers->get('cache-control'))->toContain('no-store');
    r4($this->s->plainUser)->getJson('/api/v1/editorial/venues/export')->assertNotFound();
});

it('cambia le immagini del locale solo a chi può modificarlo', function (): void {
    Storage::fake('public');
    $url = '/api/v1/editorial/venues/'.$this->s->venueB->id.'/media';
    $image = fn (): UploadedFile => UploadedFile::fake()->image('logo.jpg', 600, 600);

    r4($this->s->moderator)->postJson($url.'/logo', ['image' => $image()])->assertForbidden();
    r4($this->s->admin)->postJson($url.'/logo', ['image' => $image()])->assertOk()->assertJsonPath('data.collection', 'logo');
    expect(r4($this->s->admin)->getJson($url)->assertOk()->json('data.logo'))->not->toBeNull();
    r4($this->s->admin)->deleteJson($url.'/logo')->assertOk();
});

it('lascia decidere le richieste alla redazione e chiede la password per eliminarle', function (): void {
    $sender = User::factory()->create(['email' => 'mittente@bottega.example']);
    $application = VenueApplication::factory()->create(['user_id' => $sender->id, 'venue_name' => 'Bottega Nuova', 'kind' => 'venue']);
    $other = VenueApplication::factory()->create(['venue_name' => 'Altra Bottega', 'kind' => 'venue']);
    VenueApplication::factory()->rejected()->create(['venue_name' => 'Respinta']);
    $base = '/api/v1/editorial/applications';

    foreach ([$this->s->ownerA, $this->s->plainUser] as $outsider) {
        r4($outsider)->getJson($base)->assertNotFound();
    }

    $list = r4($this->s->moderator)->getJson($base)->assertOk();
    expect($list->json('meta.tabs'))->toMatchArray(['pending' => 2, 'rejected' => 1, 'approved' => 0, 'all' => 3])
        ->and(collect($list->json('data'))->pluck('venue_name')->sort()->values()->all())->toBe(['Altra Bottega', 'Bottega Nuova'])
        ->and($list->json('data.0.abilities'))->toMatchArray(['approve' => true, 'reject' => true]);

    r4($this->s->moderator)->getJson($base.'/'.$application->id)->assertOk()->assertJsonPath('data.account.email', 'mittente@bottega.example');
    r4($this->s->moderator)->getJson($base.'/'.$application->id.'/image')->assertNotFound();

    r4($this->s->moderator)->patchJson($base.'/'.$application->id.'/notes', ['notes' => 'Chiamato il gestore'])->assertOk();
    expect($application->refresh()->notes)->toBe('Chiamato il gestore')
        ->and($application->status)->toBe(ApplicationStatus::Pending);

    r4($this->s->moderator)->postJson($base.'/'.$other->id.'/reject', ['notes' => 'Non è un locale', 'notify' => true, 'message' => 'Riprova più avanti'])
        ->assertOk()->assertJsonPath('data.application.status.value', 'rejected');
    Notification::assertSentTo($other->user, VenueApplicationRejected::class);
    r4($this->s->moderator)->postJson($base.'/'.$other->id.'/reject', ['notes' => 'Ancora'])->assertStatus(409);

    $approved = r4($this->s->moderator)->postJson($base.'/'.$application->id.'/approve')->assertOk();
    expect($approved->json('data.created.kind'))->toBe('venue')
        ->and(Venue::query()->whereKey($approved->json('data.created.id'))->exists())->toBeTrue();
    r4($this->s->moderator)->postJson($base.'/'.$application->id.'/approve')->assertStatus(422);

    r4($this->s->moderator, passwordMinutesAgo: 16)->deleteJson($base.'/'.$other->id)->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    r4($this->superAdmin)->deleteJson($base.'/'.$other->id)->assertOk();
    expect(VenueApplication::query()->whereKey($other->id)->exists())->toBeFalse();
});

it('lascia gli organizzatori ad admin e super admin', function (): void {
    $owner = User::factory()->create(['email' => 'responsabile@associazione.example']);
    $helper = User::factory()->create(['email' => 'aiuto@associazione.example']);

    r4($this->s->moderator)->getJson('/api/v1/editorial/organizers')->assertForbidden();
    r4($this->s->plainUser)->getJson('/api/v1/editorial/organizers')->assertNotFound();

    r4($this->s->admin)->postJson('/api/v1/editorial/organizers', ['name' => 'Associazione'])->assertStatus(422);
    $created = r4($this->s->admin)->postJson('/api/v1/editorial/organizers', [
        'name' => 'Associazione Musica Insieme',
        'city_id' => $this->s->city->id,
        'owner_id' => $owner->id,
        'users' => [$helper->id],
        'website' => 'https://musicainsieme.example',
    ])->assertCreated();
    $organizer = Organizer::query()->findOrFail($created->json('data.id'));
    expect($organizer->is_active)->toBeFalse()
        ->and($organizer->users()->pluck('users.id')->all())->toBe([$helper->id]);

    r4($this->superAdmin)->patchJson('/api/v1/editorial/organizers/'.$organizer->id, ['is_active' => true, 'contact_mode' => 'everyone'])
        ->assertStatus(422)->assertJsonPath('error.fields.contact_email.0', fn (string $message): bool => $message !== '');
    r4($this->superAdmin)->patchJson('/api/v1/editorial/organizers/'.$organizer->id, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);

    $list = r4($this->s->admin)->getJson('/api/v1/editorial/organizers?q=Musica')->assertOk();
    expect($list->json('data.0.name'))->toBe('Associazione Musica Insieme')
        ->and($list->json('data.0.abilities.destroy'))->toBeFalse();
    expect(collect(r4($this->s->admin)->getJson('/api/v1/editorial/organizers/people?q=responsabile')->assertOk()->json('data'))->pluck('id')->all())->toBe([$owner->id]);
});

it('dice all’app chi gestisce gli organizzatori', function (): void {
    expect(r4($this->s->moderator)->getJson('/api/v1/editorial/desk')->json('data.permissions.organizers_manage'))->toBeFalse()
        ->and(r4($this->s->admin)->getJson('/api/v1/editorial/desk')->json('data.permissions.organizers_manage'))->toBeTrue();
});
