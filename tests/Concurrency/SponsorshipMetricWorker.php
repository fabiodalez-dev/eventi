<?php

/*
 * Un processo che manda misure di una campagna nello stesso istante degli
 * altri: lo lancia `SponsorshipMetricConcurrencyTest`, uno per connessione
 * vera al database.
 *
 * Argomenti: id della campagna, misura (`impressions` o `clicks`), cartella
 * della barriera, numero di processi, poi i gettoni. Ogni processo, avviato
 * Laravel, lascia un file nella cartella e aspetta che ci siano quelli di
 * tutti: così partono insieme anche se l'avvio di uno è stato lento. Poi manda
 * una misura per gettone e stampa quante ne ha contate, con gli istanti di
 * inizio e di fine (per controllare che si siano sovrapposti davvero).
 */

use App\Models\Sponsorship;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || ! str_starts_with((string) config('database.connections.'.config('database.default').'.database'), 'eventi_test')) {
    fwrite(STDERR, "Solo su un database di prova.\n");
    exit(2);
}

[, $campaignId, $metric, $barrier, $processes] = $argv;
$tokens = array_slice($argv, 5);

$campaign = Sponsorship::query()->findOrFail((int) $campaignId);
$verifier = app(ImpressionToken::class);
$request = Request::create('/', 'POST', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (prova di concorrenza)']);

$claims = [];
foreach ($tokens as $token) {
    $claim = $verifier->verify($token, CarbonImmutable::now('UTC'));
    if ($claim === null) {
        fwrite(STDERR, "Gettone non valido nel processo: chiavi diverse?\n");
        exit(3);
    }
    $claims[] = $claim;
}

touch($barrier.'/'.getmypid());
$deadline = microtime(true) + 90;
while (count(glob($barrier.'/*') ?: []) < (int) $processes) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "La barriera non si è riempita in tempo.\n");
        exit(4);
    }
    usleep(200);
}

$start = microtime(true);
$counted = 0;
foreach ($claims as $claim) {
    $counted += app(RecordSponsorshipMetric::class)->recordWithToken($campaign, $claim, $metric, $request, 'web') ? 1 : 0;
}

echo $counted.' '.sprintf('%.6F', $start).' '.sprintf('%.6F', microtime(true));
