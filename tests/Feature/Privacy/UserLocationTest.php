<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Venue;
use App\Services\RememberedLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §16: «**Nessuna coordinata GPS dell'utente viene salvata**: la posizione
 * serve solo alla query».
 *
 * È una promessa scritta nella Privacy Policy pubblicata sul sito, quindi non
 * basta averla rispettata il giorno in cui è stata scritta: basta un `Log::`
 * di troppo, una colonna aggiunta a `event_views_daily` per «capire da dove
 * arrivano», un filtro salvato in sessione, e la promessa diventa falsa senza
 * che nulla si rompa.
 *
 * Il controllo è quindi fatto **al contrario**: si percorre il sito e l'API
 * con una posizione riconoscibile, poi si guarda ogni riga di ogni tabella del
 * database cercando quei numeri. Un test che sapesse in anticipo dove
 * guardare non proteggerebbe da chi inventa un posto nuovo.
 */

/** Latitudine e longitudine irripetibili: nessun dato di serie può contenerle. */
const POSIZIONE_LAT = '45.406733';
const POSIZIONE_LNG = '11.876814';

/**
 * I nomi delle tabelle di **questo** database.
 *
 * `Schema::getTableListing()` interroga il server, non lo schema: su una
 * macchina di sviluppo che ospita anche altri progetti restituisce le loro
 * tabelle, e la lettura successiva fallisce con «table doesn't exist».
 *
 * @return list<string>
 */
function tabelleDelDatabase(): array
{
    $nome = (string) DB::connection()->getDatabaseName();

    return array_map(
        static fn (object $riga): string => (string) $riga->TABLE_NAME,
        DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$nome, 'BASE TABLE'],
        ),
    );
}

/**
 * Ogni valore scritto nel database, tabella per tabella, come stringa unica.
 */
function contenutoDelDatabase(): string
{
    $righe = [];

    foreach (tabelleDelDatabase() as $tabella) {
        foreach (DB::table($tabella)->get() as $riga) {
            $righe[] = json_encode($riga, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    return implode("\n", $righe);
}

/**
 * I nomi di colonna che tradirebbero l'intenzione di conservare la posizione
 * di chi consulta il sito, cercati **solo** nelle tabelle che parlano di
 * persone: `venues.location` è la posizione di un locale, ed è il dato del
 * locale, non di chi lo cerca.
 *
 * @return list<string>
 */
function colonneSospettePerLePersone(): array
{
    $sospette = [];

    $tabelle = ['users', 'devices', 'saved_events', 'follows', 'consent_logs', 'notification_log', 'sessions', 'event_views_daily'];

    foreach ($tabelle as $tabella) {
        if (! Schema::hasTable($tabella)) {
            continue;
        }

        foreach (Schema::getColumnListing($tabella) as $colonna) {
            /* Explicit six-month opt-in storage; ordinary searches must still leave no coordinates.
               `location_lat` e `location_lng` sono lo stesso valore della colonna cifrata, in chiaro perché una colonna cifrata non si può interrogare in SQL e il raggio della proposta serale dovrebbe altrimenti decifrare ogni utente a ogni invio. Dal 24/09/2026 sono precise (`docs/DECISIONS.md`): quello che le tiene innocue non è più l'approssimazione ma il resto del patto — consenso esplicito, nessuna cronologia, sei mesi, cancellazione che porta via tutto — e sono quelle quattro cose che i test qui sotto sorvegliano. */
            if ($tabella === 'users' && in_array($colonna, ['remembered_location', 'location_expires_at', 'location_lat', 'location_lng'], true)) {
                continue;
            }
            if (preg_match('/(^|_)(lat|lng|latitude|longitude|location|coords|coordinates|geo|position)($|_)/i', $colonna) === 1) {
                $sospette[] = $tabella.'.'.$colonna;
            }
        }
    }

    return $sospette;
}

it('non ha in nessuna tabella delle persone una colonna dove mettere la posizione', function (): void {
    expect(colonneSospettePerLePersone())->toBe([]);
});

/**
 * Cosa protegge l'eccezione delle due colonne interrogabili, ora che sono precise.
 *
 * Finché erano arrotondate a un chilometro, il tipo della colonna era la garanzia: nessuno poteva conservare un indirizzo in un campo che non lo conteneva. Dal 24/09/2026 quella garanzia non c'è più, e resta solo il patto scritto nell'informativa — si conserva l'ultima posizione e nient'altro, per sei mesi, e la cancellazione porta via tutto insieme.
 *
 * Un patto di cui nessun test verifica le tre parti è una frase in una pagina. Queste sono le tre parti.
 */
it('tiene una sola posizione, senza cronologia, e la cancella tutta insieme', function (): void {
    $user = User::factory()->create();

    app(RememberedLocation::class)->save(45.4067331, 11.8768142, $user);
    $user->refresh();

    // Precisa quanto le coordinate dei locali: è con quelle che viene confrontata.
    expect((float) $user->location_lat)->toBe(45.4067331)
        ->and((float) $user->location_lng)->toBe(11.8768142)
        ->and($user->remembered_location['lat'])->toBe(45.4067331)
        // Nel futuro, e non oltre sei mesi: `diffInDays` su una data futura è
        // negativo, quindi da solo avrebbe accettato anche una scadenza fra anni.
        ->and($user->location_expires_at?->isFuture())->toBeTrue()
        ->and($user->location_expires_at?->lessThanOrEqualTo(now()->addMonthsNoOverflow(6)))->toBeTrue();

    // Una posizione nuova sostituisce la precedente: nessuna riga in più da nessuna parte.
    $prima = contenutoDelDatabase();
    app(RememberedLocation::class)->save(45.5000000, 11.9000000, $user->fresh());
    expect(contenutoDelDatabase())->not->toContain('45.4067331')
        ->and(mb_substr_count(contenutoDelDatabase(), '45.5000000'))
        ->toBe(mb_substr_count($prima, '45.4067331'));

    // E la cancellazione non ne lascia metà.
    app(RememberedLocation::class)->forget($user->fresh());
    $user->refresh();

    expect($user->location_lat)->toBeNull()->and($user->location_lng)->toBeNull()
        ->and($user->remembered_location)->toBeNull()->and($user->location_expires_at)->toBeNull();
});

/**
 * Le quattro colonne della posizione non escono mai da sole.
 *
 * Sono nascoste alla serializzazione del modello: senza, basterebbe un `toJson()` da qualunque punto — una risposta API, un payload di notifica, un registro — perché la posizione precisa di qualcuno finisse dove nessuno la stava cercando.
 */
it('non lascia uscire le colonne della posizione da una serializzazione', function (): void {
    $user = User::factory()->create();
    app(RememberedLocation::class)->save(45.4067331, 11.8768142, $user);

    $json = $user->fresh()->toJson();

    expect($json)->not->toContain('location_lat')->not->toContain('location_lng')
        ->not->toContain('remembered_location')->not->toContain('45.4067331');
});

it('non scrive niente da nessuna parte quando il sito viene percorso con una posizione', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-10 18:00');
    occurrenceAtLocal($city, $category, '2026-09-12 21:00');

    $prima = contenutoDelDatabase();

    $this->get('/eventi?lat='.POSIZIONE_LAT.'&lng='.POSIZIONE_LNG.'&radius=5')->assertOk();
    $this->get('/mappa?lat='.POSIZIONE_LAT.'&lng='.POSIZIONE_LNG)->assertOk();

    expect(contenutoDelDatabase())
        ->not->toContain(POSIZIONE_LAT)
        ->not->toContain(POSIZIONE_LNG)
        ->and(contenutoDelDatabase())->toBe($prima);
});

it('non scrive la posizione passata all API, nemmeno per un utente riconosciuto', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-10 18:00');
    occurrenceAtLocal($city, $category, '2026-09-12 21:00');

    $user = User::factory()->create();
    $token = $user->createToken('Telefono')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/events?near='.POSIZIONE_LAT.','.POSIZIONE_LNG.'&radius_km=5')
        ->assertOk();

    expect(contenutoDelDatabase())
        ->not->toContain(POSIZIONE_LAT)
        ->not->toContain(POSIZIONE_LNG);
});

/**
 * La posizione **serve** davvero alla query: se non servisse a niente, non
 * salvarla sarebbe facile e il test sopra non dimostrerebbe nulla.
 */
it('usa la posizione per rispondere, e la dimentica subito dopo', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-10 18:00');

    $vicino = occurrenceAtLocal($city, $category, '2026-09-12 21:00', venue: Venue::factory()
        ->approved()
        ->at((float) POSIZIONE_LAT, (float) POSIZIONE_LNG)
        ->create(['city_id' => $city->getKey()]));

    $lontano = occurrenceAtLocal($city, $category, '2026-09-12 22:00', venue: Venue::factory()
        ->approved()
        ->at(43.6, 12.9)
        ->create(['city_id' => $city->getKey()]));

    $risposta = $this->getJson('/api/v1/events?near='.POSIZIONE_LAT.','.POSIZIONE_LNG.'&radius_km=5')->assertOk();

    expect($risposta->json('data.*.occurrence_id'))->toContain((int) $vicino->getKey())
        ->and($risposta->json('data.*.occurrence_id'))->not->toContain((int) $lontano->getKey())
        ->and(contenutoDelDatabase())->not->toContain(POSIZIONE_LAT);
});

/**
 * La cache di pagina è l'altro posto dove una posizione potrebbe restare:
 * `CachePage` non salva le richieste che ne portano una (§11.7).
 */
it('non conserva in cache la pagina chiesta da una posizione', function (): void {
    config()->set('page-cache.enabled', true);

    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-10 18:00');
    occurrenceAtLocal($city, $category, '2026-09-12 21:00');

    $this->get('/eventi?near='.POSIZIONE_LAT.','.POSIZIONE_LNG)->assertOk();

    expect(contenutoDelDatabase())->not->toContain(POSIZIONE_LAT);
});
