<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\SubmissionStatus;
use App\Models\EventSubmission;
use App\Models\Report;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Support\Honeypot;
use Carbon\Carbon;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('public-forms');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('registra una proposta di evento come pratica in attesa', function (): void {
    $city = testCity();

    freezeLocal($city, '2026-09-05 12:00:00');

    $this->get('/proponi-evento')->assertOk()->assertSee(__('forms.submission.title'));

    $this->post('/proponi-evento', [
        'title' => 'Concerto in cortile',
        'starts_at_hint' => '2026-09-10T21:30',
        'venue_hint' => 'Cortile della biblioteca',
        'contact_name' => 'Mario Rossi',
        'contact_email' => 'mario@example.com',
        Honeypot::FIELD => '',
    ])->assertRedirect('/proponi-evento')->assertSessionHas('status');

    $submission = EventSubmission::query()->firstOrFail();

    expect($submission->title)->toBe('Concerto in cortile')
        ->and($submission->status)->toBe(SubmissionStatus::Pending)
        ->and($submission->city_id)->toBe($city->getKey())
        ->and($submission->event_id)->toBeNull()
        /* L'ora locale 21:30 diventa 19:30 UTC in colonna. */
        ->and($submission->starts_at_hint?->format('Y-m-d H:i'))->toBe('2026-09-10 19:30');
});

it('scarta la proposta quando il campo esca arriva compilato', function (): void {
    testCity();

    $this->post('/proponi-evento', [
        'title' => 'Offerta imperdibile',
        'contact_email' => 'robot@example.com',
        Honeypot::FIELD => 'https://spam.example',
    ])->assertSessionHasErrors(Honeypot::FIELD);

    expect(EventSubmission::query()->count())->toBe(0);
});

it('rifiuta una proposta senza titolo e con un\'email inventata, in italiano', function (): void {
    testCity();

    $response = $this->from('/proponi-evento')->post('/proponi-evento', [
        'contact_email' => 'non-una-email',
    ]);

    $response->assertRedirect('/proponi-evento')->assertSessionHasErrors(['title', 'contact_email']);

    $errors = session('errors');

    expect($errors?->first('title'))->toContain('obbligatorio')
        ->and($errors?->first('contact_email'))->toContain('email valido');

    expect(EventSubmission::query()->count())->toBe(0);
});

it('ferma chi insiste con il limite di frequenza', function (): void {
    testCity();

    foreach (range(1, 5) as $index) {
        $this->post('/proponi-evento', [
            'title' => 'Proposta numero '.$index,
            'contact_email' => 'mario@example.com',
        ])->assertRedirect();
    }

    $this->post('/proponi-evento', [
        'title' => 'Una di troppo',
        'contact_email' => 'mario@example.com',
    ])->assertStatus(429);

    expect(EventSubmission::query()->count())->toBe(5);
});

it('registra la richiesta di accreditamento di un locale', function (): void {
    testCity();

    $this->get('/registra-il-tuo-locale')->assertOk()->assertSee(__('forms.application.title'));

    $this->post('/registra-il-tuo-locale', [
        'venue_name' => 'Circolo Nuovo',
        'type' => 'circolo',
        'address' => 'Via Prova 1, Padova',
        'website' => 'https://circolonuovo.example',
        'contact_name' => 'Anna Bianchi',
        'contact_role' => 'Presidente',
        'contact_phone' => '049 1234567',
        'contact_email' => 'anna@example.com',
        'message' => 'Organizziamo concerti ogni venerdì.',
        Honeypot::FIELD => '',
    ])->assertRedirect('/registra-il-tuo-locale')->assertSessionHas('status');

    $application = VenueApplication::query()->firstOrFail();

    expect($application->venue_name)->toBe('Circolo Nuovo')
        ->and($application->status)->toBe(ApplicationStatus::Pending)
        ->and($application->venue_id)->toBeNull()
        ->and($application->socials)->toBe(['website' => 'https://circolonuovo.example']);

    /* La richiesta non crea alcun locale: l'approvazione è un atto della redazione. */
    expect(Venue::query()->count())->toBe(0);
});

it('accetta una segnalazione su un evento e su un locale, senza account', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00');
    $event = $occurrence->event;
    $venue = $event->venue;

    $this->get('/eventi/'.$event->slug.'/segnala')->assertOk()->assertSee(__('forms.report.title'));

    $this->post('/eventi/'.$event->slug.'/segnala', [
        'reason' => ReportReason::WrongInfo->value,
        'note' => 'L\'orario è sbagliato: comincia alle 22.',
        'reporter_email' => 'lettore@example.com',
        Honeypot::FIELD => '',
    ])->assertRedirect(route('events.show', $event))->assertSessionHas('status');

    $this->post('/locali/'.$venue?->slug.'/segnala', [
        'reason' => ReportReason::Duplicate->value,
        Honeypot::FIELD => '',
    ])->assertRedirect(route('venues.show', $venue));

    $reports = Report::query()->get();

    expect($reports)->toHaveCount(2)
        ->and($reports->pluck('status')->unique()->all())->toBe([ReportStatus::Pending])
        ->and($reports->pluck('reportable_type')->all())->toBe(['event', 'venue']);
});

it('pretende un motivo per segnalare', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00')->event;

    $this->from('/eventi/'.$event->slug.'/segnala')
        ->post('/eventi/'.$event->slug.'/segnala', ['note' => 'Boh'])
        ->assertSessionHasErrors('reason');

    expect(Report::query()->count())->toBe(0);
});
