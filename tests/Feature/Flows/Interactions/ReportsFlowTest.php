<?php

declare(strict_types=1);

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Reports\Pages\EditReport;
use App\Filament\Admin\Resources\Reports\Pages\ListReports;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
 * «Segnala un errore»: dalla scheda pubblica (evento o locale) o dall'app,
 * con o senza account, fino alla coda della moderazione dove qualcuno la
 * chiude firmandola.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->event = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', event: ['title' => 'Jazz in cortile'])->event;
    $this->venue = $this->event->venue;
    $this->moderator = User::factory()->create();
    $this->moderator->assignRole(UserRole::Moderator->value);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('un ospite segnala un orario sbagliato dalla scheda dell\'evento e il moderatore la trova in coda e la chiude', function (): void {
    $form = route('events.report', ['slug' => $this->event->slug]);
    $store = route('events.report.store', ['slug' => $this->event->slug]);

    $this->get(route('events.show', ['slug' => $this->event->slug]))->assertOk()->assertSee($form, false);
    $this->get($form)->assertOk()
        ->assertSee($store, false)
        ->assertSee(ReportReason::WrongInfo->label())
        ->assertSee(ReportReason::Cancelled->label());

    $this->post($store, ['reason' => 'wrong_info', 'note' => 'Inizia alle 22, non alle 21.', 'reporter_email' => 'lettore@example.test'])
        ->assertRedirect(route('events.show', ['slug' => $this->event->slug]))
        ->assertSessionHas('status', __('forms.report.received'));

    $report = Report::query()->sole();
    expect($report->reportable_type)->toBe('event')
        ->and($report->reportable_id)->toBe($this->event->id)
        ->and($report->reason)->toBe(ReportReason::WrongInfo)
        ->and($report->status)->toBe(ReportStatus::Pending)
        ->and($report->note)->toBe('Inizia alle 22, non alle 21.')
        ->and($report->reporter_email)->toBe('lettore@example.test')
        ->and($report->reporter_user_id)->toBeNull()
        ->and($report->ip_address)->toBe('127.0.0.1');

    // In moderazione: la coda la mostra, il moderatore la chiude firmandola.
    $this->actingAs($this->moderator, 'web')->get(ReportResource::getUrl('index', panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    expect(ReportResource::getNavigationBadge())->toBe('1');
    Livewire::test(ListReports::class)->assertCanSeeTableRecords([$report]);
    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm(['status' => ReportStatus::Resolved->value, 'resolution_note' => 'Orario corretto con il locale.'])
        ->call('save')
        ->assertHasNoFormErrors();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->reviewed_by)->toBe($this->moderator->id)
        ->and($report->resolution_note)->toBe('Orario corretto con il locale.')
        ->and($report->note)->toBe('Inizia alle 22, non alle 21.');
});

it('una persona con account segnala un locale doppio; robot, motivi inventati e schede non pubbliche restano fuori', function (): void {
    $lettrice = User::factory()->create();
    $form = route('venues.report', ['slug' => $this->venue->slug]);
    $store = route('venues.report.store', ['slug' => $this->venue->slug]);

    $this->actingAs($lettrice)->get(route('venues.show', ['slug' => $this->venue->slug]))->assertOk()->assertSee($form, false);
    $this->get($form)->assertOk()->assertSee($store, false);

    // Il campo civetta compilato è un robot.
    $this->from($form)->post($store, ['reason' => 'duplicate', 'website_url' => 'https://spam.example'])
        ->assertRedirect($form)->assertSessionHasErrors('website_url');
    $this->post($store, ['reason' => 'complotto'])->assertSessionHasErrors('reason');
    $this->post($store, ['reason' => 'duplicate', 'reporter_email' => 'non-una-email'])->assertSessionHasErrors('reporter_email');
    expect(Report::query()->count())->toBe(0);

    $this->post($store, ['reason' => 'duplicate', 'note' => 'È lo stesso locale di via Roma.'])
        ->assertRedirect(route('venues.show', ['slug' => $this->venue->slug]))
        ->assertSessionHas('status', __('forms.report.received'));
    $report = Report::query()->sole();
    expect($report->reportable_type)->toBe('venue')
        ->and($report->reportable_id)->toBe($this->venue->id)
        ->and($report->reporter_user_id)->toBe($lettrice->id)
        ->and($report->reason)->toBe(ReportReason::Duplicate);

    /* Ciò che il pubblico non vede non si segnala. Lo prova un altro
       visitatore: questo indirizzo ha già usato quattro dei cinque invii
       orari dei moduli pubblici (limitatore `public-forms`). */
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);
    $bozza = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $eventoBozza = Event::factory()->for($this->city)->create(['status' => 'draft', 'category_id' => $this->category->id]);
    $this->get(route('venues.report', ['slug' => $bozza->slug]))->assertNotFound();
    $this->post(route('venues.report.store', ['slug' => $bozza->slug]), ['reason' => 'spam'])->assertNotFound();
    $this->get(route('events.report', ['slug' => $eventoBozza->slug]))->assertNotFound();
    $this->post(route('events.report.store', ['slug' => $eventoBozza->slug]), ['reason' => 'spam'])->assertNotFound();
    expect(Report::query()->count())->toBe(1);
});

it('l\'app ritenta una segnalazione con la stessa chiave senza duplicarla, e una seconda uguale è un 409', function (): void {
    $payload = ['subject_type' => 'event', 'subject_slug' => $this->event->slug, 'reason' => 'cancelled', 'note' => 'Annullato per pioggia.'];
    $chiave = 'segnalazione-0001-abcdef';

    $prima = $this->postJson('/api/v1/reports', $payload, ['Idempotency-Key' => $chiave])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.message', __('api.reports.received'));
    $id = $prima->json('data.id');

    // Il ritentativo dopo un timeout riceve la stessa risposta, senza un secondo record.
    $this->postJson('/api/v1/reports', $payload, ['Idempotency-Key' => $chiave])->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertJsonPath('data.id', $id);
    expect(Report::query()->count())->toBe(1);

    // Stessa chiave con un corpo diverso: conflitto.
    $this->postJson('/api/v1/reports', [...$payload, 'note' => 'Testo cambiato.'], ['Idempotency-Key' => $chiave])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

    // Chiave nuova ma stessa segnalazione ancora da leggere: già in coda.
    $this->postJson('/api/v1/reports', $payload, ['Idempotency-Key' => 'segnalazione-0002-abcdef'])
        ->assertStatus(409)->assertJsonPath('error.code', 'REPORT_ALREADY_PENDING');

    // Un motivo diverso è un'altra segnalazione (quinto invio dell'ora da questo indirizzo).
    $this->postJson('/api/v1/reports', [...$payload, 'reason' => 'wrong_info'], ['Idempotency-Key' => 'segnalazione-0003-abcdef'])->assertCreated();

    // Il sesto invio dell'ora dallo stesso indirizzo è fermato dal limitatore dei moduli pubblici.
    $this->postJson('/api/v1/reports', [...$payload, 'reason' => 'spam'], ['Idempotency-Key' => 'segnalazione-0004-abcdef'])->assertStatus(429);

    // Un altro dispositivo con una chiave malformata: errore del client, nulla salvato.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/reports', [...$payload, 'reason' => 'spam'], ['Idempotency-Key' => 'corta'])->assertStatus(400);

    expect(Report::query()->count())->toBe(2)
        ->and(Report::query()->findOrFail($id)->reason)->toBe(ReportReason::Cancelled)
        ->and(Report::query()->pluck('reportable_id')->unique()->all())->toBe([$this->event->id]);
});

it('dall\'app con un account si segnala un locale; chiusa la prima, si può segnalare di nuovo; soggetti invisibili e dati errati no', function (): void {
    $utente = User::factory()->create();
    Sanctum::actingAs($utente);
    $payload = ['subject_type' => 'venue', 'subject_slug' => $this->venue->slug, 'reason' => 'wrong_info', 'reporter_email' => 'utente@example.test'];

    $id = $this->postJson('/api/v1/reports', $payload)->assertCreated()->json('data.id');
    $report = Report::query()->findOrFail($id);
    expect($report->reportable_type)->toBe('venue')->and($report->reporter_user_id)->toBe($utente->id);
    $this->postJson('/api/v1/reports', $payload)->assertStatus(409)->assertJsonPath('error.code', 'REPORT_ALREADY_PENDING');

    // La moderazione la archivia: la stessa persona può tornare a segnalare.
    $this->actingAs($this->moderator, 'web')->get(ReportResource::getUrl('index', panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm(['status' => ReportStatus::Dismissed->value, 'resolution_note' => 'Dati verificati, sono corretti.'])
        ->call('save')->assertHasNoFormErrors();
    expect($report->fresh()->status)->toBe(ReportStatus::Dismissed);
    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($utente);
    $this->postJson('/api/v1/reports', $payload)->assertCreated();

    // Bozze e slug inesistenti: 404; dati malformati: 422. Dalla rete di casa,
    // perché quella di prima ha consumato tre dei cinque invii orari.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
    $eventoBozza = Event::factory()->for($this->city)->create(['status' => 'draft', 'category_id' => $this->category->id]);
    $this->postJson('/api/v1/reports', ['subject_type' => 'event', 'subject_slug' => $eventoBozza->slug, 'reason' => 'spam'])
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->postJson('/api/v1/reports', ['subject_type' => 'venue', 'subject_slug' => 'locale-che-non-esiste', 'reason' => 'spam'])->assertNotFound();
    $this->postJson('/api/v1/reports', ['subject_type' => 'user', 'subject_slug' => $this->venue->slug, 'reason' => 'spam'])->assertUnprocessable();
    $this->postJson('/api/v1/reports', ['subject_type' => 'venue', 'subject_slug' => $this->venue->slug])->assertUnprocessable();
    $this->postJson('/api/v1/reports', [...$payload, 'reason' => 'spam', 'reporter_email' => 'sbagliata'])->assertUnprocessable();

    expect(Report::query()->count())->toBe(2);
});
