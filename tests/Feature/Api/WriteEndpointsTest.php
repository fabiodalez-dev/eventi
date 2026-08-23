<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\SubmissionStatus;
use App\Models\EventSubmission;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('registra una proposta e la lascia in coda di moderazione', function (): void {
    $city = testCity();

    freezeLocal($city, '2026-09-05 09:00:00');

    $response = $this->postJson('/api/v1/submissions', [
        'title' => 'Concerto al parco',
        'contact_email' => 'chi.propone@example.test',
        'venue_hint' => 'Parco della Musica',
        'starts_at_hint' => '2026-09-20 21:00:00',
    ])->assertCreated();

    expect($response->json('data.status'))->toBe(SubmissionStatus::Pending->value);

    $submission = EventSubmission::query()->firstOrFail();

    expect($submission->title)->toBe('Concerto al parco')
        ->and($submission->city_id)->toBe($city->getKey())
        ->and($submission->event_id)->toBeNull()
        /* Chi propone scrive l'ora del proprio orologio: va riportata a UTC. */
        ->and($submission->starts_at_hint?->format('H:i'))->toBe('19:00');
});

it('rifiuta una proposta senza titolo o senza recapito', function (): void {
    testCity();

    $this->postJson('/api/v1/submissions', ['title' => 'Solo il titolo'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['contact_email']]]);

    $this->postJson('/api/v1/submissions', ['contact_email' => 'non-una-email'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['title', 'contact_email']]]);
});

it('registra una segnalazione su un evento pubblicato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Concerto']);

    $this->postJson('/api/v1/reports', [
        'subject_type' => 'event',
        'subject_slug' => $occorrenza->event->slug,
        'reason' => ReportReason::WrongInfo->value,
        'note' => 'L\'orario non torna',
    ])->assertCreated()->assertJsonPath('data.status', ReportStatus::Pending->value);

    $report = Report::query()->firstOrFail();

    expect($report->reportable_type)->toBe('event')
        ->and($report->reportable_id)->toBe($occorrenza->event->getKey())
        ->and($report->reason)->toBe(ReportReason::WrongInfo);
});

it('segnala anche un locale, e non ciò che il pubblico non vede', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $bozza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: [
        'title' => 'Bozza',
        'status' => EventStatus::Draft,
    ]);

    $this->postJson('/api/v1/reports', [
        'subject_type' => 'venue',
        'subject_slug' => $venue->slug,
        'reason' => ReportReason::Duplicate->value,
    ])->assertCreated();

    $this->postJson('/api/v1/reports', [
        'subject_type' => 'event',
        'subject_slug' => $bozza->event->slug,
        'reason' => ReportReason::Spam->value,
    ])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});

/*
 * La stessa segnalazione mandata due volte è un conflitto, non un secondo
 * record: due righe identiche fanno perdere tempo a chi le legge.
 */
it('risponde 409 alla stessa segnalazione ripetuta', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $payload = [
        'subject_type' => 'event',
        'subject_slug' => $occorrenza->event->slug,
        'reason' => ReportReason::WrongInfo->value,
    ];

    $this->postJson('/api/v1/reports', $payload)->assertCreated();

    $this->postJson('/api/v1/reports', $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'REPORT_ALREADY_PENDING');

    /* Un motivo diverso è un'altra segnalazione, e passa. */
    $this->postJson('/api/v1/reports', [...$payload, 'reason' => ReportReason::Cancelled->value])
        ->assertCreated();

    expect(Report::query()->count())->toBe(2);
});

it('attribuisce la segnalazione a chi è autenticato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/reports', [
        'subject_type' => 'event',
        'subject_slug' => $occorrenza->event->slug,
        'reason' => ReportReason::Offensive->value,
    ])->assertCreated();

    expect(Report::query()->firstOrFail()->reporter_user_id)->toBe($user->getKey());
});

it('rifiuta una segnalazione senza motivo o con un motivo inventato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $this->postJson('/api/v1/reports', [
        'subject_type' => 'event',
        'subject_slug' => $occorrenza->event->slug,
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);

    $this->postJson('/api/v1/reports', [
        'subject_type' => 'event',
        'subject_slug' => $occorrenza->event->slug,
        'reason' => 'non-mi-piace',
    ])->assertStatus(422);
});

/*
 * §14.7: i moduli pubblici hanno un limite di frequenza per indirizzo IP.
 */
it('mette un tetto alle proposte in arrivo dallo stesso indirizzo', function (): void {
    testCity();

    for ($invio = 0; $invio < 5; $invio++) {
        $this->postJson('/api/v1/submissions', [
            'title' => 'Proposta '.$invio,
            'contact_email' => 'chi.propone@example.test',
        ])->assertCreated();
    }

    $this->postJson('/api/v1/submissions', [
        'title' => 'Una di troppo',
        'contact_email' => 'chi.propone@example.test',
    ])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
