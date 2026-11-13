<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\EventResource as AdminEventResource;
use App\Filament\Admin\Resources\Events\Pages\EditEvent as AdminEditEvent;
use App\Filament\Admin\Resources\Reports\Pages\EditReport;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Support\Honeypot;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Flusso: un lettore segnala un evento dalla scheda pubblica, la segnalazione
 * arriva nella coda della redazione, il moderatore la prende in carico,
 * l'amministratore ritira l'evento e il caso si chiude firmato da chi l'ha
 * deciso. Il secondo percorso è quello della segnalazione infondata, con le
 * porte della coda chiuse a chi non appartiene alla redazione.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 12:00:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00', '2026-09-20 23:00', event: [
        'title' => 'Concerto al chiostro',
    ]);
    $this->event = $this->occurrence->event;

    $this->admin = User::factory()->create(['name' => 'Redazione Centrale']);
    $this->admin->assignRole(UserRole::Admin->value);
    $this->moderator = User::factory()->create(['name' => 'Moderatrice di turno']);
    $this->moderator->assignRole(UserRole::Moderator->value);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('porta la segnalazione di un lettore fino al ritiro dell\'evento e chiude il caso firmato da chi ha deciso', function (): void {
    // 1. Il lettore, senza account, vede la scheda e segnala.
    $this->get(route('events.show', $this->event))->assertOk()->assertSee('Concerto al chiostro');
    $this->get('/eventi/'.$this->event->slug.'/segnala')->assertOk()->assertSee(__('forms.report.title'));
    $this->post('/eventi/'.$this->event->slug.'/segnala', [
        'reason' => ReportReason::Spam->value,
        'note' => 'Pubblicità di un altro locale, non un concerto.',
        'reporter_email' => 'lettore@example.test',
        Honeypot::FIELD => '',
    ])->assertRedirect(route('events.show', $this->event))->assertSessionHas('status');

    $report = Report::query()->sole();
    expect($report->status)->toBe(ReportStatus::Pending)
        ->and($report->reason)->toBe(ReportReason::Spam)
        ->and($report->reportable->is($this->event))->toBeTrue()
        ->and($report->reporter_email)->toBe('lettore@example.test')
        ->and($report->reviewed_by)->toBeNull();

    Cache::forget('nav-badge:reports-open');
    expect(ReportResource::getNavigationBadge())->toBe('1');

    // 2. La moderatrice la trova in coda e la prende in carico.
    $this->actingAs($this->moderator)->get('/admin/reports')->assertOk()
        ->assertSee('Pubblicità di un altro locale')
        ->assertSee('Concerto al chiostro');
    Filament::setCurrentPanel('admin');
    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm(['status' => ReportStatus::Reviewing->value])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($report->fresh()->status)->toBe(ReportStatus::Reviewing)
        ->and($report->fresh()->reviewed_by)->toBe($this->moderator->getKey());

    // 3. L'amministratore ritira l'evento dal sito.
    $this->actingAs($this->admin)
        ->get(AdminEventResource::getUrl('edit', ['record' => $this->event], panel: 'admin'))
        ->assertOk();
    Livewire::test(AdminEditEvent::class, ['record' => $this->event->getRouteKey()])
        ->callAction('unpublish')
        ->assertHasNoActionErrors();

    expect($this->event->fresh()->status)->toBe(EventStatus::Draft);
    $this->get(route('events.show', $this->event))->assertNotFound();
    expect(Activity::query()
        ->where('subject_type', 'event')
        ->where('subject_id', $this->event->getKey())
        ->where('causer_id', $this->admin->getKey())
        ->exists())->toBeTrue();

    // 4. La moderatrice chiude il caso: firma e data le scrive il salvataggio.
    $this->actingAs($this->moderator);
    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm(['status' => ReportStatus::Resolved->value, 'resolution_note' => 'Evento ritirato dalla redazione.'])
        ->call('save')
        ->assertHasNoFormErrors();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->resolution_note)->toBe('Evento ritirato dalla redazione.')
        ->and($report->reviewed_by)->toBe($this->moderator->getKey())
        ->and($report->reviewed_at)->not->toBeNull()
        // Quello che il lettore ha scritto resta com'era.
        ->and($report->note)->toBe('Pubblicità di un altro locale, non un concerto.')
        ->and($report->reason)->toBe(ReportReason::Spam);

    Cache::forget('nav-badge:reports-open');
    expect(ReportResource::getNavigationBadge())->toBeNull();
});

it('archivia una segnalazione infondata lasciando l\'evento online e tiene la coda chiusa a chi non è della redazione', function (): void {
    // Un robot che riempie il campo trappola non lascia niente in coda.
    $this->from('/eventi/'.$this->event->slug.'/segnala')
        ->post('/eventi/'.$this->event->slug.'/segnala', [
            'reason' => ReportReason::WrongInfo->value,
            Honeypot::FIELD => 'https://spam.example',
        ])->assertSessionHasErrors(Honeypot::FIELD);
    expect(Report::query()->count())->toBe(0);

    // Una persona vera segnala un orario che crede sbagliato.
    $this->post('/eventi/'.$this->event->slug.'/segnala', [
        'reason' => ReportReason::WrongInfo->value,
        'note' => 'Secondo me inizia alle 22.',
        Honeypot::FIELD => '',
    ])->assertRedirect(route('events.show', $this->event));
    $report = Report::query()->sole();

    // La coda non è visibile a chi gestisce il locale né a un utente qualunque.
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($this->event->venue, ['role' => 'owner']);
    $plain = User::factory()->create();
    $plain->assignRole(UserRole::User->value);

    $this->actingAs($owner)->get('/admin/reports')->assertForbidden();
    $this->actingAs($plain)->get('/admin/reports')->assertForbidden();
    $this->actingAs($plain)->get(ReportResource::getUrl('edit', ['record' => $report], panel: 'admin'))->assertForbidden();

    // La moderatrice verifica e archivia; un tentativo di riscrivere il
    // testo della segnalazione dal modulo non passa.
    $this->actingAs($this->moderator)->get(ReportResource::getUrl('edit', ['record' => $report], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm([
            'status' => ReportStatus::Dismissed->value,
            'resolution_note' => 'Orario confermato dal locale.',
            'note' => 'Testo riscritto dal moderatore',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Dismissed)
        ->and($report->reviewed_by)->toBe($this->moderator->getKey())
        ->and($report->note)->toBe('Secondo me inizia alle 22.');

    // L'evento resta pubblicato e raggiungibile.
    expect($this->event->fresh()->status)->toBe(EventStatus::Published);
    auth()->logout();
    $this->get(route('events.show', $this->event))->assertOk()->assertSee('Concerto al chiostro');
});
