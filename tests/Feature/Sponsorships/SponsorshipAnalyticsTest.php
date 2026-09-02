<?php

declare(strict_types=1);

use App\Enums\SponsorshipPhase;
use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Notifications\SponsorshipWeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/*
 * Le misure giorno per giorno, la fase calcolata e il riepilogo al
 * committente.
 *
 * Prima c'erano due contatori cumulativi e basta: dicevano un totale e niente
 * altro. «Quante visualizzazioni a novembre?» non aveva risposta, una campagna
 * sospesa e ripresa mescolava due periodi, e un errore che gonfiava il
 * contatore era irreversibile perche' non si sapeva quando fosse entrato — si
 * fatturava su un numero indifendibile.
 */

it('tiene una riga per campagna e per giorno, non una per visualizzazione', function (): void {
    /*
     * L'`upsert` e' cio' che rende questa tabella sostenibile: cresce con i
     * giorni, non con il traffico. Con una riga per evento, una campagna vista
     * diecimila volte ne produrrebbe diecimila.
     */
    $campagna = Sponsorship::factory()->create();

    foreach (range(1, 25) as $ignored) {
        SponsorshipDailyStat::registra($campagna->getKey(), 'impressions');
    }

    SponsorshipDailyStat::registra($campagna->getKey(), 'clicks');

    $righe = SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->getKey())->get();

    expect($righe)->toHaveCount(1)
        ->and($righe->first()->impressions)->toBe(25)
        ->and($righe->first()->clicks)->toBe(1);
});

it('separa i giorni, cosi si puo rispondere a «quante a novembre»', function (): void {
    $campagna = Sponsorship::factory()->create();

    SponsorshipDailyStat::registra($campagna->getKey(), 'impressions', CarbonImmutable::parse('2026-11-10'));
    SponsorshipDailyStat::registra($campagna->getKey(), 'impressions', CarbonImmutable::parse('2026-11-10'));
    SponsorshipDailyStat::registra($campagna->getKey(), 'impressions', CarbonImmutable::parse('2026-12-01'));

    $novembre = SponsorshipDailyStat::query()
        ->where('sponsorship_id', $campagna->getKey())
        ->whereBetween('day', ['2026-11-01', '2026-11-30'])
        ->sum('impressions');

    expect((int) $novembre)->toBe(2);
});

it('registra la misura giornaliera quando qualcuno guarda una campagna', function (): void {
    /* Il collegamento fra il controller delle misure e questa tabella: se si
       rompe, i totali continuano a salire e la storia resta vuota — cioe' il
       difetto torna senza che niente lo segnali. */
    $campagna = Sponsorship::factory()->create();

    $this->postJson(route('sponsorships.metric', [
        'sponsorship' => $campagna,
        'metric' => 'impressions',
    ]))->assertNoContent();

    /* `sum()` torna una stringa dal driver: il cast dice cosa si sta
       confrontando invece di far fallire il test su un dettaglio del tipo. */
    expect((int) SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->getKey())->sum('impressions'))
        ->toBe(1)
        /* E il contatore cumulativo resta, perche' serve all'elenco. */
        ->and($campagna->refresh()->impressions)->toBe(1);
});

it('dice a che punto e una campagna senza bisogno di un processo notturno', function (): void {
    /*
     * La fase si ricava da stato piu' finestra. E' il motivo per cui dice
     * sempre il vero: uno stato aggiornato da un comando schedulato mente non
     * appena quel comando non gira.
     */
    $adesso = CarbonImmutable::parse('2026-09-15 12:00');

    $inCorso = Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'starts_at' => $adesso->subDays(3),
        'ends_at' => $adesso->addDays(3),
    ]);

    $finita = Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'starts_at' => $adesso->subDays(30),
        'ends_at' => $adesso->subDay(),
    ]);

    $programmata = Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'starts_at' => $adesso->addDays(5),
        'ends_at' => $adesso->addDays(15),
    ]);

    $sospesa = Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Paused,
        'starts_at' => $adesso->subDay(),
        'ends_at' => $adesso->addDay(),
    ]);

    $bozza = Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Draft,
        'starts_at' => $adesso->subDay(),
        'ends_at' => $adesso->addDay(),
    ]);

    expect($inCorso->phase($adesso))->toBe(SponsorshipPhase::Running)
        /* Il caso che ha motivato tutto: nell'elenco si leggeva «attiva». */
        ->and($finita->phase($adesso))->toBe(SponsorshipPhase::Ended)
        ->and($programmata->phase($adesso))->toBe(SponsorshipPhase::Scheduled)
        ->and($sospesa->phase($adesso))->toBe(SponsorshipPhase::Paused)
        ->and($bozza->phase($adesso))->toBe(SponsorshipPhase::Draft);
});

it('manda il riepilogo settimanale a chi ha pagato', function (): void {
    Notification::fake();

    $campagna = Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'advertiser_email' => 'committente@prova.test',
        'starts_at' => CarbonImmutable::now()->subDays(20),
        'ends_at' => CarbonImmutable::now()->addDays(20),
    ]);

    SponsorshipDailyStat::registra($campagna->getKey(), 'impressions', CarbonImmutable::now()->subDays(2));

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertSentOnDemand(SponsorshipWeeklyReport::class);
});

it('non scrive a chi non ha lasciato un indirizzo', function (): void {
    Notification::fake();

    Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'advertiser_email' => null,
        'starts_at' => CarbonImmutable::now()->subDays(20),
        'ends_at' => CarbonImmutable::now()->addDays(20),
    ]);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('non scrive a una campagna appena partita', function (): void {
    /*
     * Un riepilogo di due giorni sembra una misura e invece e' rumore, e la
     * prima impressione che si porta dietro e' «questa cosa conta poco».
     */
    Notification::fake();

    Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'advertiser_email' => 'appena@partita.test',
        'starts_at' => CarbonImmutable::now()->subDay(),
        'ends_at' => CarbonImmutable::now()->addDays(20),
    ]);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('non scrive a una campagna finita', function (): void {
    /* Quel messaggio non dice niente di nuovo e arriva quando non c'e' piu'
       niente da fare. */
    Notification::fake();

    Sponsorship::factory()->create([
        'status' => SponsorshipStatus::Active,
        'advertiser_email' => 'finita@prova.test',
        'starts_at' => CarbonImmutable::now()->subDays(40),
        'ends_at' => CarbonImmutable::now()->subDay(),
    ]);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('e schedulato: un riepilogo che nessuno lancia non parte', function (): void {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'sponsorships:report'));

    expect($evento)->not->toBeNull();
});

it('le email del sito finiscono in italiano fino in fondo', function (): void {
    /*
     * Il commiato e la frase sotto il pulsante arrivano dal framework, e
     * uscivano in inglese in TUTTE le notifiche del sito — reset password,
     * accesso, promemoria. «Regards,» in fondo a un messaggio italiano.
     */
    $traduzioni = json_decode((string) file_get_contents(base_path('lang/it.json')), true);

    expect($traduzioni)->toBeArray()
        ->and($traduzioni)->toHaveKey('Regards,')
        ->and($traduzioni['Regards,'])->not->toBe('Regards,')
        ->and(implode(' ', array_keys($traduzioni)))->toContain('trouble clicking');
});
