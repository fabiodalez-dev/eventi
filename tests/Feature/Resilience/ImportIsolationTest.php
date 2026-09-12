<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Jobs\Import\ImportSourceJob;
use App\Models\Event;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Services\Import\HostResolver;
use App\Services\Import\ImportRunner;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\IcsFixtures;

/**
 * §14.2 — una sorgente irraggiungibile è la normalità, non l'eccezione: i
 * calendari stanno su server di altri, che vanno giù, cambiano indirizzo o
 * rispondono con una pagina di cortesia.
 *
 * Le due cose che non devono succedere sono diverse fra loro e si rompono in
 * momenti diversi:
 *
 * 1. **la lettura di un calendario rotto non deve fermare gli altri** — con
 *    venticinque locali, una sorgente morta metterebbe in pausa l'intera
 *    città;
 * 2. **non deve cancellare niente** — un feed che non risponde non sta
 *    dicendo «quegli eventi non esistono più», e trattarlo così cancella
 *    serate vere.
 */
beforeEach(function (): void {
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->city = testCity();
    $this->category = testCategory();

    Carbon::setTestNow(localInstant($this->city, '2026-08-20 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function sorgenteDiImport(string $url, array $attributes = []): ImportSource
{
    return ImportSource::factory()->create([
        'city_id' => test()->city->getKey(),
        'url' => $url,
        'default_category_id' => test()->category->getKey(),
        ...$attributes,
    ]);
}

/**
 * La sorgente rotta sta **in mezzo**: se stesse in fondo, un ciclo che si
 * interrompe al primo errore passerebbe lo stesso e il test non direbbe nulla.
 */
it('legge le sorgenti dopo quella che non risponde', function (): void {
    $prima = sorgenteDiImport('https://uno.test/eventi.ics');
    $rotta = sorgenteDiImport('https://giu.test/eventi.ics');
    $ultima = sorgenteDiImport('https://tre.test/eventi.ics');

    Http::fake([
        'https://uno.test/*' => Http::response(IcsFixtures::body('three-date-forms'), 200, ['Content-Type' => 'text/calendar']),
        'https://giu.test/*' => static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        },
        'https://tre.test/*' => Http::response(IcsFixtures::body('three-date-forms'), 200, ['Content-Type' => 'text/calendar']),
    ]);

    $this->artisan('import:run', ['--sync' => true])->assertSuccessful();

    expect($prima->fresh()?->last_status)->toBe(ImportRunStatus::Success->value)
        ->and($rotta->fresh()?->last_status)->toBe(ImportRunStatus::Failed->value)
        ->and($ultima->fresh()?->last_status)->toBe(ImportRunStatus::Success->value);
});

it('scrive comunque l esito di ciascuna sorgente, anche di quella caduta', function (): void {
    $rotta = sorgenteDiImport('https://giu.test/eventi.ics');
    $buona = sorgenteDiImport('https://su.test/eventi.ics');

    Http::fake([
        'https://giu.test/*' => Http::response('Service Unavailable', 503),
        'https://su.test/*' => Http::response(IcsFixtures::body('three-date-forms'), 200, ['Content-Type' => 'text/calendar']),
    ]);

    $this->artisan('import:run', ['--sync' => true])->assertSuccessful();

    expect(ImportRun::query()->where('import_source_id', $rotta->getKey())->count())->toBe(1)
        ->and(ImportRun::query()->where('import_source_id', $buona->getKey())->count())->toBe(1)
        ->and(ImportRun::query()->where('import_source_id', $rotta->getKey())->value('status'))
        ->toBe(ImportRunStatus::Failed->value);
});

/**
 * Il caso che costa dati: il calendario ha già prodotto eventi, poi il server
 * smette di rispondere. Nessuno di quegli eventi deve sparire dal sito.
 */
it('non cancella nulla di ciò che aveva già importato quando il server sparisce', function (): void {
    $sorgente = sorgenteDiImport(IcsFixtures::URL);

    IcsFixtures::fake('three-date-forms');
    $this->artisan('import:run', ['--sync' => true])->assertSuccessful();

    $importati = Event::query()->where('status', EventStatus::Published->value)->count();
    expect($importati)->toBeGreaterThan(0);

    IcsFixtures::fakeFailure(new ConnectionException('cURL error 7: Failed to connect'));

    $this->artisan('import:run', ['--sync' => true])->assertSuccessful();

    expect(Event::query()->where('status', EventStatus::Published->value)->count())->toBe($importati)
        ->and($sorgente->fresh()?->last_status)->toBe(ImportRunStatus::Failed->value);
});

/**
 * In produzione ogni sorgente è un lavoro in coda a sé (§14.2): il guasto di
 * uno non arriva nemmeno vicino agli altri, perché sono processi separati. Il
 * lavoro rilancia, così la coda può riprovare — e l'importante è che
 * riprovare riguardi **quella** sorgente e nessun'altra.
 */
it('tiene il guasto dentro il lavoro della sola sorgente caduta', function (): void {
    $rotta = sorgenteDiImport('https://giu.test/eventi.ics');
    $buona = sorgenteDiImport('https://su.test/eventi.ics');

    Http::fake([
        'https://giu.test/*' => static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        },
        'https://su.test/*' => Http::response(IcsFixtures::body('three-date-forms'), 200, ['Content-Type' => 'text/calendar']),
    ]);

    $runner = app(ImportRunner::class);

    try {
        (new ImportSourceJob((int) $rotta->getKey()))->handle($runner);
    } catch (Throwable) {
        // Rilanciato di proposito: è così che la coda riprova (§14.2).
    }

    (new ImportSourceJob((int) $buona->getKey()))->handle($runner);

    expect($buona->fresh()?->last_status)->toBe(ImportRunStatus::Success->value)
        ->and(Event::query()->count())->toBeGreaterThan(0);
});
