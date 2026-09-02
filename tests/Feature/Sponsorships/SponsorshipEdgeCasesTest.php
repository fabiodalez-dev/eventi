<?php

declare(strict_types=1);

use App\Enums\SponsorshipPhase;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\User;
use App\Notifications\SponsorshipWeeklyReport;
use App\Services\Sponsorship\CategoriePreferite;
use App\Services\Sponsorship\SponsorshipSelector;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
 * I casi limite del sistema di sponsorizzazioni: quelli che non si incontrano
 * provando a mano, e che si scoprono quando c'e' un cliente che paga.
 *
 * Non ripetono cio' che gia' verificano gli altri sette file — selezione,
 * trasparenza, permessi, misure, peso, affinita'. Coprono i bordi: date
 * esatte, cancellazioni, concorrenza, isolamento fra utenti e fra citta',
 * numeri che diventano fatture.
 */

beforeEach(function (): void {
    Cache::flush();

    $this->citta = testCity();
    $this->categoria = testCategory();
});

function campagnaDiProva(array $attributi = []): Sponsorship
{
    $occorrenza = occurrenceAtLocal(
        test()->citta,
        $attributi['categoria'] ?? test()->categoria,
        '2026-09-20 21:00:00',
    );

    unset($attributi['categoria']);

    return Sponsorship::factory()->create(array_merge([
        'city_id' => test()->citta->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
        'status' => SponsorshipStatus::Active,
        'starts_at' => CarbonImmutable::now()->subDay(),
        'ends_at' => CarbonImmutable::now()->addDay(),
    ], $attributi));
}

/* ─────────────────  I bordi delle date  ───────────────── */

it('mostra una campagna nell istante esatto in cui comincia', function (): void {
    /* `>=` e non `>`: un minuto comprato e' un minuto dovuto, e chi vende
       finestre al minuto se ne accorge al primo controllo. */
    $istante = CarbonImmutable::parse('2026-09-10 18:00:00');
    campagnaDiProva(['starts_at' => $istante, 'ends_at' => $istante->addHours(2)]);

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero, $istante))
        ->toHaveCount(1);
});

it('mostra una campagna nell istante esatto in cui finisce', function (): void {
    $fine = CarbonImmutable::parse('2026-09-10 20:00:00');
    campagnaDiProva(['starts_at' => $fine->subHours(2), 'ends_at' => $fine]);

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero, $fine))
        ->toHaveCount(1);
});

it('la smette un secondo dopo la fine', function (): void {
    $fine = CarbonImmutable::parse('2026-09-10 20:00:00');
    campagnaDiProva(['starts_at' => $fine->subHours(2), 'ends_at' => $fine]);

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero, $fine->addSecond()))
        ->toBeEmpty();
});

it('dice «in corso» nell istante di partenza e «finita» un attimo dopo la fine', function (): void {
    $inizio = CarbonImmutable::parse('2026-09-10 18:00:00');
    $campagna = campagnaDiProva(['starts_at' => $inizio, 'ends_at' => $inizio->addHour()]);

    expect($campagna->phase($inizio))->toBe(SponsorshipPhase::Running)
        ->and($campagna->phase($inizio->addHour()))->toBe(SponsorshipPhase::Running)
        ->and($campagna->phase($inizio->addHour()->addSecond()))->toBe(SponsorshipPhase::Ended);
});

/* ─────────────────  Cancellazioni  ───────────────── */

it('porta via le misure giornaliere quando la campagna viene cancellata davvero', function (): void {
    /* `cascadeOnDelete` a schema: righe orfane in una tabella di misure sono
       numeri che non si possono piu' attribuire a nessuno. */
    $campagna = campagnaDiProva();
    SponsorshipDailyStat::registra($campagna->getKey(), 'impressions');

    expect(SponsorshipDailyStat::query()->count())->toBe(1);

    $campagna->forceDelete();

    expect(SponsorshipDailyStat::query()->count())->toBe(0);
});

it('tiene le misure quando la campagna e solo cestinata', function (): void {
    /*
     * Il cestino non e' una cancellazione: una campagna ripristinata deve
     * ritrovare i propri numeri, altrimenti il ripristino perde proprio la
     * cosa che si sta cercando di recuperare.
     */
    $campagna = campagnaDiProva();
    SponsorshipDailyStat::registra($campagna->getKey(), 'impressions');

    $campagna->delete();

    expect(SponsorshipDailyStat::query()->count())->toBe(1);
});

it('non mostra una campagna cestinata', function (): void {
    $campagna = campagnaDiProva();
    $campagna->delete();

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero))
        ->toBeEmpty();
});

/* ─────────────────  Concorrenza sulle misure  ───────────────── */

it('non perde una misura quando due arrivano insieme sullo stesso giorno', function (): void {
    /*
     * L'`upsert` esiste per questo. Un `firstOrCreate` seguito da `increment`
     * sarebbero due query e una corsa: fra la lettura e la scrittura un'altra
     * richiesta crea la stessa riga, il vincolo di unicita' fa fallire la
     * seconda, e una misura sparisce per un dettaglio di ordine.
     */
    $campagna = campagnaDiProva();
    $giorno = CarbonImmutable::parse('2026-09-15');

    foreach (range(1, 50) as $ignored) {
        SponsorshipDailyStat::registra($campagna->getKey(), 'impressions', $giorno);
    }

    $riga = SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->getKey())->first();

    expect(SponsorshipDailyStat::query()->count())->toBe(1)
        ->and($riga?->impressions)->toBe(50);
});

it('tiene separate le misure di campagne diverse nello stesso giorno', function (): void {
    $una = campagnaDiProva();
    $altra = campagnaDiProva();
    $giorno = CarbonImmutable::parse('2026-09-15');

    SponsorshipDailyStat::registra($una->getKey(), 'impressions', $giorno);
    SponsorshipDailyStat::registra($altra->getKey(), 'clicks', $giorno);

    expect(SponsorshipDailyStat::query()->count())->toBe(2)
        ->and((int) SponsorshipDailyStat::query()->where('sponsorship_id', $una->getKey())->value('impressions'))->toBe(1)
        ->and((int) SponsorshipDailyStat::query()->where('sponsorship_id', $altra->getKey())->value('clicks'))->toBe(1);
});

it('il totale cumulativo coincide con la somma dei giorni', function (): void {
    /*
     * I due numeri devono raccontare la stessa storia: il contatore sulla
     * campagna e' comodo per l'elenco, la somma dei giorni e' quella che si
     * puo' difendere davanti a chi contesta una fattura. Se divergono, uno dei
     * due sta mentendo e non si sa quale.
     */
    $campagna = campagnaDiProva();

    foreach (range(1, 7) as $ignored) {
        $this->postJson(route('sponsorships.metric', ['sponsorship' => $campagna, 'metric' => 'impressions']));
    }

    $somma = (int) SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->getKey())->sum('impressions');

    expect($campagna->refresh()->impressions)->toBe($somma);
});

/* ─────────────────  Tetti di consegna  ───────────────── */

it('smette di mostrare una campagna che ha esaurito il tetto di aperture', function (): void {
    $campagna = campagnaDiProva();
    $campagna->forceFill(['clicks' => 50, 'clicks_cap' => 50])->save();

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero))
        ->toBeEmpty();
});

it('mostra una campagna che e a un passo dal tetto', function (): void {
    /* Il confronto e' `<`: il tetto e' il numero a cui si smette, non quello
       dopo il quale si smette. Uno scarto di uno su un contratto a
       visualizzazioni e' una discussione. */
    $campagna = campagnaDiProva();
    $campagna->forceFill(['impressions' => 999, 'impressions_cap' => 1000])->save();

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero))
        ->toHaveCount(1);
});

it('un tetto raggiunto non cambia lo stato della campagna', function (): void {
    /*
     * Finita per esaurimento e sospesa da qualcuno sono due cose diverse, e la
     * differenza si deve poter leggere: la prima e' andata come doveva, la
     * seconda e' un problema da qualche parte.
     */
    $campagna = campagnaDiProva();
    $campagna->forceFill(['impressions' => 1000, 'impressions_cap' => 1000])->save();

    expect($campagna->refresh()->status)->toBe(SponsorshipStatus::Active)
        ->and($campagna->phase())->toBe(SponsorshipPhase::Running);
});

/* ─────────────────  Isolamento  ───────────────── */

it('non mescola le campagne di due citta', function (): void {
    $altra = testCity(['name' => 'Altrove', 'slug' => 'altrove-prova']);

    campagnaDiProva(['advertiser_name' => 'qui']);
    Sponsorship::factory()->create([
        'city_id' => $altra->getKey(),
        'event_id' => occurrenceAtLocal($altra, $this->categoria, '2026-09-20 21:00:00')->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
        'status' => SponsorshipStatus::Active,
        'starts_at' => CarbonImmutable::now()->subDay(),
        'ends_at' => CarbonImmutable::now()->addDay(),
        'advertiser_name' => 'altrove',
    ]);

    $trovate = app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero);

    expect($trovate->pluck('advertiser_name')->all())->toBe(['qui']);
});

it('rispetta il tetto della collocazione anche con molte campagne', function (): void {
    /* Vendere non deve poter cambiare l'aspetto del prodotto: se domani si
       firmano sei contratti per l'apertura, ne compare comunque uno. */
    foreach (range(1, 6) as $numero) {
        campagnaDiProva(['advertiser_name' => 'numero '.$numero]);
    }

    expect(app(SponsorshipSelector::class)->forPlacement($this->citta, SponsorshipPlacement::HomeHero))
        ->toHaveCount(SponsorshipPlacement::HomeHero->limit());
});

it('tiene separate le preferenze di due utenti diversi', function (): void {
    /*
     * La cache dell'affinita' e' per utente: una chiave condivisa mostrerebbe
     * a uno le preferenze dell'altro, che e' il difetto peggiore che possa
     * avere una personalizzazione.
     */
    $concerti = Category::factory()->create(['name' => 'Concerti', 'slug' => 'concerti-prova']);
    $mostre = Category::factory()->create(['name' => 'Mostre', 'slug' => 'mostre-prova']);

    $primo = User::factory()->create();
    $primo->savedOccurrences()->attach(occurrenceAtLocal($this->citta, $concerti, '2026-10-05 21:00:00')->getKey());

    $secondo = User::factory()->create();
    $secondo->savedOccurrences()->attach(occurrenceAtLocal($this->citta, $mostre, '2026-10-06 21:00:00')->getKey());

    $servizio = app(CategoriePreferite::class);

    expect($servizio->dellUtente($primo))->toBe([$concerti->getKey()])
        ->and($servizio->dellUtente($secondo))->toBe([$mostre->getKey()]);
});

it('non da preferenze a un utente che non ha salvato niente', function (): void {
    expect(app(CategoriePreferite::class)->dellUtente(User::factory()->create()))->toBe([]);
});

/* ─────────────────  Le misure viste dal browser  ───────────────── */

it('non conta una campagna cestinata', function (): void {
    /* Il legame di rotta risolve per chiave: senza il filtro sul cestino si
       potrebbero gonfiare i numeri di una campagna che nessuno vede piu'. */
    $campagna = campagnaDiProva();
    $campagna->delete();

    $this->postJson(route('sponsorships.metric', ['sponsorship' => $campagna->getKey(), 'metric' => 'impressions']))
        ->assertNotFound();
});

it('il tetto orario di una campagna non blocca le altre', function (): void {
    /*
     * Il limite serve a impedire che una persona con un ciclo `for` gonfi le
     * cifre — non a spegnere le misure di tutto il sito quando qualcuno ci
     * prova su una campagna sola.
     */
    $bersaglio = campagnaDiProva();
    $altra = campagnaDiProva();

    foreach (range(1, 31) as $ignored) {
        $this->postJson(route('sponsorships.metric', ['sponsorship' => $bersaglio, 'metric' => 'impressions']));
    }

    $this->postJson(route('sponsorships.metric', ['sponsorship' => $altra, 'metric' => 'impressions']))
        ->assertNoContent();

    expect($altra->refresh()->impressions)->toBe(1);
});

/* ─────────────────  Numeri che diventano fatture  ───────────────── */

it('non calcola un rapporto su zero visualizzazioni', function (): void {
    /*
     * Non e' «zero per cento», e' una domanda senza risposta: scritto come 0%
     * farebbe concludere che la campagna vada male quando non e' ancora
     * partita.
     */
    expect(campagnaDiProva()->clickRate())->toBeNull();
});

it('calcola il rapporto come frazione, non come percentuale', function (): void {
    /*
     * Cinque aperture su duecento viste sono `0.025`, non `2.5`. La
     * distinzione conta perche' chi legge questo metodo per la prima volta si
     * aspetta una percentuale — l'ho fatto scrivendo questo stesso test — e
     * moltiplicherebbe una seconda volta: la tabella del pannello fa gia' il
     * `* 100`, e il risultato sarebbe un rapporto di apertura del 250%.
     */
    $campagna = campagnaDiProva();
    $campagna->forceFill(['impressions' => 200, 'clicks' => 5])->save();

    expect($campagna->refresh()->clickRate())->toBe(0.025);
});

/* ─────────────────  Il riepilogo al committente  ───────────────── */

it('non spedisce niente in prova a vuoto', function (): void {
    Notification::fake();

    campagnaDiProva([
        'advertiser_email' => 'committente@prova.test',
        'starts_at' => CarbonImmutable::now()->subDays(20),
    ]);

    $this->artisan('sponsorships:report', ['--dry-run' => true])->assertExitCode(0);

    Notification::assertNothingSent();
});

it('non scrive a una campagna sospesa', function (): void {
    /* Sospesa vuol dire che qualcosa non va — spesso un pagamento: un
       riepilogo entusiasta in quel momento e' la mail sbagliata. */
    Notification::fake();

    campagnaDiProva([
        'status' => SponsorshipStatus::Paused,
        'advertiser_email' => 'sospesa@prova.test',
        'starts_at' => CarbonImmutable::now()->subDays(20),
    ]);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('dice sempre come sono contate le misure', function (): void {
    /*
     * Si contano dal browser, quindi chi blocca gli script non viene contato:
     * sono una stima al ribasso. Dirlo accanto ai numeri costa una riga ed
     * evita la sola discussione che non si puo' vincere — quella su un numero
     * che si e' lasciato credere esatto.
     */
    $campagna = campagnaDiProva(['advertiser_name' => 'Libreria del Corso']);

    $messaggio = (new SponsorshipWeeklyReport(
        $campagna,
        CarbonImmutable::now()->subWeek(),
        CarbonImmutable::now(),
        120,
        4,
    ))->toMail((object) []);

    $testo = implode(' ', array_merge($messaggio->introLines, $messaggio->outroLines));

    expect($testo)->toContain('stima al ribasso');
});

/* ─────────────────  Chi può fare cosa  ───────────────── */

it('non lascia creare campagne a chi non e amministratore', function (): void {
    /*
     * Il posto in cima si compra, non si prende: se il referente di un locale
     * potesse crearsi una campagna, l'etichetta «sponsorizzato» smetterebbe di
     * voler dire qualcosa.
     */
    (new RolesAndPermissionsSeeder)->run();

    $referente = User::factory()->create();
    $referente->syncRoles([UserRole::VenueOwner->value]);

    $moderatore = User::factory()->create();
    $moderatore->syncRoles([UserRole::Moderator->value]);

    $amministratore = User::factory()->create();
    $amministratore->syncRoles([UserRole::Admin->value]);

    expect($referente->can('create', Sponsorship::class))->toBeFalse()
        ->and($moderatore->can('create', Sponsorship::class))->toBeFalse()
        ->and($amministratore->can('create', Sponsorship::class))->toBeTrue();
});

it('non lascia modificare una campagna a chi non e amministratore', function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $campagna = campagnaDiProva();

    $referente = User::factory()->create();
    $referente->syncRoles([UserRole::VenueOwner->value]);

    expect($referente->can('update', $campagna))->toBeFalse()
        ->and($referente->can('delete', $campagna))->toBeFalse();
});

it('conserva chi ha creato la campagna, anche se quell utente sparisce', function (): void {
    /*
     * `nullOnDelete`: la campagna resta e il riferimento diventa vuoto. Se
     * cadesse anche la campagna, cancellare un amministratore porterebbe via
     * lo storico di cio' che e' stato venduto.
     */
    $autore = User::factory()->create();
    $campagna = campagnaDiProva(['created_by' => $autore->getKey()]);

    $autore->forceDelete();

    expect(Sponsorship::query()->whereKey($campagna->getKey())->exists())->toBeTrue()
        ->and(Sponsorship::query()->whereKey($campagna->getKey())->value('created_by'))->toBeNull();
});
