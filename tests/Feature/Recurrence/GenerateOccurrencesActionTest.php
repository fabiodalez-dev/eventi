<?php

declare(strict_types=1);

use App\Actions\GenerateOccurrencesAction;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use Carbon\CarbonImmutable;

/**
 * §7.8 e criterio di accettazione F1: la RRULE si espande nel fuso della città,
 * l'azione è idempotente, le `exdates` sono rispettate e annullare una singola
 * data non tocca le altre.
 */

/**
 * Serie settimanale con la prima data già inserita dal gestore: è la forma in
 * cui la ricorrenza nasce davvero nel pannello (§10.4, la sintassi RRULE non
 * viene mai mostrata).
 *
 * @param  array<string, mixed>  $recurrence
 */
function weeklySeries(
    string $rrule,
    string $firstLocalStart,
    ?string $firstLocalEnd = null,
    array $recurrence = [],
    bool $nightlife = false,
): EventRecurrence {
    $city = testCity();
    $category = testCategory(['is_nightlife' => $nightlife]);

    $occurrence = occurrenceAt(
        $city,
        $category,
        localInstant($city, $firstLocalStart)->utc()->format('Y-m-d H:i:s'),
        $firstLocalEnd === null ? null : localInstant($city, $firstLocalEnd)->utc()->format('Y-m-d H:i:s'),
    );

    return EventRecurrence::factory()->create([
        'event_id' => $occurrence->event_id,
        'rrule' => $rrule,
        ...$recurrence,
    ]);
}

/**
 * @return array<int, string> date evento delle occorrenze, in ordine cronologico
 */
function businessDatesOf(EventRecurrence $recurrence): array
{
    return EventOccurrence::query()
        ->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')
        ->get()
        ->map(fn (EventOccurrence $occurrence): string => $occurrence->business_date->toDateString())
        ->all();
}

/**
 * @return array<int, string> orari locali di inizio, in ordine cronologico
 */
function localStartsOf(EventRecurrence $recurrence, string $timezone): array
{
    return EventOccurrence::query()
        ->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')
        ->get()
        ->map(fn (EventOccurrence $occurrence): string => CarbonImmutable::instance($occurrence->starts_at)
            ->setTimezone($timezone)
            ->format('Y-m-d H:i'))
        ->all();
}

it('espande FREQ=WEEKLY;BYDAY=TH;COUNT=10 in dieci occorrenze con business_date corretta', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=10', '2026-09-03 21:00');

    $created = app(GenerateOccurrencesAction::class)($recurrence);

    // Nove create: la prima data della serie è l'occorrenza già inserita.
    expect($created)->toBe(9)
        ->and(EventOccurrence::query()->where('event_id', $recurrence->event_id)->count())->toBe(10);

    expect(businessDatesOf($recurrence))->toBe([
        '2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24', '2026-10-01',
        '2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29', '2026-11-05',
    ]);
});

it('arretra di un giorno la business_date delle date generate dopo mezzanotte per una categoria nightlife', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    // Un after che comincia all'una di notte: per l'utente è la sera prima (§8.2).
    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=FR;COUNT=3', '2026-09-04 01:00', nightlife: true);

    app(GenerateOccurrencesAction::class)($recurrence);

    expect(businessDatesOf($recurrence))->toBe(['2026-09-03', '2026-09-10', '2026-09-17']);
});

it('non duplica nulla se viene rieseguita', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=10', '2026-09-03 21:00');
    $action = app(GenerateOccurrencesAction::class);

    $action($recurrence);
    $before = EventOccurrence::query()->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')->toBase()->pluck('starts_at')->all();

    expect($action($recurrence->fresh()))->toBe(0)
        ->and($action($recurrence->fresh()))->toBe(0);

    $after = EventOccurrence::query()->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')->toBase()->pluck('starts_at')->all();

    expect($after)->toBe($before)
        ->and(EventOccurrence::query()->where('event_id', $recurrence->event_id)->count())->toBe(10);
});

it('rispetta le exdates, sia come giornata sia come istante', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=5', '2026-09-03 21:00', recurrence: [
        'exdates' => ['2026-09-17', '2026-09-24 21:00:00'],
    ]);

    app(GenerateOccurrencesAction::class)($recurrence);

    expect(businessDatesOf($recurrence))->toBe([
        '2026-09-03', '2026-09-10', '2026-10-01',
    ]);
});

it('mantiene l\'ora locale attraverso il cambio di ora legale', function (): void {
    freezeLocal(testCity(), '2027-03-01 12:00');

    // 28 marzo 2027: ultima domenica di marzo, si passa all'ora legale.
    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=6', '2027-03-04 21:00');

    app(GenerateOccurrencesAction::class)($recurrence);

    expect(localStartsOf($recurrence, 'Europe/Rome'))->toBe([
        '2027-03-04 21:00', '2027-03-11 21:00', '2027-03-18 21:00',
        '2027-03-25 21:00', '2027-04-01 21:00', '2027-04-08 21:00',
    ]);

    // Stessa ora locale, istante UTC diverso: prima del cambio le 21:00 romane
    // sono le 20:00 UTC, dopo le 19:00.
    $utc = EventOccurrence::query()
        ->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')
        ->get()
        ->map(fn (EventOccurrence $occurrence): string => CarbonImmutable::instance($occurrence->starts_at)->utc()->format('Y-m-d H:i'))
        ->all();

    expect($utc[3])->toBe('2027-03-25 20:00')
        ->and($utc[4])->toBe('2027-04-01 19:00');
});

it('mantiene l\'ora locale attraverso il ritorno all\'ora solare', function (): void {
    freezeLocal(testCity(), '2026-10-01 12:00');

    // 25 ottobre 2026: ultima domenica di ottobre, si torna all'ora solare.
    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=4', '2026-10-15 22:30');

    app(GenerateOccurrencesAction::class)($recurrence);

    expect(localStartsOf($recurrence, 'Europe/Rome'))->toBe([
        '2026-10-15 22:30', '2026-10-22 22:30', '2026-10-29 22:30', '2026-11-05 22:30',
    ]);
});

it('eredita durata e orario porte dalla prima occorrenza della serie', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=3', '2026-09-03 21:00', '2026-09-03 23:30');

    app(GenerateOccurrencesAction::class)($recurrence);

    $generated = EventOccurrence::query()
        ->where('event_id', $recurrence->event_id)
        ->whereNotNull('recurrence_id')
        ->orderBy('starts_at')
        ->get();

    expect($generated)->toHaveCount(2);

    foreach ($generated as $occurrence) {
        $starts = CarbonImmutable::instance($occurrence->starts_at)->setTimezone('Europe/Rome');
        $ends = CarbonImmutable::instance($occurrence->ends_at)->setTimezone('Europe/Rome');

        expect($ends->format('H:i'))->toBe('23:30')
            ->and($starts->diffInMinutes($ends))->toBe(150.0)
            ->and(CarbonImmutable::instance($occurrence->effective_ends_at)->equalTo($ends))->toBeTrue();
    }
});

it('si ferma alla data di fine della ricorrenza', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH', '2026-09-03 21:00', recurrence: [
        'until' => localInstant(testCity(), '2026-09-30 23:59')->utc(),
    ]);

    app(GenerateOccurrencesAction::class)($recurrence);

    expect(businessDatesOf($recurrence))->toBe([
        '2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24',
    ]);
});

it('annullare una singola occorrenza non tocca le altre e la marca come eccezione', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=5', '2026-09-03 21:00');

    app(GenerateOccurrencesAction::class)($recurrence);

    $occurrences = EventOccurrence::query()
        ->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')
        ->get();

    $target = $occurrences[2];
    $target->status = OccurrenceStatus::Cancelled;
    $target->status_note = 'Annullato per lutto cittadino.';
    $target->save();

    expect($target->fresh()->is_exception)->toBeTrue();

    foreach ($occurrences as $index => $occurrence) {
        if ($index === 2) {
            continue;
        }

        $fresh = $occurrence->fresh();

        expect($fresh->status)->toBe(OccurrenceStatus::Scheduled)
            ->and($fresh->is_exception)->toBeFalse()
            ->and($fresh->starts_at->equalTo($occurrence->starts_at))->toBeTrue();
    }
});

it('non rigenera la data annullata e lascia intatta l\'eccezione', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=5', '2026-09-03 21:00');
    $action = app(GenerateOccurrencesAction::class);
    $action($recurrence);

    $target = EventOccurrence::query()
        ->where('event_id', $recurrence->event_id)
        ->orderBy('starts_at')
        ->get()[2];

    $target->update(['status' => OccurrenceStatus::Cancelled]);

    expect($action($recurrence->fresh()))->toBe(0);

    $fresh = $target->fresh();

    expect($fresh->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($fresh->is_exception)->toBeTrue()
        ->and(EventOccurrence::query()->where('event_id', $recurrence->event_id)->count())->toBe(5);
});

it('non genera nulla per un evento senza città o senza regola', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('   ', '2026-09-03 21:00');

    expect(app(GenerateOccurrencesAction::class)($recurrence))->toBe(0)
        ->and(EventOccurrence::query()->where('event_id', $recurrence->event_id)->count())->toBe(1);
});

it('genera le occorrenze anche per un evento cestinato, senza perderle al ripristino', function (): void {
    freezeLocal(testCity(), '2026-09-01 12:00');

    $recurrence = weeklySeries('FREQ=WEEKLY;BYDAY=TH;COUNT=3', '2026-09-03 21:00');

    Event::query()->whereKey($recurrence->event_id)->firstOrFail()->delete();

    expect(app(GenerateOccurrencesAction::class)($recurrence->fresh()))->toBe(2);
});
