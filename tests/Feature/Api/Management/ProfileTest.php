<?php

declare(strict_types=1);

use App\Enums\AccessibilityFeature;
use App\Enums\ContactMode;
use App\Enums\TransitMode;
use App\Filament\Organizer\Pages\Profile as OrganizerProfilePage;
use App\Filament\Venue\Pages\VenueProfile;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueNotice;
use App\Notifications\ContactEmailConfirmationRequested;
use App\Policies\OrganizerPolicy;
use App\Services\Contact\ContactEmailConfirmation;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Il profilo del locale e dell'organizzazione nell'app di gestione (P4): la
 * stessa scheda della pagina «Il tuo locale» e della pagina «Profilo»
 * dell'organizzatore, con gli stessi permessi (referente; solo chi ha creato
 * l'organizzazione), le stesse regole sui campi e lo stesso salvataggio
 * (`UpdateVenueProfile`, `UpdateOrganizerProfile`). L'app salva una sezione
 * alla volta: le chiavi che non manda restano come sono.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->url = '/api/v1/management/venues/'.$this->s->venueA->id;
    $this->organizerOwner = User::factory()->create();
    $this->organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $this->organizerOwner->id, 'is_active' => true]);
    $this->organizerMember = User::factory()->create();
    $this->organizer->users()->attach($this->organizerMember);
    $this->organizerUrl = '/api/v1/management/organizers/'.$this->organizer->id;
});

it('apre la scheda al solo referente: il collaboratore riceve 403, gli estranei 404', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->url.'/profile')->assertOk()
        ->assertJsonPath('data.id', $this->s->venueA->id)
        ->assertJsonPath('data.name', 'Circolo Aurora')
        ->assertJsonPath('data.public_url', route('venues.show', $this->s->venueA->slug))
        ->assertJsonPath('data.calendar_url', route('venues.calendar', $this->s->venueA->slug))
        ->assertJsonPath('data.notices.min_days_between', 7)
        ->assertJsonPath('data.choices.district_municipality', 'Padova')
        ->assertJsonStructure(['data' => [
            'type' => ['value', 'label'], 'city' => ['name', 'timezone'], 'opening_hours', 'closures', 'socials', 'transit', 'accessibility', 'info',
            'content_details', 'seo' => ['title', 'description', 'image'],
            'contact' => ['mode', 'email', 'confirmed', 'pending', 'can_resend', 'link_days', 'modes'],
            'widget' => ['url', 'code'], 'choices' => ['weekdays', 'municipalities', 'districts', 'parking_types', 'notice_kinds'], 'limits',
        ]]);
    expect($this->getJson($this->url.'/profile')->json('data.widget.code'))->toContain('<iframe', '/widget/'.$this->s->venueA->slug);

    Sanctum::actingAs($this->s->editorA);
    $this->getJson($this->url.'/profile')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->patchJson($this->url.'/profile', ['short_description' => 'Scritta dal collaboratore'])->assertForbidden();
    $this->postJson($this->url.'/notices', ['kind' => 'info', 'body' => 'Ciao'])->assertForbidden();
    $this->postJson($this->url.'/contact/resend')->assertForbidden();

    foreach ([$this->s->ownerB, $this->s->plainUser, $this->organizerOwner] as $stranger) {
        Sanctum::actingAs($stranger);
        $this->getJson($this->url.'/profile')->assertNotFound();
    }

    // Le rotte del solo locale non esistono sotto l'organizzazione.
    Sanctum::actingAs($this->organizerOwner);
    $this->postJson($this->organizerUrl.'/notices', ['kind' => 'info', 'body' => 'Ciao'])->assertNotFound();
});

it('chiude la scheda del locale sospeso anche al referente', function (): void {
    $this->s->venueA->forceFill(['status' => 'suspended'])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->url.'/profile')->assertNotFound();
    $this->patchJson($this->url.'/profile', ['short_description' => 'x'])->assertNotFound();
});

it('salva una sezione alla volta e lascia stare le altre', function (): void {
    $this->s->venueA->forceFill(['phone' => '+39 049 000000', 'address' => 'Via Vecchia 1', 'capacity' => 90])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->url.'/profile', ['short_description' => 'Circolo sul Piovego', 'description' => '<p>Musica <strong>dal vivo</strong></p><script>alert(1)</script>'])
        ->assertOk()
        ->assertJsonPath('data.short_description', 'Circolo sul Piovego')
        ->assertJsonPath('data.phone', '+39 049 000000')
        ->assertJsonPath('data.capacity', 90);

    $venue = $this->s->venueA->fresh();
    expect($venue->description)->toBe('<p>Musica <strong>dal vivo</strong></p>')
        ->and($venue->address)->toBe('Via Vecchia 1')
        ->and($venue->name)->toBe('Circolo Aurora');

    // Nome, tipo e stato non sono campi della scheda: restano della redazione.
    $this->patchJson($this->url.'/profile', ['name' => 'Altro nome', 'status' => 'approved', 'auto_publish' => true])->assertOk();
    expect($this->s->venueA->fresh()->name)->toBe('Circolo Aurora');
});

it('traduce gli orari in righe e le chiusure come il pannello', function (): void {
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    Sanctum::actingAs($this->s->ownerA);

    $response = $this->patchJson($this->url.'/profile', [
        'opening_hours' => [
            ['day' => 'fri', 'open' => '18:00', 'close' => '02:00'],
            ['day' => 'sat', 'open' => '10:00', 'close' => '13:00'],
            ['day' => 'sat', 'open' => '18:00', 'close' => '02:00'],
        ],
        'closures' => [
            ['from' => '2026-12-24', 'to' => '2027-01-06', 'note' => '  Ferie di Natale '],
            // Finita: sparisce al salvataggio.
            ['from' => '2026-08-01', 'to' => '2026-08-20', 'note' => null],
            ['from' => '2026-12-01', 'to' => '2026-12-01'],
        ],
        'capacity' => 180,
    ])->assertOk();

    expect($this->s->venueA->fresh()->opening_hours)->toBe([
        'fri' => [['open' => '18:00', 'close' => '02:00']],
        'sat' => [['open' => '10:00', 'close' => '13:00'], ['open' => '18:00', 'close' => '02:00']],
    ]);
    expect($response->json('data.opening_hours'))->toHaveCount(3)
        ->and($response->json('data.opening_hours.0'))->toBe(['day' => 'fri', 'open' => '18:00', 'close' => '02:00'])
        ->and($response->json('data.closures'))->toBe([
            ['from' => '2026-12-01', 'to' => '2026-12-01', 'note' => null],
            ['from' => '2026-12-24', 'to' => '2027-01-06', 'note' => 'Ferie di Natale'],
        ]);

    // Svuotare toglie tutto.
    $this->patchJson($this->url.'/profile', ['opening_hours' => [], 'closures' => []])->assertOk()
        ->assertJsonPath('data.opening_hours', [])
        ->assertJsonPath('data.closures', []);
    expect($this->s->venueA->fresh()->opening_hours)->toBeNull();
    CarbonImmutable::setTestNow();
});

it('rifiuta righe e tetti sbagliati sul campo giusto', function (array $payload, string $field): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->url.'/profile', $payload)->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => [$field]]]);
})->with([
    'orario senza giorno' => [['opening_hours' => [['open' => '18:00', 'close' => '22:00']]], 'opening_hours.0.day'],
    'orario con giorno inventato' => [['opening_hours' => [['day' => 'xyz', 'open' => '18:00', 'close' => '22:00']]], 'opening_hours.0.day'],
    'orario con i secondi' => [['opening_hours' => [['day' => 'mon', 'open' => '18:00:00', 'close' => '22:00']]], 'opening_hours.0.open'],
    'troppe righe di orario' => [['opening_hours' => array_fill(0, 29, ['day' => 'mon', 'open' => '18:00', 'close' => '22:00'])], 'opening_hours'],
    'chiusura al rovescio' => [['closures' => [['from' => '2027-02-10', 'to' => '2027-02-01']]], 'closures.0.to'],
    'troppe chiusure' => [['closures' => array_fill(0, 21, ['from' => '2027-02-10', 'to' => '2027-02-11'])], 'closures'],
    'nota di chiusura lunga' => [['closures' => [['from' => '2027-02-10', 'to' => '2027-02-11', 'note' => str_repeat('x', 121)]]], 'closures.0.note'],
    'tredici social' => [['socials' => array_map(fn (int $i): array => ['name' => 'Social '.$i, 'url' => 'https://example.test/'.$i], range(1, 13))], 'socials'],
    'social eseguibile' => [['socials' => [['name' => 'instagram', 'url' => 'javascript:alert(1)']]], 'socials.0.url'],
    'nome social HTML' => [['socials' => [['name' => '<script>', 'url' => 'https://example.test']]], 'socials.0.name'],
    'come arrivare senza testo' => [['transit' => [['mode' => TransitMode::Bus->value, 'text' => '']]], 'transit'],
    'scheda con tredici righe' => [['info' => array_fill(0, 13, ['label' => 'Guardaroba', 'value' => 'Sì'])], 'info'],
    'accessibilità inventata' => [['accessibility' => ['teletrasporto' => true]], 'accessibility'],
    'voce libera senza icona valida' => [['content_details' => ['practical_custom' => [['label' => 'Ingresso', 'icon' => 'bomba', 'text' => '']]]], 'content_details.practical_custom.0.icon'],
    'tredici voci libere' => [['content_details' => ['practical_custom' => array_fill(0, 13, ['label' => 'Ingresso', 'icon' => 'map-pin', 'text' => ''])]], 'content_details.practical_custom'],
    'chiave editoriale sconosciuta' => [['content_details' => ['agenda' => []]], 'content_details'],
    'indicizzazione dalla gestione' => [['seo' => ['indexing' => 'excluded']], 'seo'],
    'comune fuori provincia' => [['municipality' => 'Milano', 'address' => 'Via Roma 1'], 'municipality'],
    'Padova senza quartiere' => [['municipality' => 'Padova', 'address' => 'Via Roma 1'], 'zone'],
    'indirizzo vuoto' => [['address' => ''], 'address'],
    'messaggi accesi senza recapito' => [['contact_mode' => 'members'], 'contact_email'],
]);

it('fonde «Prima di andare» con le informazioni editoriali invece di cancellarle', function (): void {
    $this->s->venueA->forceFill(['content_details' => ['introduction' => '<p>Storia del circolo</p>', 'parking_type' => 'free'], 'seo' => ['title' => 'Circolo', 'indexing' => 'excluded']])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->url.'/profile', ['content_details' => [
        'age_groups' => ['3-5', '6-10'], 'stroller' => 'yes', 'accessibility' => 'no',
        'practical_custom' => [['label' => 'Ingresso', 'icon' => 'map-pin', 'text' => '<p>Porta laterale</p>']],
    ]])->assertOk()
        ->assertJsonPath('data.content_details.introduction', '<p>Storia del circolo</p>')
        ->assertJsonPath('data.content_details.stroller', 'yes');

    $this->patchJson($this->url.'/profile', ['seo' => ['description' => 'Musica e incontri']])->assertOk()
        ->assertJsonPath('data.seo.title', 'Circolo')
        ->assertJsonPath('data.seo.description', 'Musica e incontri');

    $venue = $this->s->venueA->fresh();
    expect($venue->content_details['parking_type'])->toBe('free')
        ->and($venue->content_details['age_groups'])->toBe(['3-5', '6-10'])
        // L'indicizzazione la decide la redazione: la gestione non la tocca.
        ->and($venue->seo['indexing'])->toBe('excluded');
});

it('salva dove, contatti, accessibilità e scheda nella forma del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->url.'/profile', [
        'address' => 'Via dei Livello, 32', 'address_extra' => 'Ingresso dal cortile', 'postal_code' => '35139',
        'municipality' => 'Padova', 'zone' => 'Portello', 'lat' => 45.4093, 'lng' => 11.8891,
        'phone' => '+39 049 123456', 'email' => 'info@circolo.test', 'website' => 'https://circolo.test',
        'socials' => [['name' => 'Instagram', 'url' => 'https://instagram.com/circolo'], ['name' => 'Facebook', 'url' => 'https://facebook.com/circolo']],
        'transit' => [['mode' => TransitMode::Tram->value, 'text' => 'Tram SIR1, fermata Ospedali.']],
        'accessibility' => [AccessibilityFeature::StepFreeEntrance->value => true, AccessibilityFeature::TactilePath->value => false, AccessibilityFeature::GuideDogAllowed->value => null],
        'info' => [['label' => 'Guardaroba', 'value' => 'Gratuito']],
        'requires_membership' => true, 'membership_notes' => '<p>Tessera ARCI in sede.</p>',
    ])->assertOk()
        ->assertJsonPath('data.socials.1', ['name' => 'Facebook', 'url' => 'https://facebook.com/circolo'])
        ->assertJsonPath('data.accessibility.'.AccessibilityFeature::TactilePath->value, false)
        ->assertJsonMissingPath('data.accessibility.'.AccessibilityFeature::GuideDogAllowed->value);

    $venue = $this->s->venueA->fresh();
    expect($venue->socials)->toBe(['Instagram' => 'https://instagram.com/circolo', 'Facebook' => 'https://facebook.com/circolo'])
        ->and($venue->zone)->toBe('Portello')
        ->and((float) $venue->lat)->toBe(45.4093)
        ->and($venue->accessibility->has(AccessibilityFeature::StepFreeEntrance))->toBeTrue()
        ->and($venue->accessibility->declares(AccessibilityFeature::TactilePath))->toBeTrue()
        ->and($venue->accessibility->declares(AccessibilityFeature::GuideDogAllowed))->toBeFalse()
        ->and($venue->transit->toArray())->toBe([['mode' => TransitMode::Tram->value, 'text' => 'Tram SIR1, fermata Ospedali.']])
        ->and($venue->info->toArray())->toBe([['label' => 'Guardaroba', 'value' => 'Gratuito']]);

    // Fuori da Padova il quartiere sparisce, come nel pannello.
    $this->patchJson($this->url.'/profile', ['address' => 'Via Roma 1', 'municipality' => 'Este', 'zone' => 'Portello'])->assertOk()
        ->assertJsonPath('data.zone', null);
});

it('accende il modulo di contatto e manda la conferma, poi la reinvia entro i tetti del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->url.'/profile', ['contact_mode' => ContactMode::Members->value, 'contact_email' => 'scrivici@circolo.test'])->assertOk()
        ->assertJsonPath('data.contact.mode', 'members')
        ->assertJsonPath('data.contact.email', 'scrivici@circolo.test')
        ->assertJsonPath('data.contact.confirmed', false)
        ->assertJsonPath('data.contact.pending', true);
    Notification::assertSentOnDemand(ContactEmailConfirmationRequested::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'scrivici@circolo.test');

    // Gli stessi secchi del pulsante del pannello: tre email al giorno allo stesso indirizzo, una già partita col salvataggio.
    for ($i = 1; $i < ContactEmailConfirmation::RECIPIENT_DAILY_LIMIT; $i++) {
        $this->postJson($this->url.'/contact/resend')->assertOk()->assertJsonPath('data.contact.pending', true);
    }
    $this->postJson($this->url.'/contact/resend')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonPath('error.message', __('contact.confirmation.limit'));

    $this->s->venueA->fresh()->markContactEmailConfirmed();
    $this->postJson($this->url.'/contact/resend')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['contact_email']]]);
    $this->getJson($this->url.'/profile')->assertJsonPath('data.contact.confirmed', true)->assertJsonPath('data.contact.can_resend', false);
});

it('avvisa chi segue il locale una volta ogni sette giorni', function (): void {
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    $follower = User::factory()->create();
    $follower->follows()->create(['followable_type' => 'venue', 'followable_id' => $this->s->venueA->id, 'notify' => true]);
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->url.'/notices', ['kind' => 'pause', 'body' => 'Chiusi per ferie dal 10 al 24 agosto.', 'until' => '2026-11-24'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['until']]]);
    $this->postJson($this->url.'/notices', ['kind' => 'bomba', 'body' => 'x'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['kind']]]);
    $this->postJson($this->url.'/notices', ['kind' => 'info', 'body' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['body']]]);

    $this->postJson($this->url.'/notices', ['kind' => 'pause', 'body' => 'Chiusi per ferie fino a domenica.', 'until' => '2026-11-30'])->assertCreated()
        ->assertJsonPath('data.notice.kind', 'pause')
        ->assertJsonPath('data.notice.until', '2026-11-30')
        ->assertJsonPath('data.notified', 1);
    expect(VenueNotice::query()->where('venue_id', $this->s->venueA->id)->count())->toBe(1);

    $this->getJson($this->url.'/profile')->assertJsonPath('data.notices.latest.kind', 'pause')
        ->assertJsonPath('data.notices.followers', 1)
        ->assertJsonPath('data.notices.next_available_at', '2026-12-02T11:00:00+01:00');

    // Troppo presto: lo stesso errore del modale del pannello, sotto il testo.
    $this->postJson($this->url.'/notices', ['kind' => 'info', 'body' => 'Un altro avviso'])->assertUnprocessable()
        ->assertJsonPath('error.fields.body.0', __('manage.notices.too_soon', ['days' => 7]));

    CarbonImmutable::setTestNow('2026-12-02 11:00:01');
    $this->postJson($this->url.'/notices', ['kind' => 'reopening', 'body' => 'Si riapre!'])->assertCreated();
    CarbonImmutable::setTestNow();
});

it('apre il profilo dell’organizzazione al solo proprietario', function (): void {
    Sanctum::actingAs($this->organizerOwner);
    $this->getJson($this->organizerUrl.'/profile')->assertOk()
        ->assertJsonPath('data.name', 'Collettivo Portici')
        ->assertJsonPath('data.public_url', route('organizers.show', $this->organizer->slug))
        ->assertJsonPath('data.calendar_url', route('organizers.calendar', $this->organizer->slug))
        ->assertJsonPath('data.limits.description', 20000);

    $this->patchJson($this->organizerUrl.'/profile', ['description' => 'Nuova presentazione', 'website' => 'https://portici.test'])->assertOk()
        ->assertJsonPath('data.website', 'https://portici.test');
    $organizer = $this->organizer->fresh();
    expect($organizer->description)->toBe('Nuova presentazione')
        ->and($organizer->email)->toBeNull();

    $this->patchJson($this->organizerUrl.'/profile', ['website' => 'non un indirizzo'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['website']]]);
    $this->patchJson($this->organizerUrl.'/profile', ['description' => str_repeat('x', 20001)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['description']]]);
    $this->patchJson($this->organizerUrl.'/profile', ['contact_mode' => 'everyone'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['contact_email']]]);
    $this->patchJson($this->organizerUrl.'/profile', ['contact_mode' => 'everyone', 'contact_email' => 'posta@portici.test'])->assertOk()
        ->assertJsonPath('data.contact.pending', true);
    $this->postJson($this->organizerUrl.'/contact/resend')->assertOk();

    // Il membro gestisce eventi e biglietteria, non la presentazione.
    Sanctum::actingAs($this->organizerMember);
    $this->getJson($this->organizerUrl.'/profile')->assertForbidden();
    $this->patchJson($this->organizerUrl.'/profile', ['description' => 'Dal membro'])->assertForbidden();
    $this->postJson($this->organizerUrl.'/contact/resend')->assertForbidden();
    $this->getJson('/api/v1/management/subjects')->assertJsonPath('data.organizers.0.permissions.profile_update', false);

    // Un'organizzazione spenta non si apre più.
    $this->organizer->forceFill(['is_active' => false])->saveQuietly();
    Sanctum::actingAs($this->organizerOwner);
    $this->getJson($this->organizerUrl.'/profile')->assertNotFound();
});

it('decide il profilo dell’organizzazione con OrganizerPolicy::updateProfile, nel pannello e nell’API', function (): void {
    $policy = new OrganizerPolicy;
    expect($policy->updateProfile($this->organizerOwner, $this->organizer))->toBeTrue()
        ->and($policy->updateProfile($this->organizerMember, $this->organizer))->toBeFalse()
        ->and($policy->updateProfile($this->s->admin, $this->organizer))->toBeFalse()
        // `update` resta della redazione.
        ->and($policy->update($this->organizerOwner, $this->organizer))->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('organizer'));
    $this->actingAs($this->organizerOwner);
    Filament::setTenant($this->organizer);
    expect(OrganizerProfilePage::canAccess())->toBeTrue();

    $this->organizer->forceFill(['is_active' => false]);
    expect($policy->updateProfile($this->organizerOwner, $this->organizer))->toBeFalse()
        ->and(OrganizerProfilePage::canAccess())->toBeFalse();

    $this->actingAs($this->organizerMember);
    $this->organizer->forceFill(['is_active' => true]);
    expect(OrganizerProfilePage::canAccess())->toBeFalse();
});

/*
 * Parità con la pagina: le regole dei campi nel pannello stanno nello schema
 * Filament e nell'API nel Form Request. Gli stessi valori sbagliati devono
 * essere rifiutati da tutte e due, sullo stesso campo.
 */
it('rifiuta gli stessi valori della pagina «Il tuo locale»', function (string $field, mixed $page, mixed $api): void {
    Filament::setCurrentPanel('venue');
    $this->actingAs($this->s->ownerA);
    Filament::setTenant($this->s->venueA);
    $this->get('/gestione/'.$this->s->venueA->slug.'/locale')->assertOk();

    Livewire::test(VenueProfile::class)
        ->fillForm(['address' => 'Via Roma 1', 'municipality' => 'Padova', 'zone' => 'Portello'])
        ->set('data.'.$field, $page)
        ->call('save')
        ->assertHasErrors(['data.'.$field]);

    // Sul campo o su una sua parte (`socials.0.url`): l'app li mostra entrambi sotto il campo.
    Sanctum::actingAs($this->s->ownerA);
    $fields = array_keys((array) $this->patchJson($this->url.'/profile', [$field => $api])->assertUnprocessable()->json('error.fields'));
    expect(collect($fields)->contains(fn (string $key): bool => $key === $field || str_starts_with($key, $field.'.')))->toBeTrue();
})->with([
    'latitudine fuori intervallo' => ['lat', 91, 91],
    'longitudine fuori intervallo' => ['lng', -181, -181],
    'capienza frazionaria' => ['capacity', 1.5, 1.5],
    'capienza eccessiva' => ['capacity', 1000001, 1000001],
    'telefono non telefonico' => ['phone', '<script>evil()</script>', '<script>evil()</script>'],
    'email non valida' => ['email', 'not-an-email', 'not-an-email'],
    'sito non valido' => ['website', 'non un sito', 'non un sito'],
    'indirizzo troppo lungo' => ['address', str_repeat('x', 256), str_repeat('x', 256)],
    'descrizione breve troppo lunga' => ['short_description', str_repeat('x', 501), str_repeat('x', 501)],
    'social eseguibile' => ['socials', [['key' => 'instagram', 'value' => 'javascript:alert(1)']], [['name' => 'instagram', 'url' => 'javascript:alert(1)']]],
    'come arrivare senza testo' => ['transit', [['mode' => 'bus', 'text' => '']], [['mode' => 'bus', 'text' => '']]],
    'scheda senza valore' => ['info', [['label' => 'Guardaroba', 'value' => '']], [['label' => 'Guardaroba', 'value' => '']]],
]);

it('salva dal pannello come prima, passando dalla stessa azione', function (): void {
    Filament::setCurrentPanel('venue');
    $this->actingAs($this->s->ownerA);
    Filament::setTenant($this->s->venueA);
    $this->s->venueA->forceFill(['content_details' => ['introduction' => '<p>Storia</p>']])->saveQuietly();
    $this->get('/gestione/'.$this->s->venueA->slug.'/locale')->assertOk();

    Livewire::test(VenueProfile::class)
        ->fillForm([
            'address' => 'Via Roma 1', 'municipality' => 'Padova', 'zone' => 'Portello',
            'opening_hours' => [['day' => 'fri', 'open' => '18:00', 'close' => '02:00']],
            'closures' => [['from' => '2099-12-24', 'to' => '2099-12-26', 'note' => 'Natale']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $venue = $this->s->venueA->fresh();
    expect($venue->opening_hours)->toBe(['fri' => [['open' => '18:00', 'close' => '02:00']]])
        ->and($venue->closures)->toBe([['from' => '2099-12-24', 'to' => '2099-12-26', 'note' => 'Natale']])
        ->and($venue->content_details['introduction'])->toBe('<p>Storia</p>');

    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->url.'/profile')->assertJsonPath('data.opening_hours.0', ['day' => 'fri', 'open' => '18:00', 'close' => '02:00']);
});

it('non lascia che un PATCH rimetta un recapito confermato per scorciatoia', function (): void {
    $this->s->venueA->forceFill(['contact_mode' => ContactMode::Members, 'contact_email' => 'scrivici@circolo.test', 'contact_email_verified_at' => now()])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->url.'/profile', ['contact_email' => 'altro@circolo.test'])->assertOk()
        ->assertJsonPath('data.contact.confirmed', false);
    expect(Venue::query()->find($this->s->venueA->id)?->contact_email_verified_at)->toBeNull();
});
