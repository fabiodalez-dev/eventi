<?php

declare(strict_types=1);

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\VenueApplicationReceived;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * P13 — registrare o rivendicare dall'app (W-AP-01…06). Le stesse azioni e
 * gli stessi tetti dei moduli del sito, ma solo da collegati con l'email
 * confermata: niente account creato dal modulo, niente Turnstile, e ogni
 * richiesta entra subito in coda.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->city = testCity();
    $this->user = User::factory()->create(['email' => 'Marta@Esempio.test', 'name' => 'Marta Rossi']);
});

/** @return array<string, mixed> */
function venueApplicationFields(array $override = []): array
{
    return [
        'venue_name' => 'Circolo del Ponte',
        'type' => 'circolo',
        'address' => 'Via Roma 1',
        'postal_code' => '35100',
        'municipality' => 'Padova',
        'website' => 'https://circolo.example.test',
        'capacity' => 120,
        'description' => 'Concerti e letture.',
        'contact_name' => 'Marta Rossi',
        'contact_role' => 'Presidente',
        'message' => 'Ogni venerdì musica dal vivo.',
        ...$override,
    ];
}

it('chiede l’accesso con l’email confermata a tutte le rotte', function (): void {
    $this->getJson('/api/v1/me/venue-applications/options')->assertUnauthorized();
    $this->postJson('/api/v1/me/venue-applications', venueApplicationFields())->assertUnauthorized();

    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson('/api/v1/me/venue-applications', venueApplicationFields())
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->postJson('/api/v1/me/organizer-applications', ['venue_name' => 'Collettivo', 'contact_name' => 'Marta'])
        ->assertForbidden();
    expect(VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->count())->toBe(0);
});

it('dà gli elenchi del modulo', function (): void {
    Sanctum::actingAs($this->user);

    $options = $this->getJson('/api/v1/me/venue-applications/options')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($options->json('data.types'))->toContain(['value' => 'circolo', 'label' => __('enums.venue_type.circolo')])
        ->and($options->json('data.municipality'))->toBe($this->city->name)
        ->and($options->json('data.account_email'))->toBe($this->user->email)
        ->and($options->json('data.limits.pending'))->toBe(3);
});

it('registra un locale con l’immagine e lo mette subito in coda, a nome dell’account', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->post('/api/v1/me/venue-applications', venueApplicationFields([
        // L'email scritta non conta: risponde quella dell'account.
        'contact_email' => 'altra@esempio.test',
        'image' => UploadedFile::fake()->image('copertina.jpg', 800, 600),
    ]), ['Accept' => 'application/json'])->assertCreated();

    $application = VenueApplication::query()->sole();
    expect($response->json('data.state'))->toBe('queued')
        ->and($response->json('data.message'))->toBe(__('forms.application.sent.queued'))
        ->and($response->json('data.application.id'))->toBe($application->id)
        ->and($response->json('data.application.state'))->toBe('pending')
        ->and($application->kind())->toBe(ApplicationKind::Venue)
        ->and($application->user_id)->toBe($this->user->id)
        ->and($application->contact_email)->toBe('marta@esempio.test')
        ->and($application->isAwaitingEmail())->toBeFalse()
        ->and($application->venue_data)->toMatchArray(['postal_code' => '35100', 'municipality' => 'Padova', 'capacity' => 120])
        ->and($application->image_path)->toStartWith('venue-applications/');
    Storage::disk('local')->assertExists((string) $application->image_path);
    Notification::assertSentTo($this->user, VenueApplicationReceived::class);
});

it('respinge i campi come il modulo del sito, con gli errori sotto i campi', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/me/venue-applications', venueApplicationFields([
        'venue_name' => '',
        'postal_code' => '351',
        'type' => 'astronave',
        'contact_phone' => 'chiamami',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['venue_name', 'postal_code', 'type', 'contact_phone'], 'error.fields');

    $this->post('/api/v1/me/venue-applications', venueApplicationFields(['image' => UploadedFile::fake()->image('piccola.jpg', 200, 100)]), ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['image'], 'error.fields');
    expect(VenueApplication::query()->count())->toBe(0);
});

it('ferma la quarta richiesta aperta con il messaggio del sito', function (): void {
    VenueApplication::factory()->count(3)->create(['user_id' => $this->user->id]);
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/me/venue-applications', venueApplicationFields())
        ->assertStatus(409)->assertJsonPath('error.message', __('forms.application.limit_reached'));
    $this->postJson('/api/v1/me/organizer-applications', ['venue_name' => 'Collettivo', 'contact_name' => 'Marta'])
        ->assertStatus(409);
});

it('cerca nel catalogo e dice quali locali si possono rivendicare', function (): void {
    $libero = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria del Ponte']);
    $gestito = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Bar del Ponte']);
    $gestito->members()->attach(User::factory()->create(), ['role' => 'owner', 'accepted_at' => now()]);
    Venue::factory()->create(['city_id' => $this->city->id, 'name' => 'Ponte in bozza', 'status' => 'draft']);
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/venue-applications/lookup?q=Po')->assertUnprocessable();
    $found = collect($this->getJson('/api/v1/venue-applications/lookup?q=ponte')->assertOk()->json('data'))->keyBy('slug');

    expect($found->keys()->sort()->values()->all())->toBe(collect([$libero->slug, $gestito->slug])->sort()->values()->all())
        ->and($found[$libero->slug]['claimable'])->toBeTrue()
        ->and($found[$gestito->slug]['claimable'])->toBeFalse();
});

it('rivendica un locale senza referente, una volta sola', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria Importata']);
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/venues/'.$venue->slug.'/claim')->assertOk()
        ->assertJsonPath('data.name', 'Osteria Importata')->assertJsonPath('data.id', $venue->id);
    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', ['contact_name' => 'Marta Rossi', 'message' => 'Corto'])
        ->assertUnprocessable()->assertJsonValidationErrors(['message'], 'error.fields');

    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', ['contact_name' => 'Marta Rossi', 'message' => 'Sono la titolare dal 2019.'])
        ->assertCreated()->assertJsonPath('data.state', 'queued')->assertJsonPath('data.application.kind', 'claim');
    $claim = VenueApplication::query()->sole();
    expect($claim->venue_id)->toBe($venue->id)
        ->and($claim->venue_name)->toBe('Osteria Importata')
        ->and($claim->contact_email)->toBe('marta@esempio.test');

    // Una seconda rivendicazione aperta per lo stesso locale no.
    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', ['contact_name' => 'Marta Rossi', 'message' => 'Ci riprovo, sono sempre io.'])
        ->assertStatus(409)->assertJsonPath('error.message', __('forms.claim.limit_reached'));
});

it('non lascia rivendicare un locale con un referente, una bozza o uno che non c’è', function (): void {
    $gestito = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $gestito->members()->attach(User::factory()->create(), ['role' => 'owner', 'accepted_at' => now()]);
    $bozza = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/venues/'.$gestito->slug.'/claim')->assertNotFound()->assertJsonPath('error.message', __('forms.claim.already_managed'));
    $this->postJson('/api/v1/venues/'.$gestito->slug.'/claim', ['contact_name' => 'Marta', 'message' => 'Sono la titolare dal 2019.'])->assertNotFound();
    $this->postJson('/api/v1/venues/'.$bozza->slug.'/claim', ['contact_name' => 'Marta', 'message' => 'Sono la titolare dal 2019.'])->assertNotFound();
    $this->getJson('/api/v1/venues/nessuno/claim')->assertNotFound();
    expect(VenueApplication::query()->count())->toBe(0);
});

it('registra un’organizzazione, in coda subito', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/me/organizer-applications', ['venue_name' => 'Collettivo Notturno', 'website' => 'https://collettivo.example.test', 'contact_name' => 'Marta Rossi', 'message' => 'Rassegne itineranti.'])
        ->assertCreated()->assertJsonPath('data.state', 'organizer')
        ->assertJsonPath('data.message', __('forms.application.sent.organizer'))
        ->assertJsonPath('data.application.kind', 'organizer');

    $application = VenueApplication::query()->sole();
    expect($application->kind())->toBe(ApplicationKind::Organizer)
        ->and($application->user_id)->toBe($this->user->id)
        ->and($application->contact_email)->toBe('marta@esempio.test')
        ->and($application->isAwaitingEmail())->toBeFalse();
});

it('conferma ed elimina solo le proprie richieste in attesa di conferma', function (): void {
    $mia = VenueApplication::factory()->create(['user_id' => $this->user->id, 'email_confirmed_at' => null]);
    $daEliminare = VenueApplication::factory()->create(['user_id' => $this->user->id, 'email_confirmed_at' => null, 'image_path' => 'venue-applications/foto.jpg']);
    Storage::disk('local')->put('venue-applications/foto.jpg', 'x');
    $inCoda = VenueApplication::factory()->create(['user_id' => $this->user->id]);
    $altrui = VenueApplication::factory()->create(['email_confirmed_at' => null]);
    Sanctum::actingAs($this->user);

    $list = collect($this->getJson('/api/v1/me/venue-applications')->assertOk()->json('data'))->keyBy('id');
    expect($list[$mia->id]['can_confirm'])->toBeTrue()->and($list[$inCoda->id]['can_confirm'])->toBeFalse();

    $this->postJson('/api/v1/me/venue-applications/'.$altrui->id.'/confirm')->assertNotFound();
    $this->postJson('/api/v1/me/venue-applications/'.$inCoda->id.'/confirm')->assertNotFound();
    $this->deleteJson('/api/v1/me/venue-applications/'.$altrui->id)->assertNotFound();

    $this->postJson('/api/v1/me/venue-applications/'.$mia->id.'/confirm')->assertOk()->assertJsonPath('data.message', __('forms.application.confirmed'));
    expect($mia->fresh()->isAwaitingEmail())->toBeFalse();
    Notification::assertSentTo($this->user, VenueApplicationReceived::class);
    // Già in coda: non si conferma due volte.
    $this->postJson('/api/v1/me/venue-applications/'.$mia->id.'/confirm')->assertNotFound();

    $this->deleteJson('/api/v1/me/venue-applications/'.$daEliminare->id)->assertOk()->assertJsonPath('data.message', __('forms.application.discarded'));
    expect(VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->whereKey($daEliminare->id)->exists())->toBeFalse()
        ->and(VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->whereKey($altrui->id)->exists())->toBeTrue();
    Storage::disk('local')->assertMissing('venue-applications/foto.jpg');
});

it('dice nell’elenco quale soggetto apre il pannello e dove riprovare dopo un rifiuto', function (): void {
    $accolta = VenueApplication::factory()->approved()->create(['user_id' => $this->user->id]);
    $accolta->venue->members()->attach($this->user, ['role' => 'owner', 'accepted_at' => now()]);
    $libero = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $respinta = VenueApplication::factory()->rejected()->create(['user_id' => $this->user->id, 'kind' => ApplicationKind::Claim, 'venue_id' => $libero->id, 'venue_name' => $libero->name]);
    $respintaOrg = VenueApplication::factory()->rejected()->create(['user_id' => $this->user->id, 'kind' => ApplicationKind::Organizer]);
    Sanctum::actingAs($this->user);

    $list = collect($this->getJson('/api/v1/me/venue-applications')->assertOk()->json('data'))->keyBy('id');
    expect($list[$accolta->id]['subject'])->toBe(['type' => 'venue', 'id' => $accolta->venue->id])
        ->and($list[$accolta->id]['retry'])->toBeNull()
        ->and($list[$respinta->id]['subject'])->toBeNull()
        ->and($list[$respinta->id]['retry'])->toBe(['form' => 'claim', 'venue_slug' => $libero->slug])
        ->and($list[$respintaOrg->id]['retry'])->toBe(['form' => 'organizer', 'venue_slug' => null])
        ->and($respinta->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

it('consuma lo stesso secchio del modulo del sito', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    // Dieci invii dal sito, anche respinti, consumano il tetto dell'ora.
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->user, 'web')->post(route('venues.claim.store', $venue), [])->assertRedirect();
    }

    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', ['contact_name' => 'Marta', 'message' => 'Sono la titolare dal 2019.'])->assertTooManyRequests();
    expect(VenueApplication::query()->count())->toBe(0);
});
