<?php

declare(strict_types=1);

use App\Models\City;
use App\Queries\EventOccurrenceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Support\InstallerSandbox;

/*
 * La città creata dall'installer (D42, punto 6).
 *
 * Non è un dato anagrafico: è il perno del motore temporale (§8). Da `timezone`
 * discende «adesso», da `night_cutoff_time` la giornata evento, da
 * `starting_soon_minutes` la finestra «inizia tra poco». Una città scritta male
 * dall'installer produce un sito che si apre, risponde 200 e non mostra mai
 * niente — il guasto peggiore, perché non sembra un guasto.
 *
 * Questi casi non guardano le colonne: interrogano il motore vero.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
    Carbon::setTestNow();
});

/**
 * Percorre il wizard con le risposte indicate per la città ed esegue le cinque
 * operazioni che portano fino alla sua creazione.
 *
 * @param  array<string, string>  $city
 */
function installWithCity(array $city = []): City
{
    test()->post('/installazione/requisiti');
    test()->post('/installazione/database', testDatabaseCredentials());
    test()->post('/installazione/applicazione', [
        'app_name' => 'Prova inCittà',
        'app_url' => 'https://eventi.example.test',
        'mail_mailer' => 'log',
    ]);
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
        ...$city,
    ]);
    test()->post('/installazione/amministratore', [
        'name' => 'Chi installa',
        'email' => 'admin@example.test',
        'password' => 'password-lunga-1',
        'password_confirmation' => 'password-lunga-1',
    ]);

    for ($task = 0; $task < 5; $task++) {
        test()->post('/installazione/esecuzione');
    }

    return City::query()->firstOrFail();
}

it('crea una città che il motore temporale sa interrogare', function (): void {
    /*
     * «Adesso» è sempre `now($city->timezone)`, mai l'ora del server (§8.1).
     * Un fuso scritto male qui sposterebbe di un'ora ogni finestra del sito,
     * senza che nulla segnali l'errore.
     */
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 12:00', 'UTC'));

    $city = installWithCity();
    $query = EventOccurrenceQuery::for($city);

    expect($query->now()->timezone->getName())->toBe('Europe/Rome')
        ->and($query->now()->format('H:i'))->toBe('14:00')
        ->and($query->currentBusinessDate())->toBe('2026-09-15');
});

it('mostra in «oggi» un evento della città appena creata', function (): void {
    /*
     * Il giro completo: installazione, evento pubblicato, finestra pubblica.
     * È l'unica prova che la città creata dall'installer sia *usabile* e non
     * soltanto scritta.
     */
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 16:00', 'Europe/Rome'));

    $city = installWithCity();
    $occurrence = occurrenceAtLocal($city, testCategory(), '2026-09-15 21:00');

    expect(EventOccurrenceQuery::for($city)->today()->get()->pluck('id')->all())
        ->toBe([$occurrence->getKey()]);
});

it('rispetta i 180 minuti di «inizia tra poco» che lo schema le dà', function (): void {
    /*
     * `starting_soon_minutes` non si chiede al wizard: prende il default dello
     * schema. Se l'installer lo lasciasse a zero, la sezione «sta per
     * cominciare» sarebbe vuota per sempre.
     */
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 18:00', 'Europe/Rome'));

    $city = installWithCity();
    $category = testCategory();

    $fraPoco = occurrenceAtLocal($city, $category, '2026-09-15 19:00');
    occurrenceAtLocal($city, $category, '2026-09-15 23:00');

    expect($city->starting_soon_minutes)->toBe(180)
        ->and(EventOccurrenceQuery::for($city)->startingSoon()->get()->pluck('id')->all())
        ->toBe([$fraPoco->getKey()]);
});

it('lascia allo schema i valori che il wizard non chiede', function (): void {
    /*
     * Tredici domande e non trenta (D42, punto 3): tutto ciò che ha un default
     * sensato lo prende. Ma «lo prende» va verificato, perché una colonna
     * riempita a mano con un valore diverso dal default non darebbe alcun
     * segnale.
     */
    $city = installWithCity();

    expect($city->night_cutoff_time)->toBe('06:00:00')
        ->and($city->starting_soon_minutes)->toBe(180)
        ->and($city->default_zoom)->toBe(12)
        ->and($city->locale)->toBe('it')
        ->and($city->country_code)->toBe('IT')
        ->and($city->bounds)->toBeNull();
});

it('nasce attiva e con una data di lancio, o non comparirebbe da nessuna parte', function (): void {
    /*
     * `is_active` è il filtro dello scope pubblico: una città inattiva è un
     * sito installato che risponde a ogni indirizzo con «città inesistente».
     */
    $city = installWithCity();

    expect($city->is_active)->toBeTrue()
        ->and($city->launched_at)->not->toBeNull()
        ->and(City::query()->active()->count())->toBe(1);
});

it('non sovrascrive lo slug scelto a mano', function (): void {
    /*
     * È la scoperta di D44, punto 4: il trait `HasSlug` lo rigenera dal nome
     * sia in creazione sia in aggiornamento. Chi scrive `pd` per avere
     * indirizzi corti se lo vedrebbe diventare `padova` senza spiegazioni, e
     * gli indirizzi comunicati prima dell'apertura sarebbero già sbagliati.
     */
    $city = installWithCity(['slug' => 'padova-centro']);

    expect($city->slug)->toBe('padova-centro')
        ->and($this->sandbox->envValue('CITY_DEFAULT_SLUG'))->toBe('padova-centro');
});

it('ricava lo slug da un nome con apostrofi e accenti', function (): void {
    /*
     * Lo slug si deriva lato server e non con un po' di JavaScript: durante
     * un'installazione non è il momento di scoprire che un campo obbligatorio
     * si riempiva da solo e non l'ha fatto.
     */
    $city = installWithCity(['name' => "Reggio nell'Emilia", 'slug' => '']);

    expect($city->slug)->toBe('reggio-nellemilia');
});

it('scrive nel .env lo slug della città che ha davvero creato', function (): void {
    /*
     * `CITY_DEFAULT_SLUG` è ciò che il sito usa per sapere dove mandare chi
     * arriva sulla radice. Se non coincidesse con la città creata, la home
     * risponderebbe 404 su un'installazione appena conclusa.
     */
    $city = installWithCity();

    expect($this->sandbox->envValue('CITY_DEFAULT_SLUG'))->toBe($city->slug)
        ->and($city->slug)->toBe('padova');
});

it('non crea una seconda città se quella dello slug esiste già', function (): void {
    /*
     * L'operazione della checklist deve poter essere ripetuta: un secondo clic
     * non può produrre due città, che sul vincolo di unicità dello slug
     * fermerebbe l'installazione a un passo dalla fine.
     */
    installWithCity();

    session(['installer.completed_tasks' => ['env', 'migrazioni', 'verifica-tabelle', 'dati-di-base']]);
    $this->post('/installazione/esecuzione');

    expect(City::query()->count())->toBe(1);
});
