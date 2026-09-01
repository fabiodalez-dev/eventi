<?php

declare(strict_types=1);

use App\Actions\CreateReport;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Reports\Pages\EditReport;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EditorialDashboardQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * §14.6 — le segnalazioni: i sei motivi e ciò che il moderatore può farne.
 *
 * Due cose contano più delle altre e hanno un test ciascuna: che una
 * segnalazione **non sia riscrivibile** da chi la esamina — sarebbe una prova
 * manomissibile di ciò che è stato detto — e che chiuderla lasci scritto
 * **chi** ha deciso, senza che quel nome passi dal modulo.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));

    $this->city = testCity();
    $this->category = testCategory();

    $this->moderator = User::factory()->create();
    $this->moderator->assignRole(UserRole::Moderator->value);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('conosce esattamente i sei motivi di §14.6, e ognuno ha un\'etichetta scritta in italiano', function (): void {
    expect(ReportReason::values())->toBe([
        'wrong_info', 'duplicate', 'spam', 'offensive', 'cancelled', 'copyright',
    ]);

    foreach (ReportReason::cases() as $reason) {
        expect($reason->label())
            ->not->toBe('enums.report_reason.'.$reason->value, "manca la traduzione di {$reason->value}")
            ->not->toBe('');
    }

    expect(ReportReason::options())->toHaveCount(6)
        ->and(ReportReason::options()['duplicate'])->toBe(__('enums.report_reason.duplicate'));
});

it('conosce i quattro esiti possibili e li sa nominare', function (): void {
    expect(ReportStatus::values())->toBe(['pending', 'reviewing', 'resolved', 'dismissed']);

    foreach (ReportStatus::cases() as $status) {
        expect($status->label())->not->toBe('enums.report_status.'.$status->value);
    }
});

it('accetta una segnalazione su un evento e una su un locale, senza chiedere chi la manda', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00')->event;
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $action = app(CreateReport::class);

    $suEvento = $action->handle($event, ReportReason::WrongInfo, 'L\'orario è sbagliato.');
    $suLocale = $action->handle($venue, ReportReason::Duplicate, null, 'lettore@example.test');

    expect($suEvento->reportable_type)->toBe('event')
        ->and($suEvento->reportable_id)->toBe($event->getKey())
        ->and($suEvento->status)->toBe(ReportStatus::Pending)
        /* Chi si accorge di un orario sbagliato spesso non è iscritto a
           nulla: l'indirizzo resta facoltativo. */
        ->and($suEvento->reporter_email)->toBeNull()
        ->and($suEvento->reporter_user_id)->toBeNull()
        ->and($suLocale->reportable_type)->toBe('venue')
        ->and($suLocale->reporter_email)->toBe('lettore@example.test')
        ->and($suLocale->reason)->toBe(ReportReason::Duplicate);
});

it('collega la segnalazione all\'account di chi era autenticato', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00')->event;
    $lettore = User::factory()->create();

    $report = app(CreateReport::class)->handle(
        $event,
        ReportReason::Cancelled,
        'Erano chiusi.',
        null,
        (int) $lettore->getKey(),
        '203.0.113.7',
    );

    expect($report->reporter->is($lettore))->toBeTrue()
        ->and($report->ip_address)->toBe('203.0.113.7')
        ->and($report->reportable->is($event))->toBeTrue();
});

it('chiama aperta una segnalazione da esaminare o in esame, e chiusa le altre due', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00')->event;

    $daEsaminare = Report::factory()->about($event)->create();
    $inEsame = Report::factory()->about($event)->reviewing()->create();
    Report::factory()->about($event)->resolved()->create();
    Report::factory()->about($event)->dismissed()->create();

    $aperte = Report::query()->tap(EditorialDashboardQuery::openReportScope(...))->pluck('id')->sort()->values()->all();

    expect($aperte)->toBe(collect([$daEsaminare->getKey(), $inEsame->getKey()])->sort()->values()->all())
        ->and(ReportResource::getNavigationBadge())->toBe('2');

    Report::query()->whereKey([$daEsaminare->getKey(), $inEsame->getKey()])
        ->update(['status' => ReportStatus::Dismissed]);

    /* Nessuna coda aperta, nessun numero rosso: un contatore fermo su zero
       sarebbe una notifica che non se ne va più. */
    expect(ReportResource::getNavigationBadge())->toBeNull();
});

it('chiude una segnalazione firmandola con chi ha deciso, senza che quel nome passi dal modulo', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00')->event;
    $report = Report::factory()->about($event)->ofReason(ReportReason::WrongInfo)->create([
        'note' => 'L\'orario indicato non è quello vero.',
    ]);

    $this->actingAs($this->moderator);

    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm([
            'status' => ReportStatus::Resolved->value,
            'resolution_note' => 'Orario corretto d\'accordo con il locale.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $salvata = $report->fresh();

    expect($salvata?->status)->toBe(ReportStatus::Resolved)
        ->and($salvata?->resolution_note)->toBe('Orario corretto d\'accordo con il locale.')
        ->and($salvata?->reviewed_by)->toBe($this->moderator->getKey())
        ->and($salvata?->reviewed_at?->toDateTimeString())->toBe('2026-09-10 10:00:00')
        /* Motivo e testo di chi ha segnalato non sono nel modulo e restano
           quelli: una segnalazione riscritta non prova più nulla. */
        ->and($salvata?->reason)->toBe(ReportReason::WrongInfo)
        ->and($salvata?->note)->toBe('L\'orario indicato non è quello vero.');
});

it('non lascia riscrivere il motivo né il testo di chi ha segnalato', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00')->event;
    $report = Report::factory()->about($event)->create();

    $this->actingAs($this->moderator);

    $component = Livewire::test(EditReport::class, ['record' => $report->getRouteKey()]);

    $component->assertFormFieldExists('status')
        ->assertFormFieldExists('resolution_note')
        ->assertFormFieldDoesNotExist('reason')
        ->assertFormFieldDoesNotExist('note')
        ->assertFormFieldDoesNotExist('reporter_email');
});

it('non si inventa segnalazioni dal pannello: arrivano dal pubblico', function (): void {
    expect(ReportResource::canCreate())->toBeFalse();
});

it('nega a chi non modera di leggere e di chiudere una segnalazione', function (): void {
    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00')->event;
    $report = Report::factory()->about($event)->create();

    $lettore = User::factory()->create();
    $lettore->assignRole(UserRole::User->value);

    expect($lettore->can('update', $report))->toBeFalse()
        ->and($lettore->can('delete', $report))->toBeFalse()
        ->and($lettore->can('view', $report))->toBeFalse()
        ->and($this->moderator->can('update', $report))->toBeTrue();

    // Chi ha segnalato può rileggere la propria segnalazione, e solo quella.
    $mia = Report::factory()->about($event)->create(['reporter_user_id' => $lettore->getKey()]);

    expect($lettore->can('view', $mia))->toBeTrue()
        ->and($lettore->can('update', $mia))->toBeFalse();
});
