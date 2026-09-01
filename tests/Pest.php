<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\InstallerStep;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
 * Pennant tiene in memoria il valore di un interruttore appena lo risolve, e
 * quella cache vive quanto il processo — cioe' attraversa i test, mentre
 * `RefreshDatabase` azzera la tabella `features` fra l'uno e l'altro.
 *
 * Il risultato e' un test che dipende da chi ha girato prima: se qualcuno ha
 * gia' chiesto «la newsletter e' accesa?» il valore resta in memoria e il test
 * successivo legge quello invece di ricalcolarlo sul database appena svuotato.
 * Si manifesta come un fallimento ogni tante esecuzioni, che sparisce appena
 * si prova il test da solo — la forma piu' fastidiosa di intermittenza, perche'
 * il tentativo di riprodurla la fa svanire.
 */
uses()->beforeEach(function (): void {
    Feature::flushCache();
})->in('Feature');

/**
 * La città pilota: fuso Europe/Rome, cutoff notturno alle 06:00, "inizia tra
 * poco" a 180 minuti. Sono i valori su cui §8 fonda tutti i suoi esempi.
 *
 * @param  array<string, mixed>  $attributes
 */
function testCity(array $attributes = []): City
{
    return City::factory()->padova()->create($attributes);
}

/**
 * Categoria con durata predefinita, usata quando l'occorrenza non porta
 * un'ora di fine (§8.3).
 *
 * @param  array<string, mixed>  $attributes
 */
function testCategory(array $attributes = []): Category
{
    return Category::factory()->create([
        'name' => 'Musica dal vivo',
        'default_duration_minutes' => 180,
        'supports_ongoing' => true,
        'is_nightlife' => false,
        ...$attributes,
    ]);
}

/**
 * Un'occorrenza pubblicata, ancorata a istanti espressi **in UTC**.
 *
 * Gli istanti si passano in UTC di proposito: il cast `datetime` di Eloquent
 * scrive l'ora dell'oggetto così com'è, senza convertirla, quindi passare un
 * orario locale salverebbe un istante sbagliato di uno o due fusi.
 *
 * @param  array<string, mixed>  $occurrence  attributi dell'occorrenza
 * @param  array<string, mixed>  $event  attributi dell'evento
 */
function occurrenceAt(
    City $city,
    Category $category,
    string $startsAtUtc,
    ?string $endsAtUtc = null,
    array $occurrence = [],
    array $event = [],
    ?Venue $venue = null,
): EventOccurrence {
    $venue ??= Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    $eventModel = Event::factory()->create([
        'city_id' => $city->getKey(),
        'category_id' => $category->getKey(),
        'venue_id' => $venue->getKey(),
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC'),
        ...$event,
    ]);

    return EventOccurrence::factory()->create([
        'event_id' => $eventModel->getKey(),
        'starts_at' => CarbonImmutable::parse($startsAtUtc, 'UTC'),
        'ends_at' => $endsAtUtc === null ? null : CarbonImmutable::parse($endsAtUtc, 'UTC'),
        'doors_at' => null,
        ...$occurrence,
    ]);
}

/**
 * La stessa occorrenza, ma con gli orari scritti come li scrive un gestore:
 * nell'ora locale della città.
 *
 * @param  array<string, mixed>  $occurrence  attributi dell'occorrenza
 * @param  array<string, mixed>  $event  attributi dell'evento
 */
function occurrenceAtLocal(
    City $city,
    Category $category,
    string $localStartsAt,
    ?string $localEndsAt = null,
    array $occurrence = [],
    array $event = [],
    ?Venue $venue = null,
): EventOccurrence {
    return occurrenceAt(
        $city,
        $category,
        localInstant($city, $localStartsAt)->utc()->format('Y-m-d H:i:s'),
        $localEndsAt === null ? null : localInstant($city, $localEndsAt)->utc()->format('Y-m-d H:i:s'),
        $occurrence,
        $event,
        $venue,
    );
}

/**
 * Stesso istante espresso nell'ora locale della città: è così che sono scritti
 * gli scenari di §18 ("evento 18:00–20:00", "sono le 19:00").
 */
function localInstant(City $city, string $localDateTime): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, $city->timezone);
}

/**
 * Fissa "adesso" a un orario locale della città. §8.1: l'adesso del motore è
 * sempre quello della città, mai quello del server.
 */
function freezeLocal(City $city, string $localDateTime): CarbonImmutable
{
    $instant = localInstant($city, $localDateTime);

    Carbon::setTestNow($instant);

    return $instant;
}

/**
 * @param  iterable<int, EventOccurrence>  $occurrences
 * @return array<int, int>
 */
function idsOf(iterable $occurrences): array
{
    $ids = [];

    foreach ($occurrences as $occurrence) {
        $ids[] = (int) $occurrence->getKey();
    }

    return $ids;
}

/**
 * Se un eseguibile esiste sul PATH. Serve ai test che dipendono da un
 * programma esterno — `mysqldump` per il backup del database — per dichiarare
 * di essere stati saltati invece di fallire su una macchina che non ce l'ha.
 */
function shellCommandExists(string $command): bool
{
    exec('command -v '.escapeshellarg($command).' 2>/dev/null', $output, $status);

    return $status === 0;
}

/**
 * I cookie appena impostati da una risposta, pronti a essere rimandati indietro
 * nella richiesta successiva — che è ciò che farebbe un browser.
 *
 * Arrivano già cifrati da `EncryptCookies`, quindi vanno rispediti verbatim:
 * `withCookies()` cifrerebbe una seconda volta un valore già cifrato, e il
 * middleware lo scarterebbe come illeggibile. Un cookie con valore vuoto è un
 * cookie che il server sta **cancellando**: non si rimanda indietro.
 *
 * @return array<string, string>
 */
function cookiesFrom(TestResponse $response): array
{
    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $value = (string) $cookie->getValue();

        if ($value !== '') {
            $cookies[$cookie->getName()] = $value;
        }
    }

    return $cookies;
}

/**
 * Una richiesta che porta con sé **esattamente** i cookie indicati, e nessun
 * altro.
 *
 * `withCookies()` e `withUnencryptedCookies()` non vanno bene qui: accumulano
 * sul caso di prova e restano attaccati alle richieste successive, quindi un
 * test che verifica cosa succede *dopo* che un cookie è stato cancellato
 * continuerebbe a mandarlo. I valori si passano già cifrati, così come escono
 * da `cookiesFrom()`.
 *
 * @param  array<string, string>  $cookies
 */
function requestWithCookies(string $method, string $uri, array $cookies = []): TestResponse
{
    return test()->call($method, $uri, [], $cookies);
}

/**
 * Le credenziali del database su cui la suite sta già girando: sono quelle che
 * i test dell'installer (D42) fanno digitare al passo 2, così la prova di
 * connessione è una prova vera e non una finzione.
 *
 * @return array<string, string>
 */
function testDatabaseCredentials(): array
{
    return [
        'db_host' => config()->string('database.connections.mariadb.host'),
        'db_port' => (string) config('database.connections.mariadb.port'),
        'db_database' => config()->string('database.connections.mariadb.database'),
        'db_username' => config()->string('database.connections.mariadb.username'),
        'db_password' => (string) config('database.connections.mariadb.password'),
    ];
}

/**
 * Compila i cinque moduli del wizard fino all'amministratore compreso, e
 * lascia la sessione ferma davanti alla checklist di esecuzione.
 */
function completeThroughAdmin(): void
{
    test()->post('/installazione/requisiti')->assertRedirect(InstallerStep::Database->url());

    test()->post('/installazione/database', testDatabaseCredentials())
        ->assertRedirect(InstallerStep::Application->url());

    test()->post('/installazione/applicazione', [
        'app_name' => 'Prova inCittà',
        'app_url' => 'https://eventi.example.test',
        'mail_mailer' => 'log',
    ])->assertRedirect(InstallerStep::City->url());

    test()->post('/installazione/citta', [
        'name' => 'Padova',
        'slug' => '',
        'province_code' => 'pd',
        'province_name' => 'Padova',
        'region' => 'Veneto',
        'timezone' => 'Europe/Rome',
        'center_lat' => '45.4064',
        'center_lng' => '11.8768',
        'radius_km' => '30',
    ])->assertRedirect(InstallerStep::Admin->url());

    test()->post('/installazione/amministratore', [
        'name' => 'Chi installa',
        'email' => 'admin@example.test',
        'password' => 'password-lunga-1',
        'password_confirmation' => 'password-lunga-1',
    ])->assertRedirect(InstallerStep::Run->url());
}
