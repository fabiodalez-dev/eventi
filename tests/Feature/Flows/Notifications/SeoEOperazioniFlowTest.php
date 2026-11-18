<?php

declare(strict_types=1);

use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\MagicLoginLink;
use App\Support\EventUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;

/**
 * Un controllo di stato che fallisce sempre: «degradato» deve essere una
 * condizione che il test produce, non una che capita alla macchina.
 */
final class FlussoOperazioniControlloGuasto extends Check
{
    public function run(): Result
    {
        return Result::make()->failed('la coda non smaltisce');
    }
}

/**
 * Ciò che leggono i programmi e non le persone: il motore di ricerca che
 * parte da `robots.txt` e arriva alle schede, l'assistente che legge
 * `llms.txt`, il telefono che installa l'app web, il monitor di uptime con la
 * sua chiave, l'integrazione continua che chiede lo stato del rilascio. E la
 * pagina di ripiego del collegamento magico dell'app.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

it('un motore di ricerca parte da robots.txt, percorre la mappa del sito fino alla scheda, e un assistente trova la stessa data in llms.txt', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Auditorium di prova']);
    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30', event: ['title' => 'Serata da indicizzare'], venue: $venue);

    // 1. robots.txt: testo semplice, pannelli chiusi, la mappa dichiarata con indirizzo assoluto.
    $robots = $this->get('/robots.txt')->assertOk();

    expect($robots->headers->get('Content-Type'))->toContain('text/plain')
        ->and($robots->getContent())->toContain('User-agent: *')->toContain('Disallow: /admin');

    preg_match('/^Sitemap: (\S+)$/m', (string) $robots->getContent(), $dichiarata);

    expect($dichiarata[1] ?? null)->toBe(route('sitemap.index'));

    // 2. L'indice rimanda alle mappe per sezione, e ognuna è XML valido.
    $indice = $this->get($dichiarata[1])->assertOk();
    expect($indice->headers->get('Content-Type'))->toContain('application/xml');

    $documento = simplexml_load_string((string) $indice->getContent());
    expect($documento)->not->toBeFalse();

    $sezioni = [];
    foreach ($documento->sitemap as $nodo) {
        $sezioni[] = (string) $nodo->loc;
    }

    expect($sezioni)->not->toBeEmpty();

    $pagine = [];
    foreach ($sezioni as $sezione) {
        expect($sezione)->toMatch('#/sitemap-[a-z]+-\d+\.xml$#');

        $xml = simplexml_load_string((string) $this->get($sezione)->assertOk()->getContent());
        expect($xml)->not->toBeFalse();

        foreach ($xml->url as $nodo) {
            $pagine[] = (string) $nodo->loc;
        }
    }

    // 3. Fra le pagine dichiarate ci sono la scheda della data e il locale, e si aprono.
    $scheda = EventUrl::occurrence($data);

    expect($pagine)->toContain($scheda)
        ->toContain(route('venues.show', $venue))
        ->toContain(route('events.date', ['date' => '2026-09-12']));

    $this->get($scheda)->assertOk()->assertSee('Serata da indicizzare');
    $this->get(route('venues.show', $venue))->assertOk()->assertSee('Auditorium di prova');

    // Una sezione inventata o una pagina oltre la fine non esistono.
    $this->get('/sitemap-inventata-1.xml')->assertNotFound();
    $this->get('/sitemap-eventi-99.xml')->assertNotFound();

    // 4. L'assistente legge llms.txt: la stessa data, con ora e indirizzo.
    $llms = $this->get('/llms.txt')->assertOk();

    expect($llms->headers->get('Content-Type'))->toContain('text/plain')
        ->and($llms->getContent())
        ->toStartWith('# '.config()->string('app.name'))
        ->toContain('Serata da indicizzare')
        ->toContain($scheda)
        ->toContain('12/09/2026 21:30');

    // 5. Il telefono installa l'app web dal manifesto.
    $manifesto = $this->get('/site.webmanifest')->assertOk();

    expect($manifesto->headers->get('Content-Type'))->toContain('application/manifest+json')
        ->and($manifesto->json('short_name'))->toBe($this->city->name)
        ->and($manifesto->json('display'))->toBe('standalone')
        ->and($manifesto->json('start_url'))->toBeString();
});

it('il monitor di uptime legge lo stato solo con la chiave, e il rilascio senza segreto non esiste', function (): void {
    Health::clearChecks();
    Health::checks([DatabaseCheck::new()]);

    // 1. Senza chiave configurata le rotte operative non esistono per nessuno.
    config(['health.secret_token' => null]);

    $this->withHeader('X-Secret-Token', 'qualunque')->get('/stato')->assertNotFound();
    $this->withHeader('X-Secret-Token', 'qualunque')->get('/stato/completo')->assertNotFound();
    $this->postJson('/rilascio', ['branch' => 'main'])->assertNotFound();

    // 2. Con la chiave configurata, chi non la porta o la sbaglia riceve comunque 404.
    config(['health.secret_token' => 'chiave-del-monitor']);
    $this->flushHeaders();

    $this->get('/stato')->assertNotFound();
    $this->get('/stato?token=chiave-del-monitor')->assertNotFound();
    $this->withHeader('X-Secret-Token', 'chiave-sbagliata')->get('/stato')->assertNotFound();
    $this->withHeader('X-Secret-Token', 'chiave-sbagliata')->postJson('/rilascio', ['branch' => 'main'])->assertNotFound();

    // 3. Il controllo programmato salva l'esito; il monitor con la chiave lo rilegge.
    $this->artisan('health:check')->assertSuccessful();

    $verde = $this->withHeader('X-Secret-Token', 'chiave-del-monitor')->get('/stato')
        ->assertOk()
        ->assertJson(['healthy' => true]);

    // Chi interroga è un programma: nessuna sessione, nessun cookie.
    expect($verde->headers->getCookies())->toBe([]);

    // 4. Un controllo diventa rosso: 503, e il dettaglio dice quale e perché.
    Health::clearChecks();
    Health::checks([DatabaseCheck::new(), FlussoOperazioniControlloGuasto::new()]);
    $this->artisan('health:check')->assertSuccessful();

    $this->withHeader('X-Secret-Token', 'chiave-del-monitor')->get('/stato')->assertStatus(503);

    $dettaglio = $this->withHeader('X-Secret-Token', 'chiave-del-monitor')->get('/stato/completo')->assertStatus(503);
    $rosso = collect($dettaglio->json('checkResults'))->firstWhere('name', 'FlussoOperazioniControlloGuasto');

    expect($rosso)->not->toBeNull()
        ->and($rosso['status'])->toBe('failed')
        ->and($rosso['notificationMessage'])->toBe('la coda non smaltisce');

    // 5. Lo stato del rilascio è pubblico ma non indicizzabile né in cache, e non rivela segreti.
    $this->flushHeaders();
    $rilascio = $this->get('/release-status')
        ->assertOk()
        ->assertJsonStructure(['sha', 'deployed_at'])
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertDontSee('chiave-del-monitor');

    expect($rilascio->headers->get('Cache-Control'))->toContain('no-store');
});

it('apre dal browser il collegamento magico dell app senza rifletterne il gettone e lo scambia una volta sola dall app', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();

    $teresa = User::factory()->create(['email' => 'teresa@example.test']);

    $verificatore = str_repeat('v', 43);
    $sfida = rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');

    // 1. L'app chiede il collegamento con la sfida PKCE.
    $this->postJson('/api/v1/auth/magic-link', ['email' => 'teresa@example.test', 'code_challenge' => $sfida])->assertOk();

    $collegamento = '';
    Notification::assertSentTo($teresa, MagicLoginLink::class, function (MagicLoginLink $notifica) use ($teresa, &$collegamento): bool {
        $collegamento = (string) $notifica->toMail($teresa)->actionUrl;

        return true;
    });

    expect($collegamento)->toContain('/app/auth/magic?token=');

    parse_str((string) parse_url($collegamento, PHP_URL_QUERY), $query);
    $gettone = (string) ($query['token'] ?? '');

    expect($gettone)->toHaveLength(64);

    // 2. Il telefono lo apre nel browser: pagina di ripiego, senza il gettone nell'HTML.
    $pagina = $this->get($collegamento)
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($pagina->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $pagina->getContent())->not->toContain($gettone)
        ->toContain('intent://auth/magic');

    // 3. Un verificatore sbagliato non apre e non brucia il collegamento.
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $gettone, 'code_verifier' => str_repeat('x', 43), 'device_name' => 'Telefono di Teresa'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');

    expect(MobileAuthChallenge::query()->sole()->used_at)->toBeNull();

    // 4. Lo scambio giusto dà il token, e con quello l'app legge l'area personale.
    $scambio = $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $gettone, 'code_verifier' => $verificatore, 'device_name' => 'Telefono di Teresa'])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'teresa@example.test');

    $this->withToken((string) $scambio->json('data.token'))->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 0);

    // 5. Lo stesso collegamento non vale una seconda volta.
    $this->flushHeaders();
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $gettone, 'code_verifier' => $verificatore, 'device_name' => 'Un altro telefono'])
        ->assertStatus(400);

    expect($teresa->fresh()?->tokens()->count())->toBe(1);
});
