<?php

declare(strict_types=1);

use App\Actions\Account\FollowSubject;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationType;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Admin\Resources\EventSubmissions\EventSubmissionResource;
use App\Filament\Admin\Resources\EventSubmissions\Pages\EditEventSubmission;
use App\Filament\Admin\Resources\EventSubmissions\Pages\ListEventSubmissions;
use App\Models\City;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Flusso «proponi un evento»: una persona qualunque apre una pratica, dal
 * sito o dall'app; la redazione la legge nel pannello e decide. Solo una
 * proposta accolta diventa una scheda pubblica, e chi segue il locale viene
 * avvisato come per qualunque evento nuovo.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Portello']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Una proposta valida come la compila una persona nel modulo del sito. */
function pubPropostaValida(array $overrides = []): array
{
    return [
        'title' => 'Jazz sotto i portici',
        'raw_text' => 'Quartetto jazz dal vivo, ingresso libero.',
        'venue_id' => test()->venue->id,
        'starts_at_hint' => '2026-09-20 21:00',
        'contact_name' => 'Paola Proponente',
        'contact_email' => 'proponente@example.test',
        ...$overrides,
    ];
}

/** Apre la pagina del pannello come il browser, poi prepara Livewire. */
function pubApriPannelloAdmin(User $user, string $url): void
{
    test()->actingAs($user)->get($url)->assertOk();
    Filament::setCurrentPanel('admin');
}

it('porta una proposta dal modulo pubblico alla scheda pubblicata dalla redazione', function (): void {
    $this->get('/proponi-evento')->assertOk()->assertSee(__('forms.submission.title'))->assertSee('Circolo Portello');

    $this->post('/proponi-evento', pubPropostaValida())
        ->assertRedirect(route('submissions.create'))
        ->assertSessionHas('status', __('forms.submission.received'));

    $proposta = EventSubmission::query()->sole();
    expect($proposta->status)->toBe(SubmissionStatus::Pending)
        ->and($proposta->venue_id)->toBe($this->venue->id)
        ->and($proposta->city_id)->toBe($this->city->id)
        ->and($proposta->event_id)->toBeNull()
        // Chi propone scrive l'ora di Padova: in UTC sono le 19:00.
        ->and($proposta->starts_at_hint?->utc()->format('Y-m-d H:i'))->toBe('2026-09-20 19:00')
        ->and(Event::query()->count())->toBe(0);

    // La redazione apre la pratica e la pubblica con la categoria scelta.
    pubApriPannelloAdmin($this->admin, EventSubmissionResource::getUrl('edit', ['record' => $proposta]));
    Livewire::test(EditEventSubmission::class, ['record' => $proposta->id])
        ->assertFormSet(['title' => 'Jazz sotto i portici', 'venue_id' => $this->venue->id])
        ->fillForm(['category_id' => $this->category->id])
        ->callAction('publish')
        ->assertHasNoActionErrors();

    $proposta->refresh();
    $evento = $proposta->event;
    expect($proposta->status)->toBe(SubmissionStatus::Approved)
        ->and($proposta->reviewed_by)->toBe($this->admin->id)
        ->and($evento)->not->toBeNull()
        ->and($evento->status)->toBe(EventStatus::Published)
        ->and($evento->source)->toBe(EventSource::Submission)
        ->and($evento->venue_id)->toBe($this->venue->id)
        ->and($evento->occurrences()->sole()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-20 19:00');

    // Il pubblico vede la scheda, ma non il recapito di chi l'ha proposta.
    auth()->logout();
    $this->get('/eventi/'.$evento->slug)->assertOk()
        ->assertSee('Jazz sotto i portici')
        ->assertSee('Circolo Portello')
        ->assertDontSee('proponente@example.test');
});

it('respinge le proposte incomplete, i robot e i locali inesistenti senza aprire pratiche', function (): void {
    $this->from('/proponi-evento')->post('/proponi-evento', ['title' => '', 'contact_email' => 'non-una-email'])
        ->assertRedirect('/proponi-evento')
        ->assertSessionHasErrors(['title', 'contact_email']);

    // Il campo esca riempito è un robot.
    $this->post('/proponi-evento', pubPropostaValida(['website_url' => 'https://spam.example']))
        ->assertSessionHasErrors('website_url');

    $this->post('/proponi-evento', pubPropostaValida(['venue_id' => 99999999]))
        ->assertSessionHasErrors('venue_id');

    expect(EventSubmission::query()->count())->toBe(0);

    // Un locale vero ma di un'altra città non si aggancia: resta solo l'indicazione scritta.
    $altrove = Venue::factory()->approved()->create(['city_id' => City::factory()->create()->id]);
    $this->post('/proponi-evento', pubPropostaValida(['venue_id' => $altrove->id, 'venue_hint' => 'Il locale di mio cugino']))
        ->assertSessionHasNoErrors();

    $proposta = EventSubmission::query()->sole();
    expect($proposta->venue_id)->toBeNull()
        ->and($proposta->venue_hint)->toBe('Il locale di mio cugino')
        ->and($proposta->status)->toBe(SubmissionStatus::Pending);
});

it('accoglie una proposta dall’app una volta sola anche se viene rispedita con la stessa chiave', function (): void {
    $payload = [
        'title' => 'Mercatino del riuso',
        'contact_email' => 'app@example.test',
        'venue_hint' => 'Piazza dei Signori',
        'starts_at_hint' => '2026-09-27 10:00:00',
    ];
    $intestazioni = ['Idempotency-Key' => 'proposta-app-0001-abcdef', 'X-Installation-ID' => 'installazione-prova-0001'];

    $prima = $this->withHeaders($intestazioni)->postJson('/api/v1/submissions', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', SubmissionStatus::Pending->value);

    // La rete cade, l'app ritenta: stessa risposta, nessuna seconda pratica.
    $this->withHeaders($intestazioni)->postJson('/api/v1/submissions', $payload)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertJsonPath('data.id', $prima->json('data.id'));
    expect(EventSubmission::query()->count())->toBe(1);

    // Stessa chiave, contenuto diverso: è un conflitto, non una nuova proposta.
    $this->withHeaders($intestazioni)->postJson('/api/v1/submissions', [...$payload, 'title' => 'Altro titolo'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Una chiave malformata è rifiutata prima di toccare il database.
    $this->withHeaders(['Idempotency-Key' => 'corta'])->postJson('/api/v1/submissions', $payload)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_REQUEST');

    // Un'altra installazione con la stessa chiave è un'altra persona.
    $this->withHeaders(['Idempotency-Key' => 'proposta-app-0001-abcdef', 'X-Installation-ID' => 'installazione-prova-0002'])
        ->postJson('/api/v1/submissions', $payload)
        ->assertCreated()
        ->assertHeaderMissing('Idempotency-Replayed');

    expect(EventSubmission::query()->count())->toBe(2)
        ->and(EventSubmission::query()->pluck('starts_at_hint')->map(fn ($d) => $d->utc()->format('H:i'))->unique()->all())->toBe(['08:00']);

    // Le pratiche arrivate dall'app sono in coda per la redazione.
    pubApriPannelloAdmin($this->admin, EventSubmissionResource::getUrl());
    Livewire::test(ListEventSubmissions::class)
        ->assertCanSeeTableRecords(EventSubmission::query()->get());
});

it('lascia rifiutare una proposta dal pannello, con il motivo, senza creare eventi', function (): void {
    $proposta = EventSubmission::factory()->create(['city_id' => $this->city->id, 'title' => 'Festa privata']);

    pubApriPannelloAdmin($this->admin, EventSubmissionResource::getUrl('edit', ['record' => $proposta]));

    // Il motivo è obbligatorio: fra un mese qualcuno chiederà perché.
    Livewire::test(EditEventSubmission::class, ['record' => $proposta->id])
        ->callAction('reject', data: ['notes' => ''])
        ->assertHasActionErrors(['notes' => 'required']);
    expect($proposta->fresh()->status)->toBe(SubmissionStatus::Pending);

    Livewire::test(EditEventSubmission::class, ['record' => $proposta->id])
        ->callAction('reject', data: ['notes' => 'Evento privato, fuori ambito'])
        ->assertHasNoActionErrors()
        ->assertRedirect(EventSubmissionResource::getUrl());

    $proposta->refresh();
    expect($proposta->status)->toBe(SubmissionStatus::Rejected)
        ->and($proposta->notes)->toBe('Evento privato, fuori ambito')
        ->and($proposta->reviewed_by)->toBe($this->admin->id)
        ->and($proposta->event_id)->toBeNull()
        ->and(Event::query()->count())->toBe(0);

    // Una pratica chiusa non si pubblica più dal pannello.
    Livewire::test(EditEventSubmission::class, ['record' => $proposta->id])
        ->assertActionHidden('publish')
        ->assertActionHidden('reject');
});

it('apre la coda delle proposte solo alla redazione, e la pubblicazione solo a chi può pubblicare', function (): void {
    $proposta = EventSubmission::factory()->create(['city_id' => $this->city->id]);
    $url = EventSubmissionResource::getUrl('edit', ['record' => $proposta]);

    $utente = User::factory()->create();
    $utente->assignRole(UserRole::User->value);
    $this->actingAs($utente)->get(EventSubmissionResource::getUrl())->assertForbidden();
    $this->actingAs($utente)->get($url)->assertForbidden();

    $gestore = User::factory()->create();
    $gestore->assignRole(UserRole::VenueOwner->value);
    $gestore->venues()->attach($this->venue, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);
    $this->actingAs($gestore)->get(EventSubmissionResource::getUrl())->assertForbidden();

    // Il moderatore legge e rifiuta, ma non ha il permesso di pubblicare.
    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);
    pubApriPannelloAdmin($moderatore, $url);
    Livewire::test(EditEventSubmission::class, ['record' => $proposta->id])
        ->assertActionHidden('publish')
        ->assertActionVisible('reject');

    expect($proposta->fresh()->status)->toBe(SubmissionStatus::Pending)
        ->and(Event::query()->count())->toBe(0);
});

it('avvisa chi segue il locale quando una proposta viene pubblicata', function (): void {
    $seguace = User::factory()->create();
    $silenzioso = User::factory()->create();
    app(FollowSubject::class)($seguace, FollowableType::Venue, $this->venue->id);
    app(FollowSubject::class)($silenzioso, FollowableType::Venue, $this->venue->id, false);

    $this->post('/proponi-evento', pubPropostaValida(['title' => 'Concerto d’autunno']))->assertSessionHasNoErrors();
    $proposta = EventSubmission::query()->sole();

    // Finché è una proposta, nessuno viene avvisato.
    expect(ScheduledNotification::query()->ofType(NotificationType::VenueNewEvent->value)->count())->toBe(0);

    pubApriPannelloAdmin($this->admin, EventSubmissionResource::getUrl('edit', ['record' => $proposta]));
    Livewire::test(EditEventSubmission::class, ['record' => $proposta->id])
        ->fillForm(['category_id' => $this->category->id])
        ->callAction('publish')
        ->assertHasNoActionErrors();

    $avviso = ScheduledNotification::query()->ofType(NotificationType::VenueNewEvent->value)->sole();
    expect($avviso->user_id)->toBe($seguace->id)
        ->and($avviso->notifiable_id)->toBe($proposta->fresh()->event_id);

    Notification::fake();
    $this->artisan('notifications:send')->assertSuccessful();
    Notification::assertSentTo($seguace, ScheduledMessage::class, fn (ScheduledMessage $n): bool => $n->message->type === NotificationType::VenueNewEvent);
    Notification::assertNotSentTo($silenzioso, ScheduledMessage::class);
});
