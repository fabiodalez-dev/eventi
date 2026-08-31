<?php

declare(strict_types=1);

use App\Enums\ImportSourceType;
use App\Enums\NotificationType;
use App\Jobs\Import\ImportSourceJob;
use App\Models\City;
use App\Models\ImportSource;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Notifications\DigestPlanner;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/**
 * Gli interruttori di §12 dello stack: Pennant «serve per accendere funzioni
 * per singola città». Non basta che esistano — devono spegnere qualcosa.
 */
function sorgenteAttiva(City $city): ImportSource
{
    return ImportSource::factory()->create([
        'city_id' => $city->getKey(),
        'type' => ImportSourceType::Ics,
        'is_active' => true,
    ]);
}

it('accende l\'import di una città nuova', function (): void {
    $city = testCity();

    expect(Features::importActiveFor($city))->toBeTrue();
});

it('spegne l\'import di una sola città', function (): void {
    $padova = testCity();
    $verona = City::factory()->create(['name' => 'Verona', 'slug' => 'verona']);

    Feature::for($padova)->deactivate(Features::CITY_IMPORT);

    expect(Features::importActiveFor($padova))->toBeFalse()
        ->and(Features::importActiveFor($verona))->toBeTrue();
});

/**
 * L'interruttore deve spegnere l'import **davvero**: nessun lavoro accodato
 * per le sorgenti della città spenta.
 */
it('l\'esecuzione oraria salta le sorgenti della città spenta', function (): void {
    Queue::fake();

    $padova = testCity();
    $verona = City::factory()->create(['name' => 'Verona', 'slug' => 'verona']);

    sorgenteAttiva($padova);
    $rimasta = sorgenteAttiva($verona);

    Feature::for($padova)->deactivate(Features::CITY_IMPORT);

    $this->artisan('import:run')->assertSuccessful();

    Queue::assertPushed(ImportSourceJob::class, 1);
    Queue::assertPushed(
        ImportSourceJob::class,
        fn (ImportSourceJob $job): bool => (new ReflectionProperty($job, 'sourceId'))->getValue($job) === (int) $rimasta->getKey(),
    );
});

it('con l\'import acceso accoda tutte le sorgenti', function (): void {
    Queue::fake();

    sorgenteAttiva(testCity());

    $this->artisan('import:run')->assertSuccessful();

    Queue::assertPushed(ImportSourceJob::class, 1);
});

it('la newsletter nasce accesa e si spegne per tutto il sistema', function (): void {
    expect(Features::newsletterActive())->toBeTrue();

    Feature::for(Features::globalScope())->deactivate(Features::NEWSLETTER);

    expect(Features::newsletterActive())->toBeFalse();
});

/**
 * §16 del compito: «una feature flag spenta nasconde davvero ciò che deve».
 */
it('con la newsletter spenta il consenso non viene più chiesto', function (): void {
    testCity();

    $this->get(route('account.register'))->assertOk()->assertSee('marketing_opt_in', false);

    Feature::for(Features::globalScope())->deactivate(Features::NEWSLETTER);

    $this->get(route('account.register'))->assertOk()->assertDontSee('marketing_opt_in', false);
});

it('con la newsletter spenta la casella sparisce anche dal profilo', function (): void {
    testCity();

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('account.profile'))->assertSee('marketing_opt_in', false);

    Feature::for(Features::globalScope())->deactivate(Features::NEWSLETTER);

    $this->actingAs($user)->get(route('account.profile'))->assertDontSee('marketing_opt_in', false);
});

/**
 * Il consenso già dato è un atto della persona, non una funzione del sistema:
 * spegnere la newsletter non lo cancella, e il salvataggio di un altro campo
 * non lo interpreta come una revoca.
 */
it('spegnere la newsletter non revoca i consensi già dati', function (): void {
    testCity();

    $consenso = now()->subMonth();
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'marketing_opt_in_at' => $consenso,
        'timezone' => 'Europe/Rome',
        'locale' => 'it',
    ]);

    Feature::for(Features::globalScope())->deactivate(Features::NEWSLETTER);

    $this->actingAs($user)
        ->patch(route('account.profile.update'), [
            'name' => 'Chi si è iscritto',
            'timezone' => 'Europe/Rome',
            'locale' => 'it',
        ])
        ->assertRedirect();

    expect($user->refresh()->marketing_opt_in_at)->not->toBeNull();
});

/**
 * Il contrappeso del test precedente: con la newsletter accesa la casella c'è,
 * e toglierla revoca davvero. Senza questa verifica, «non revoca» potrebbe
 * voler dire soltanto che nessuno revoca mai.
 */
it('con la newsletter accesa togliere la spunta revoca il consenso', function (): void {
    testCity();

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'marketing_opt_in_at' => now()->subMonth(),
        'timezone' => 'Europe/Rome',
        'locale' => 'it',
    ]);

    $this->actingAs($user)
        ->patch(route('account.profile.update'), [
            'name' => 'Chi si è iscritto',
            'timezone' => 'Europe/Rome',
            'locale' => 'it',
        ])
        ->assertRedirect();

    expect($user->refresh()->marketing_opt_in_at)->toBeNull();
});

/**
 * §15.9: con la newsletter spenta non si mette in coda nulla, nemmeno per chi
 * il consenso lo aveva dato.
 */
it('con la newsletter spenta non programma alcun invio del weekend', function (): void {
    /* La newsletter esce di giovedì alle 16:00 e il pianificatore guarda
       avanti 36 ore: se «adesso» non è mercoledì, non ci sarebbe niente da
       programmare e il test direbbe di sì per il motivo sbagliato. */
    Carbon\Carbon::setTestNow(CarbonImmutable::parse('2026-09-02 10:00:00', 'Europe/Rome'));

    testCity();

    User::factory()->create([
        'email_verified_at' => now(),
        'marketing_opt_in_at' => now()->subMonth(),
        'timezone' => 'Europe/Rome',
    ]);

    $accesa = app(DigestPlanner::class)->plan();

    expect($accesa[NotificationType::WeekendNewsletter->value])->toBeGreaterThan(0);

    ScheduledNotification::query()->delete();
    Feature::for(Features::globalScope())->deactivate(Features::NEWSLETTER);

    $spenta = app(DigestPlanner::class)->plan();

    expect($spenta[NotificationType::WeekendNewsletter->value])->toBe(0);
});
