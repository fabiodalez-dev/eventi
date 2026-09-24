<?php

declare(strict_types=1);

use App\Models\EventViewDaily;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueMonthlyReport as VenueMonthlyReportNotification;
use App\Services\Analytics\VenueMonthlyReport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Il rapporto mensile ai referenti dei locali.
 *
 * Tre cose lo distinguono da un riepilogo qualunque, e sono quelle protette
 * qui: non esce dal perimetro del locale, non parte quando non ha niente da
 * dire, e rispetta l'interruttore prima di spedire invece che dopo.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-10-01 08:00');
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function reportVenue(string $name = 'Circolo Aurora'): Venue
{
    return Venue::factory()->approved()->create(['city_id' => test()->city->getKey(), 'name' => $name]);
}

function reportOwner(Venue $venue, array $attributes = []): User
{
    $owner = User::factory()->create($attributes);
    $venue->members()->attach($owner, ['role' => 'owner']);

    return $owner;
}

/** Una data del mese di settembre con qualche apertura e un salvataggio. */
function septemberActivity(Venue $venue, int $views = 40): void
{
    $occurrence = occurrenceAtLocal(test()->city, test()->category, '2026-09-12 21:00', '2026-09-12 23:00', venue: $venue);
    EventViewDaily::query()->create(['event_id' => $occurrence->event_id, 'date' => '2026-09-12', 'views' => $views]);
    /* `created_at` non e' fra i campi riempibili: passarlo a `create()` lo fa
       ignorare in silenzio e il salvataggio prende l'istante congelato, che in
       questi test e' il primo ottobre. Finche' la finestra del rapporto
       sconfinava nel mese seguente il test passava lo stesso — per il motivo
       sbagliato — e smetteva di proteggere proprio il confine del mese. */
    SavedEvent::query()->create(['user_id' => User::factory()->create()->getKey(), 'occurrence_id' => $occurrence->getKey()])
        ->forceFill(['created_at' => CarbonImmutable::parse('2026-09-12 12:00', 'Europe/Rome')])->save();
}

it('conta solo ciò che appartiene al locale e mai i numeri di un altro', function (): void {
    $mine = reportVenue();
    $other = reportVenue('Teatro Belzoni');
    septemberActivity($mine, views: 40);
    septemberActivity($other, views: 900);

    $report = app(VenueMonthlyReport::class)->forMonth($mine, CarbonImmutable::parse('2026-09-01'));

    expect($report['totals']['views'])->toBe(40)
        ->and($report['totals']['saves'])->toBe(1)
        ->and($report['label'])->toBe('settembre 2026')
        ->and($report['empty'])->toBeFalse();
});

it('non manda niente a un locale senza attività nel mese', function (): void {
    $venue = reportVenue();
    reportOwner($venue);

    $this->artisan('venues:monthly-report')->assertSuccessful();

    Notification::assertNothingSent();
});

it('manda il rapporto ai referenti, e non ai collaboratori', function (): void {
    $venue = reportVenue();
    $owner = reportOwner($venue);
    $editor = User::factory()->create();
    $venue->members()->attach($editor, ['role' => 'editor']);
    septemberActivity($venue);

    $this->artisan('venues:monthly-report')->assertSuccessful();

    Notification::assertSentTo($owner, VenueMonthlyReportNotification::class);
    Notification::assertNotSentTo($editor, VenueMonthlyReportNotification::class);
});

it('rispetta chi ha spento il rapporto e chi non può ricevere email', function (): void {
    $venue = reportVenue();
    $off = reportOwner($venue, ['notification_preferences' => ['venue_report' => false]]);
    $unverified = reportOwner($venue, ['email_verified_at' => null]);
    septemberActivity($venue);

    $this->artisan('venues:monthly-report')->assertSuccessful();

    Notification::assertNotSentTo($off, VenueMonthlyReportNotification::class);
    Notification::assertNotSentTo($unverified, VenueMonthlyReportNotification::class);
});

it('non spedisce nulla in prova generale', function (): void {
    $venue = reportVenue();
    reportOwner($venue);
    septemberActivity($venue);

    $this->artisan('venues:monthly-report', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
});

it('confronta con il mese precedente solo quando esiste un termine di paragone', function (): void {
    $venue = reportVenue();
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-08-10 21:00', '2026-08-10 23:00', venue: $venue);
    EventViewDaily::query()->create(['event_id' => $occurrence->event_id, 'date' => '2026-08-10', 'views' => 20]);
    septemberActivity($venue, views: 40);

    $report = app(VenueMonthlyReport::class)->forMonth($venue, CarbonImmutable::parse('2026-09-01'));

    expect($report['totals']['views'])->toBe(40)->and($report['previous']['views'])->toBe(20);
});

/**
 * Il mese finisce quando finisce nel fuso del locale.
 *
 * Il primo ottobre a Roma comincia due ore prima di quanto dica l'orologio
 * UTC: costruendo la fine del mese da un istante UTC e allungandola a fine
 * giornata, il rapporto di settembre si prendeva anche tutto il primo ottobre,
 * e il confronto con agosto tutto il primo settembre — cioè dentro il mese che
 * doveva servire da paragone.
 */
it('non sconfina nel mese seguente per via del fuso', function (): void {
    $venue = reportVenue();
    septemberActivity($venue, views: 40);

    $ottobre = occurrenceAtLocal($this->city, $this->category, '2026-10-01 21:00', '2026-10-01 23:00', venue: $venue);
    EventViewDaily::query()->create(['event_id' => $ottobre->event_id, 'date' => '2026-10-01', 'views' => 500]);

    $report = app(VenueMonthlyReport::class)->forMonth($venue, CarbonImmutable::parse('2026-09-01'));

    expect($report['totals']['views'])->toBe(40);
});

/**
 * Un secondo lancio non rimanda niente.
 *
 * È il caso normale, non quello raro: il comando si rilancia a mano quando il
 * primo sembra andato storto, e senza registro chi aveva già ricevuto il
 * rapporto lo riceve due volte. Una email di troppo in un rapporto mensile è
 * la ragione per cui la gente spegne le notifiche.
 */
it('non rimanda il rapporto a chi lo ha già ricevuto', function (): void {
    $venue = reportVenue();
    $owner = reportOwner($venue, ['email_verified_at' => now()]);
    septemberActivity($venue);

    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();
    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();

    Notification::assertSentToTimes($owner, VenueMonthlyReportNotification::class, 1);
});

it('la prova generale non consuma il turno di nessuno', function (): void {
    $venue = reportVenue();
    $owner = reportOwner($venue, ['email_verified_at' => now()]);
    septemberActivity($venue);

    $this->artisan('venues:monthly-report', ['--month' => '2026-09', '--dry-run' => true])->assertSuccessful();
    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();

    Notification::assertSentToTimes($owner, VenueMonthlyReportNotification::class, 1);
});

/**
 * Una presa in carico rimasta a metà non perde il rapporto.
 *
 * Se il processo muore fra la presa in carico e l'invio, con un solo istante
 * scritto la riga direbbe «mandato» di un rapporto mai partito, e il vincolo
 * unico impedirebbe per sempre di ritentare. Il riconoscimento della presa in
 * carico orfana è ciò che rende il registro una protezione dai doppioni invece
 * che un modo nuovo di perdere le email.
 */
it('recupera una presa in carico interrotta e manda il rapporto', function (): void {
    $venue = reportVenue();
    $owner = reportOwner($venue, ['email_verified_at' => now()]);
    septemberActivity($venue);

    // Il processo di ieri ha preso in carico e non ha mai spedito.
    DB::table('venue_monthly_reports')->insert([
        'venue_id' => $venue->getKey(), 'user_id' => $owner->getKey(), 'month' => '2026-09-01',
        'claimed_at' => CarbonImmutable::now()->subDay(), 'sent_at' => null,
        'created_at' => CarbonImmutable::now()->subDay(), 'updated_at' => CarbonImmutable::now()->subDay(),
    ]);

    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();

    Notification::assertSentToTimes($owner, VenueMonthlyReportNotification::class, 1);
    expect(DB::table('venue_monthly_reports')->whereNotNull('sent_at')->count())->toBe(1);
});

it('non ritenta una presa in carico appena fatta da un altro processo', function (): void {
    $venue = reportVenue();
    $owner = reportOwner($venue, ['email_verified_at' => now()]);
    septemberActivity($venue);

    // Un altro processo sta spedendo proprio adesso: non si tocca.
    DB::table('venue_monthly_reports')->insert([
        'venue_id' => $venue->getKey(), 'user_id' => $owner->getKey(), 'month' => '2026-09-01',
        'claimed_at' => CarbonImmutable::now(), 'sent_at' => null,
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
    ]);

    $this->artisan('venues:monthly-report', ['--month' => '2026-09'])->assertSuccessful();

    Notification::assertNothingSent();
});
