<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Report;
use App\Models\Tag;
use App\Models\Venue;
use App\Models\VenueApplication;
use Illuminate\Support\Facades\Gate;
use Tests\Support\VenueIsolationScenario;

/**
 * §3 del piano: cosa può ciascun ruolo. Le righe della tabella diventano
 * asserzioni, comprese quelle scritte in negativo — «l'editor non può toccare
 * dati sensibili, proprietari, inviti».
 */
it('impedisce al collaboratore di gestire il locale, i collaboratori e i dati del referente', function (): void {
    $s = VenueIsolationScenario::make();
    $editorA = $s->editorA;

    expect(Gate::forUser($editorA)->allows('update', $s->venueA))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('delete', $s->venueA))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('manageCollaborators', $s->venueA))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('viewSensitiveData', $s->venueA))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('moderate', $s->venueA))->toBeFalse();
});

it('consente al collaboratore di creare e modificare gli eventi del proprio locale', function (): void {
    $s = VenueIsolationScenario::make();
    $editorA = $s->editorA;

    expect(Gate::forUser($editorA)->allows('create', [Event::class, $s->venueA]))->toBeTrue()
        ->and(Gate::forUser($editorA)->allows('view', $s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($editorA)->allows('update', $s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($editorA)->allows('delete', $s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($editorA)->allows('create', [EventOccurrence::class, $s->publishedEventA]))->toBeTrue()
        ->and(Gate::forUser($editorA)->allows('update', $s->occurrenceA))->toBeTrue();
});

it('estende al collaboratore lo stesso isolamento fra locali del referente', function (): void {
    $s = VenueIsolationScenario::make();
    $editorA = $s->editorA;

    expect(Gate::forUser($editorA)->allows('create', [Event::class, $s->venueB]))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('update', $s->draftEventB))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('delete', $s->publishedEventB))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('update', $s->occurrenceB))->toBeFalse()
        ->and(Gate::forUser($editorA)->allows('view', $s->draftEventB))->toBeFalse();
});

it('non consente a un utente semplice di modificare nulla di nessun locale', function (): void {
    $s = VenueIsolationScenario::make();
    $user = $s->plainUser;

    foreach ([$s->venueA, $s->venueB] as $venue) {
        expect(Gate::forUser($user)->allows('update', $venue))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $venue))->toBeFalse()
            ->and(Gate::forUser($user)->allows('manageCollaborators', $venue))->toBeFalse()
            ->and(Gate::forUser($user)->allows('viewSensitiveData', $venue))->toBeFalse()
            ->and(Gate::forUser($user)->allows('moderate', $venue))->toBeFalse()
            ->and(Gate::forUser($user)->allows('create', [Event::class, $venue]))->toBeFalse();
    }

    foreach ([$s->publishedEventA, $s->draftEventA, $s->publishedEventB, $s->draftEventB] as $event) {
        expect(Gate::forUser($user)->allows('update', $event))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $event))->toBeFalse()
            ->and(Gate::forUser($user)->allows('publish', $event))->toBeFalse()
            ->and(Gate::forUser($user)->allows('moderate', $event))->toBeFalse();
    }

    foreach ([$s->occurrenceA, $s->occurrenceB] as $occurrence) {
        expect(Gate::forUser($user)->allows('update', $occurrence))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $occurrence))->toBeFalse();
    }

    // Nemmeno le tassonomie e le città, che non appartengono ad alcun locale.
    expect(Gate::forUser($user)->allows('update', $s->category))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $s->city))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $s->city))->toBeFalse();
});

it('consente al moderatore di approvare senza dargli la gestione ordinaria', function (): void {
    $s = VenueIsolationScenario::make();
    $moderator = $s->moderator;

    $application = VenueApplication::factory()->create([
        'status' => ApplicationStatus::Pending,
    ]);
    $report = Report::factory()->create();
    $tag = Tag::factory()->create(['name' => 'aperitivo in corte']);

    // Approvazione e moderazione: sì.
    expect(Gate::forUser($moderator)->allows('moderate', $s->venueB))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('moderate', $s->draftEventB))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('publish', $s->draftEventB))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('update', $application))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('update', $report))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('approve', $tag))->toBeTrue();

    // Gestione ordinaria dei contenuti altrui: no.
    expect(Gate::forUser($moderator)->allows('update', $s->venueB))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('delete', $s->venueB))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('update', $s->draftEventB))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('delete', $s->draftEventB))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('update', $s->occurrenceB))->toBeFalse();
});

it('non consente al moderatore di cancellare una città né di toccare le tassonomie', function (): void {
    $s = VenueIsolationScenario::make();
    $moderator = $s->moderator;

    expect(Gate::forUser($moderator)->allows('delete', $s->city))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('update', $s->city))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('create', City::class))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('delete', $s->category))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('update', $s->category))->toBeFalse()
        ->and(Gate::forUser($moderator)->allows('create', Category::class))->toBeFalse();
});

it('consente all amministratore ogni azione sui contenuti', function (): void {
    $s = VenueIsolationScenario::make();
    $admin = $s->admin;

    $tag = Tag::factory()->create(['name' => 'concerto in cortile']);

    foreach ([$s->venueA, $s->venueB] as $venue) {
        expect(Gate::forUser($admin)->allows('view', $venue))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('update', $venue))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('delete', $venue))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('manageCollaborators', $venue))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('viewSensitiveData', $venue))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('moderate', $venue))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('create', [Event::class, $venue]))->toBeTrue();
    }

    foreach ([$s->draftEventA, $s->draftEventB] as $event) {
        expect(Gate::forUser($admin)->allows('view', $event))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('update', $event))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('delete', $event))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('forceDelete', $event))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('publish', $event))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('moderate', $event))->toBeTrue();
    }

    expect(Gate::forUser($admin)->allows('update', $s->occurrenceB))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $s->occurrenceB))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $s->category))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $tag))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $s->city))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Venue::class))->toBeTrue();
});

it('lascia pubblicare senza redazione solo ai locali con pubblicazione automatica', function (): void {
    $s = VenueIsolationScenario::make();

    // `venues.auto_publish` è la sola cosa che distingue i due casi: senza,
    // il referente propone e la redazione pubblica.
    expect(Gate::forUser($s->ownerA)->allows('publish', $s->draftEventA))->toBeFalse();

    $s->venueA->forceFill(['auto_publish' => true])->save();
    $eventA = $s->draftEventA->fresh();

    expect($eventA)->not->toBeNull()
        ->and(Gate::forUser($s->ownerA)->allows('publish', $eventA))->toBeTrue()
        ->and(Gate::forUser($s->ownerB)->allows('publish', $eventA))->toBeFalse();
});
