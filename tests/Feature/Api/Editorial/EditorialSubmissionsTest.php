<?php

declare(strict_types=1);

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\EventSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R2 — le proposte di eventi dal pubblico nell'app (R-SB-01…05), con la
 * griglia dei ruoli: le vede e le rifiuta chi modera; «Crea e pubblica»
 * chiede in più `events.publish` e `EventPolicy::create` (admin e super
 * admin), come il pulsante del pannello.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->submission = EventSubmission::factory()->create([
        'city_id' => $this->s->city->id,
        'title' => 'Concerto proposto dal pubblico',
        'contact_name' => 'Giulia Rossi',
        'contact_email' => 'giulia@example.test',
        'starts_at_hint' => CarbonImmutable::parse('2026-12-10 19:00:00', 'UTC'),
    ]);
    EventSubmission::factory()->rejected()->create(['city_id' => $this->s->city->id, 'title' => 'Proposta già rifiutata']);
});

it('apre l’elenco e l’esame a chi modera, con il filtro di stato', function (): void {
    $urls = ['/api/v1/editorial/submissions', '/api/v1/editorial/submissions/'.$this->submission->id];
    foreach ($urls as $url) {
        foreach ([$this->s->ownerA, $this->s->plainUser] as $outsider) {
            EditorialSession::as($this, EditorialSession::token($outsider))->getJson($url)->assertNotFound();
        }
        EditorialSession::as($this, EditorialSession::token($this->s->moderator, unlocked: false))->getJson($url)
            ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
        foreach ([$this->s->moderator, $this->s->admin, $this->superAdmin] as $staff) {
            EditorialSession::as($this, EditorialSession::token($staff))->getJson($url)->assertOk();
        }
    }

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))
        ->getJson('/api/v1/editorial/submissions?filter[status]=pending')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.contact_name', 'Giulia Rossi')
        ->assertJsonPath('data.0.city.timezone', 'Europe/Rome')
        // Il moderatore esamina e rifiuta, non pubblica.
        ->assertJsonPath('data.0.abilities.publish', false)
        ->assertJsonPath('data.0.abilities.reject', true);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))
        ->getJson('/api/v1/editorial/submissions/'.$this->submission->id)->assertOk()
        ->assertJsonPath('data.contact_email', 'giulia@example.test')
        ->assertJsonPath('data.abilities.publish', true)
        ->assertJsonPath('data.form.price_type', 'unknown')
        ->assertJsonPath('data.form.starts_at_hint', '2026-12-10T19:00:00+00:00')
        ->assertJsonPath('data.choices.venues.0.label', 'Circolo Aurora')
        ->assertJsonPath('data.timezone', 'Europe/Rome');
});

it('crea e pubblica l’evento con l’ora della città, solo per admin e super admin', function (): void {
    $url = '/api/v1/editorial/submissions/'.$this->submission->id.'/publish';
    $body = ['title' => 'Concerto pubblicato', 'raw_text' => '<p>Testo controllato</p>', 'venue_id' => $this->s->venueA->id,
        'category_id' => $this->s->category->id, 'starts_at_hint' => '2026-12-10 21:00', 'price_type' => 'free'];

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, $body)->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, [...$body, 'venue_id' => $this->s->venueB->id + 1000])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['venue_id']]]);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, [...$body, 'ends_at' => '2026-12-10T19:00:00+01:00'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['ends_at']]]);

    $eventId = EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, $body)->assertCreated()
        ->assertJsonPath('data.submission.status.value', 'approved')
        ->assertJsonPath('data.submission.abilities.open_event', true)
        ->json('data.event_id');

    $event = $this->submission->fresh()->event;
    expect($event->id)->toBe($eventId)
        ->and($event->status)->toBe(EventStatus::Published)
        ->and($event->source)->toBe(EventSource::Submission)
        // 21:00 a Padova in dicembre sono le 20:00 UTC, come il selettore del pannello.
        ->and($event->occurrences()->first()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-10 20:00')
        ->and($this->submission->fresh()->reviewed_by)->toBe($this->s->admin->id);

    // La scheda dell'evento creato si apre dalla redazione (anche per il moderatore).
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson('/api/v1/editorial/events/'.$eventId)->assertOk();
});

it('rifiuta con il motivo interno una volta sola', function (): void {
    $url = '/api/v1/editorial/submissions/'.$this->submission->id.'/reject';

    EditorialSession::as($this, EditorialSession::token($this->s->plainUser))->postJson($url, ['notes' => 'Doppione'])->assertNotFound();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, ['notes' => ''])->assertUnprocessable();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, ['notes' => str_repeat('a', 2001)])->assertUnprocessable();
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($url, ['notes' => 'Evento già in catalogo'])->assertOk()
        ->assertJsonPath('data.status.value', 'rejected')
        ->assertJsonPath('message', __('editorial-events.submission_rejected'));

    $fresh = $this->submission->fresh();
    expect($fresh->status)->toBe(SubmissionStatus::Rejected)->and($fresh->notes)->toBe('Evento già in catalogo')
        ->and($fresh->reviewed_by)->toBe($this->s->moderator->id);

    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson($url, ['notes' => 'Ancora'])->assertStatus(409);
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->postJson('/api/v1/editorial/submissions/'.$this->submission->id.'/publish', [])->assertStatus(409);
});
