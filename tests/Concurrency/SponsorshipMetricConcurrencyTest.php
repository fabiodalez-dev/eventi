<?php

use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipImpressionNonce;
use App\Models\SponsorshipSlotHourly;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/* Le tabelle si svuotano fra un test e l'altro invece di ricreare lo schema:
   i processi lanciati devono vedere righe vere, fuori da ogni transazione. */
uses(TestCase::class, DatabaseTruncation::class);

/*
 * Le misure di una campagna mandate tutte nello stesso istante, da connessioni
 * vere e separate al database: è il caso che i test in un processo solo non
 * vedono.
 *
 * La deduplica è un `INSERT IGNORE` sulla chiave primaria del numero casuale:
 * cinquanta richieste con lo stesso gettone ne contano una, cinquanta con
 * cinquanta gettoni ne contano cinquanta, e nessun aggregato perde un pezzo.
 */

const P3_PROCESSI = 10;
const P3_PER_PROCESSO = 5;

/**
 * Lancia i processi con i loro gettoni e restituisce quante misure ha contato
 * ognuno. Controlla anche che i processi abbiano lavorato davvero insieme:
 * ognuno è partito prima che l'ultimo finisse.
 *
 * @param  list<list<string>>  $gettoniPerProcesso
 * @return list<int>
 */
function p3MisureInsieme(Sponsorship $campagna, string $misura, array $gettoniPerProcesso): array
{
    $connessione = config('database.connections.'.config('database.default'));
    $barriera = sys_get_temp_dir().'/p3-barriera-'.bin2hex(random_bytes(6));
    mkdir($barriera);
    $processi = [];

    foreach ($gettoniPerProcesso as $gettoni) {
        $processo = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/SponsorshipMetricWorker.php'), (string) $campagna->getKey(), $misura, $barriera, (string) count($gettoniPerProcesso), ...$gettoni],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_HOST' => $connessione['host'],
                'DB_PORT' => (string) $connessione['port'],
                'DB_DATABASE' => $connessione['database'],
                'DB_USERNAME' => $connessione['username'],
                'DB_PASSWORD' => (string) $connessione['password'],
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'PAGE_CACHE_ENABLED' => 'false',
            ],
        );
        $processo->setTimeout(180);
        $processo->start();
        $processi[] = $processo;
    }

    $contate = [];
    $inizi = [];
    $fini = [];
    foreach ($processi as $processo) {
        $processo->wait();
        expect($processo->isSuccessful())->toBeTrue($processo->getErrorOutput());
        [$contato, $inizio, $fine] = explode(' ', trim($processo->getOutput()));
        $contate[] = (int) $contato;
        $inizi[] = (float) $inizio;
        $fini[] = (float) $fine;
    }

    array_map('unlink', glob($barriera.'/*') ?: []);
    rmdir($barriera);

    expect(max($inizi))->toBeLessThan(min($fini) + 0.5, 'I processi non si sono sovrapposti');

    return $contate;
}

beforeEach(function (): void {
    $città = testCity();
    $occorrenza = occurrenceAt($città, testCategory(), now('UTC')->addDays(3)->format('Y-m-d 19:00:00'));
    $this->campagna = Sponsorship::factory()->create(['city_id' => $città->id, 'event_id' => $occorrenza->event_id]);
});

it('conta una volta sola cinquanta visualizzazioni simultanee con lo stesso gettone', function (): void {
    $gettone = ImpressionToken::issue($this->campagna, 'list_top');

    $contate = p3MisureInsieme($this->campagna, 'impressions', array_fill(0, P3_PROCESSI, array_fill(0, P3_PER_PROCESSO, $gettone)));

    expect(array_sum($contate))->toBe(1)
        ->and($this->campagna->fresh()->impressions)->toBe(1)
        ->and((int) SponsorshipDailyStat::query()->sum('impressions'))->toBe(1)
        ->and((int) SponsorshipSlotHourly::query()->sum('sponsored_views'))->toBe(1)
        ->and(SponsorshipImpressionNonce::query()->count())->toBe(1);
});

it('conta cinquanta visualizzazioni simultanee con cinquanta gettoni, senza perderne una', function (): void {
    $gettoni = [];
    foreach (range(1, P3_PROCESSI) as $ignored) {
        $gettoni[] = array_map(fn (): string => ImpressionToken::issue($this->campagna, 'list_top'), range(1, P3_PER_PROCESSO));
    }

    $contate = p3MisureInsieme($this->campagna, 'impressions', $gettoni);

    expect(array_sum($contate))->toBe(50)
        ->and($this->campagna->fresh()->impressions)->toBe(50)
        ->and((int) SponsorshipDailyStat::query()->sum('impressions'))->toBe(50)
        ->and(SponsorshipDailyStat::query()->count())->toBe(1)
        ->and((int) SponsorshipSlotHourly::query()->sum('sponsored_views'))->toBe(50)
        ->and(SponsorshipSlotHourly::query()->count())->toBe(1)
        ->and(SponsorshipImpressionNonce::query()->count())->toBe(50);
});

it('conta un clic solo, con la sua visualizzazione, per cinquanta clic simultanei con lo stesso gettone', function (): void {
    $gettone = ImpressionToken::issue($this->campagna, 'home_card');

    $contate = p3MisureInsieme($this->campagna, 'clicks', array_fill(0, P3_PROCESSI, array_fill(0, P3_PER_PROCESSO, $gettone)));

    expect(array_sum($contate))->toBe(1)
        ->and($this->campagna->fresh()->clicks)->toBe(1)
        ->and($this->campagna->fresh()->impressions)->toBe(1)
        ->and(SponsorshipClick::query()->count())->toBe(1)
        ->and((int) SponsorshipSlotHourly::query()->sum('clicks'))->toBe(1);
});

it('non supera l obiettivo di una campagna singola con cinquanta gettoni simultanei', function (): void {
    $this->campagna->forceFill(['impressions_goal' => 20])->saveQuietly();
    $gettoni = [];
    foreach (range(1, P3_PROCESSI) as $ignored) {
        $gettoni[] = array_map(fn (): string => ImpressionToken::issue($this->campagna, 'list_top'), range(1, P3_PER_PROCESSO));
    }

    $contate = p3MisureInsieme($this->campagna, 'impressions', $gettoni);

    expect(array_sum($contate))->toBe(20)
        ->and($this->campagna->fresh()->impressions)->toBe(20)
        ->and((int) SponsorshipDailyStat::query()->sum('impressions'))->toBe(20);
});
