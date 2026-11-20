<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipImpressionNonce;
use App\Models\SponsorshipSlotHourly;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;

/**
 * Le misure di una campagna: visualizzazioni e aperture.
 *
 * Finiscono in fattura, e per questo il test che conta di più non è quello che
 * verifica che il contatore salga: è quello che verifica che non lo si possa
 * gonfiare. Ogni misura porta il suo gettone usa e getta: lo stesso gettone
 * conta una volta sola, un gettone falso o scaduto non conta, chi non deve
 * contare non conta.
 */
beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-05 12:00:00');
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00', venue: $this->venue);

    $this->campagna = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => SponsorshipPlacement::ListTop,
    ]);
});

/** Un gettone nuovo per la campagna, come lo mette la pagina nella card. */
function p3Gettone(Sponsorship $campagna, string $collocazione = 'list_top'): string
{
    return ImpressionToken::issue($campagna, $collocazione);
}

/** Una misura dal browser, con il gettone nell'intestazione e un indirizzo a scelta. */
function p3Misura(Sponsorship $campagna, string $misura, ?string $gettone, string $indirizzo = '203.0.113.10', array $intestazioni = []): TestResponse
{
    $headers = $gettone === null ? $intestazioni : ['X-Impression-Token' => $gettone, ...$intestazioni];

    return test()->withServerVariables(['REMOTE_ADDR' => $indirizzo])
        ->postJson(route('sponsorships.metric', ['sponsorship' => $campagna, 'metric' => $misura]), ['page' => 'home'], $headers);
}

/** @return array{impressions: int, clicks: int, giorno_viste: int, giorno_clic: int, ora_viste: int, ora_sponsor: int, ora_clic: int, numeri: int, registro: int} */
function p3Conti(Sponsorship $campagna): array
{
    $campagna->refresh();

    return [
        'impressions' => (int) $campagna->impressions,
        'clicks' => (int) $campagna->clicks,
        'giorno_viste' => (int) SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->id)->sum('impressions'),
        'giorno_clic' => (int) SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->id)->sum('clicks'),
        'ora_viste' => (int) SponsorshipSlotHourly::query()->sum('views'),
        'ora_sponsor' => (int) SponsorshipSlotHourly::query()->sum('sponsored_views'),
        'ora_clic' => (int) SponsorshipSlotHourly::query()->sum('clicks'),
        'numeri' => SponsorshipImpressionNonce::query()->count(),
        'registro' => SponsorshipClick::query()->where('sponsorship_id', $campagna->id)->count(),
    ];
}

const P3_ZERO = ['impressions' => 0, 'clicks' => 0, 'giorno_viste' => 0, 'giorno_clic' => 0, 'ora_viste' => 0, 'ora_sponsor' => 0, 'ora_clic' => 0, 'numeri' => 0, 'registro' => 0];

it('conta una visualizzazione e un apertura con lo stesso gettone', function (): void {
    $gettone = p3Gettone($this->campagna);

    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();
    p3Misura($this->campagna, 'clicks', $gettone)->assertNoContent();

    expect(p3Conti($this->campagna))->toBe([
        'impressions' => 1, 'clicks' => 1, 'giorno_viste' => 1, 'giorno_clic' => 1,
        'ora_viste' => 1, 'ora_sponsor' => 1, 'ora_clic' => 1, 'numeri' => 1, 'registro' => 1,
    ]);
});

it('conta una volta sola lo stesso gettone mandato due volte', function (): void {
    $gettone = p3Gettone($this->campagna);

    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();
    p3Misura($this->campagna, 'impressions', $gettone, '198.51.100.7')->assertNoContent();
    p3Misura($this->campagna, 'clicks', $gettone)->assertNoContent();
    p3Misura($this->campagna, 'clicks', $gettone, '198.51.100.8')->assertNoContent();

    expect(p3Conti($this->campagna))
        ->impressions->toBe(1)->clicks->toBe(1)
        ->giorno_viste->toBe(1)->giorno_clic->toBe(1)
        ->ora_sponsor->toBe(1)->ora_clic->toBe(1)
        ->registro->toBe(1);
});

it('senza gettone risponde 204 e non conta niente', function (): void {
    p3Misura($this->campagna, 'impressions', null)->assertNoContent();
    p3Misura($this->campagna, 'clicks', null)->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO);
});

it('con un gettone scaduto risponde 204 e non scrive niente', function (): void {
    $gettone = p3Gettone($this->campagna);

    /* Il gettone vale 6 ore; la campagna resta visibile, quindi è solo il
       gettone a fermare la misura. */
    $this->travel(7)->hours();

    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();
    p3Misura($this->campagna, 'clicks', $gettone)->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO);
});

it('con una firma sbagliata risponde 204 e non scrive niente', function (): void {
    $gettone = p3Gettone($this->campagna);
    $manomesso = substr($gettone, 0, -1).(str_ends_with($gettone, 'A') ? 'B' : 'A');

    p3Misura($this->campagna, 'impressions', $manomesso)->assertNoContent();
    p3Misura($this->campagna, 'impressions', 'v1.qualunque.cosa')->assertNoContent();
    p3Misura($this->campagna, 'clicks', $manomesso)->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO);
});

it('non conta su una campagna il gettone di un altra', function (): void {
    $altra = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $this->campagna->event_id]);

    p3Misura($this->campagna, 'impressions', p3Gettone($altra))->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO)
        ->and($altra->fresh()->impressions)->toBe(0);
});

it('conta un clic arrivato senza la sua visualizzazione come visualizzazione e clic', function (): void {
    $gettone = p3Gettone($this->campagna, 'home_card');

    p3Misura($this->campagna, 'clicks', $gettone)->assertNoContent();
    // La visualizzazione che arriva dopo è già contata.
    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();

    expect(p3Conti($this->campagna))->toBe([
        'impressions' => 1, 'clicks' => 1, 'giorno_viste' => 1, 'giorno_clic' => 1,
        'ora_viste' => 1, 'ora_sponsor' => 1, 'ora_clic' => 1, 'numeri' => 1, 'registro' => 1,
    ]);

    /* Lo spazio è quello del gettone, non la collocazione della campagna:
       la stessa riga può essere vista in posti diversi. */
    expect(SponsorshipSlotHourly::query()->sole()->placement)->toBe('home_card')
        ->and(SponsorshipClick::query()->sole()->placement)->toBe('home_card')
        ->and(SponsorshipClick::query()->sole()->page)->toBe('home');
});

it('non conta un crawler, anche con un gettone valido', function (string $firma): void {
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna), intestazioni: ['User-Agent' => $firma])->assertNoContent();
    p3Misura($this->campagna, 'clicks', p3Gettone($this->campagna), intestazioni: ['User-Agent' => $firma])->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO);
})->with([
    'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'browser senza schermo' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36',
    'Lighthouse' => 'Mozilla/5.0 (Linux; Android 11; moto g power) Chrome-Lighthouse',
    'firma vuota' => '',
]);

it('non conta la redazione collegata', function (string $ruolo): void {
    (new RolesAndPermissionsSeeder)->run();
    $persona = User::factory()->create();
    $persona->assignRole($ruolo);

    $this->actingAs($persona);
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();
    p3Misura($this->campagna, 'clicks', p3Gettone($this->campagna))->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO);
})->with([UserRole::Admin->value, UserRole::SuperAdmin->value, UserRole::Moderator->value]);

it('non conta il locale committente collegato, e conta una persona qualunque collegata', function (): void {
    $abilitazione = SponsorshipGrant::factory()->create(['venue_id' => $this->venue->id]);
    $riga = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => $this->campagna->event_id]);
    $proprietario = User::factory()->create();
    $proprietario->venuesIncludingInvited()->attach($this->venue->id, ['role' => 'owner', 'accepted_at' => now()]);

    $this->actingAs($proprietario);
    p3Misura($riga, 'impressions', p3Gettone($riga))->assertNoContent();
    p3Misura($riga, 'clicks', p3Gettone($riga))->assertNoContent();
    expect(p3Conti($riga)['impressions'])->toBe(0)->and(p3Conti($riga)['clicks'])->toBe(0);

    $this->actingAs(User::factory()->create());
    p3Misura($riga, 'impressions', p3Gettone($riga))->assertNoContent();
    expect($riga->fresh()->impressions)->toBe(1);
});

it('non conta l organizzatore committente collegato, proprietario o membro', function (): void {
    $titolare = User::factory()->create();
    $membro = User::factory()->create();
    $organizzatore = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->city->id, 'owner_id' => $titolare->id, 'is_active' => true]);
    $organizzatore->users()->attach($membro->id);
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-21 21:00:00', event: ['organizer_id' => $organizzatore->id]);
    $abilitazione = SponsorshipGrant::factory()->forOrganizer($organizzatore)->create();
    $riga = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => $occorrenza->event_id]);

    foreach ([$titolare, $membro] as $persona) {
        $this->actingAs($persona);
        p3Misura($riga, 'impressions', p3Gettone($riga))->assertNoContent();
    }

    expect($riga->fresh()->impressions)->toBe(0);
});

it('non conta i membri del locale dell evento su una campagna singola della redazione', function (): void {
    $redattore = User::factory()->create();
    $redattore->venuesIncludingInvited()->attach($this->venue->id, ['role' => 'editor', 'accepted_at' => now()]);

    $this->actingAs($redattore);
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(0);
});

it('conta cento visualizzazioni con cento gettoni, e cento nell aggregato orario', function (): void {
    foreach (range(1, 100) as $i) {
        // Indirizzi diversi: il tetto di 30 all'ora per indirizzo non c'entra.
        p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna), '198.51.'.intdiv($i, 250).'.'.($i % 250 + 1))->assertNoContent();
    }

    expect(p3Conti($this->campagna))
        ->impressions->toBe(100)
        ->giorno_viste->toBe(100)
        ->ora_viste->toBe(100)
        ->ora_sponsor->toBe(100)
        ->numeri->toBe(100);

    // Una riga sola: città, collocazione, canale, giorno e ora locali.
    $riga = SponsorshipSlotHourly::query()->sole();
    expect($riga->city_id)->toBe($this->city->id)
        ->and($riga->placement)->toBe('list_top')
        ->and($riga->channel)->toBe('web')
        ->and($riga->day->toDateString())->toBe('2026-09-05')
        ->and($riga->hour)->toBe(12);
});

it('scrive l aggregato orario nell ora e nel giorno della città', function (): void {
    /* Mezzanotte e mezza a Padova è ancora il giorno prima in UTC: il giorno
       e l'ora sono quelli che vede chi abita lì. */
    freezeLocal($this->city, '2026-09-06 00:30:00');

    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();

    $riga = SponsorshipSlotHourly::query()->sole();
    expect($riga->day->toDateString())->toBe('2026-09-06')
        ->and($riga->hour)->toBe(0)
        ->and(SponsorshipDailyStat::query()->sole()->day->toDateString())->toBe('2026-09-06');
});

it('si ferma all obiettivo: non una visualizzazione di più, e la campagna sparisce', function (): void {
    $this->campagna->forceFill(['impressions_goal' => 3])->saveQuietly();

    foreach (range(1, 5) as $i) {
        p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna), '198.51.100.'.$i)->assertNoContent();
    }

    expect($this->campagna->fresh()->impressions)->toBe(3)
        ->and(Sponsorship::query()->visible()->whereKey($this->campagna->id)->exists())->toBeFalse()
        ->and((int) SponsorshipDailyStat::query()->sum('impressions'))->toBe(3)
        ->and((int) SponsorshipSlotHourly::query()->sum('sponsored_views'))->toBe(3);
});

it('si ferma all obiettivo di un abilitazione sommando le sue righe', function (): void {
    $abilitazione = SponsorshipGrant::factory()->goal(4)->create(['venue_id' => $this->venue->id]);
    $altraData = occurrenceAtLocal($this->city, $this->category, '2026-09-25 21:00:00', venue: $this->venue);
    $prima = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => $this->campagna->event_id]);
    $seconda = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => $altraData->event_id]);

    foreach (range(1, 3) as $i) {
        p3Misura($prima, 'impressions', p3Gettone($prima), '198.51.100.'.$i)->assertNoContent();
    }
    foreach (range(4, 6) as $i) {
        p3Misura($seconda, 'impressions', p3Gettone($seconda), '198.51.100.'.$i)->assertNoContent();
    }

    expect($abilitazione->fresh()->deliveredImpressions())->toBe(4)
        ->and($prima->fresh()->impressions)->toBe(3)
        ->and($seconda->fresh()->impressions)->toBe(1);
});

it('non conta su una campagna in pausa, in bozza o nel cestino', function (): void {
    $gettone = p3Gettone($this->campagna);

    $this->campagna->forceFill(['status' => SponsorshipStatus::Paused])->save();
    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();

    $this->campagna->forceFill(['status' => SponsorshipStatus::Draft])->save();
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();

    $this->campagna->forceFill(['status' => SponsorshipStatus::Active])->save();
    $this->campagna->delete();
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();

    expect(p3Conti($this->campagna))->toBe(P3_ZERO);
});

it('accetta il gettone di una campagna finita da meno di una casella, e lo rifiuta dopo', function (): void {
    /* L'apertura della home resta nella copia in cache fino alla fine della
       casella (30 minuti): chi la guarda in quel tempo la sta guardando. */
    config(['sponsorship.hero_slot_minutes' => 30]);
    $this->campagna->forceFill(['ends_at' => CarbonImmutable::now('UTC')->addMinutes(5)])->saveQuietly();
    $gettone = p3Gettone($this->campagna, 'home_hero');

    $this->travel(20)->minutes();
    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();
    expect($this->campagna->fresh()->impressions)->toBe(1);

    $this->travel(30)->minutes();
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna, 'home_hero'))->assertNoContent();
    expect($this->campagna->fresh()->impressions)->toBe(1);
});

it('smette di contare oltre il tetto orario per indirizzo', function (): void {
    foreach (range(1, 45) as $tentativo) {
        p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();
    }

    /* Il tetto è 30: le richieste continuano a rispondere «va bene» — al
       browser non serve saperlo — ma il contatore si ferma. */
    expect($this->campagna->fresh()->impressions)->toBe(30);
});

it('conta separatamente due campagne diverse', function (): void {
    $altra = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $this->campagna->event_id]);

    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(1)
        ->and($altra->fresh()->impressions)->toBe(0);
});

it('non accetta una misura inventata', function (): void {
    $this->post(route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'fatturato']))
        ->assertNotFound();
});

it('scrive al massimo sei query per una visualizzazione, sette per un clic', function (): void {
    $conta = function (callable $richiesta): int {
        /* `City::timezones()` si ricorda dentro un processo: si dimentica
           prima, così si contano le query di una richiesta vera. */
        Once::flush();
        $query = 0;
        DB::listen(function () use (&$query): void {
            $query++;
        });
        $richiesta();
        DB::getEventDispatcher()?->forget('Illuminate\Database\Events\QueryExecuted');

        return $query;
    };

    $gettone = p3Gettone($this->campagna);

    expect($conta(fn () => p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent()))->toBeLessThanOrEqual(6)
        ->and($conta(fn () => p3Misura($this->campagna, 'clicks', $gettone)->assertNoContent()))->toBeLessThanOrEqual(7)
        ->and($this->campagna->fresh()->impressions)->toBe(1)
        ->and($this->campagna->fresh()->clicks)->toBe(1);
});

it('non lascia sul dispositivo nessun identificativo della misura', function (): void {
    $gettone = p3Gettone($this->campagna);
    $numero = explode('.', $gettone)[4];

    $pagina = $this->get('/eventi')->assertOk();
    $misura = p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();

    /* La misura non mette cookie suoi: solo quelli che ogni pagina del sito
       ha già (sessione e protezione dei moduli), e in nessuno il gettone o il
       suo numero casuale. */
    $permessi = [config('session.cookie'), 'XSRF-TOKEN'];
    foreach ([$pagina, $misura] as $risposta) {
        foreach ($risposta->headers->getCookies() as $cookie) {
            expect($cookie->getName())->toBeIn($permessi)
                ->and((string) $cookie->getValue())->not->toContain($numero)
                ->and((string) $cookie->getValue())->not->toContain($gettone);
        }
    }

    expect($this->campagna->fresh()->impressions)->toBe(1);
});

it('calcola il rapporto solo quando c è qualcosa da rapportare', function (): void {
    /* Zero visualizzazioni non è «zero per cento»: è una domanda senza
       risposta, e scriverla come zero fa credere che la campagna vada male
       quando non è ancora partita. */
    expect($this->campagna->clickRate())->toBeNull();

    /*
     * `forceFill` e non `update`: i due contatori non sono fra i campi
     * assegnabili in massa, di proposito — si incrementano, non si scrivono, e
     * un contatore che si puo' impostare da un modulo e' un contatore che
     * prima o poi qualcuno imposta. `update()` li ignorava in silenzio, ed e'
     * cosi' che questo test ha scoperto la cosa.
     */
    $this->campagna->forceFill(['impressions' => 200, 'clicks' => 6])->save();

    expect($this->campagna->fresh()->clickRate())->toBe(0.03);
});

it('la pagina porta gli indirizzi per contare, e il collegamento resta un href normale', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');

    $html = $this->get('/eventi')->assertOk()->getContent();

    expect($html)
        ->toContain('data-sponsorship-impression')
        ->toContain('data-sponsorship-click')
        /* Il visitatore non dipende dallo script: senza JavaScript le misure
           si perdono, la navigazione no. */
        ->toMatch('/<a[^>]*href="[^"]*\/eventi\/[^"]*"[^>]*rel="sponsored"/');
});

/* ─────────────────  Il segnale di occasione  ───────────────── */

/** Un gettone dell'occasione, come lo mette il segno dello spazio vuoto. */
function p3Occasione(int $citta, string $collocazione): string
{
    return ImpressionToken::issueOpportunity($citta, $collocazione);
}

it('conta un occasione non venduta solo come traffico dello spazio', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->postJson(route('sponsorships.opportunity'), ['token' => p3Occasione($this->city->id, 'home_card')])
        ->assertNoContent();

    $riga = SponsorshipSlotHourly::query()->sole();
    expect($riga->views)->toBe(1)
        ->and($riga->sponsored_views)->toBe(0)
        ->and($riga->clicks)->toBe(0)
        ->and($riga->placement)->toBe('home_card')
        ->and($riga->hour)->toBe(12)
        ->and(SponsorshipDailyStat::query()->count())->toBe(0)
        ->and($this->campagna->fresh()->impressions)->toBe(0);
});

it('non conta un occasione senza gettone, con un gettone falso, scaduto o già usato', function (): void {
    $url = route('sponsorships.opportunity');
    $tokens = app(ImpressionToken::class);

    /* Prima bastavano città e collocazione: un ciclo gonfiava la previsione
       che decide lo spazio delle campagne a visualizzazioni. */
    $this->postJson($url, ['city' => $this->city->id, 'placement' => 'home_card'])->assertNoContent();
    $this->postJson($url, ['token' => 'o1.'.$this->city->id.'.home_card.1.0000000000000000.'.str_repeat('A', 43)])->assertNoContent();
    $this->postJson($url, ['token' => $tokens->signOpportunity($this->city->id, 'home_card', CarbonImmutable::now()->subHours(7))])->assertNoContent();
    /* Un gettone di una visualizzazione non vale come occasione. */
    $this->postJson($url, ['token' => p3Gettone($this->campagna)])->assertNoContent();

    expect(SponsorshipSlotHourly::query()->count())->toBe(0);

    $gettone = p3Occasione($this->city->id, 'home_card');
    foreach (range(1, 3) as $volta) {
        $this->postJson($url, ['token' => $gettone])->assertNoContent();
    }

    expect((int) SponsorshipSlotHourly::query()->sum('views'))->toBe(1);
});

it('scarta in silenzio un occasione malformata, di un programma o della redazione', function (): void {
    $url = route('sponsorships.opportunity');

    $this->postJson($url, ['token' => p3Occasione($this->city->id, 'banner')])->assertNoContent();
    $this->postJson($url, ['token' => p3Occasione(999999, 'home_card')])->assertNoContent();
    $this->postJson($url, ['placement' => 'home_card'])->assertNoContent();
    $this->postJson($url, ['token' => p3Occasione($this->city->id, 'home_card')], ['User-Agent' => 'Googlebot/2.1'])->assertNoContent();

    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $this->actingAs($admin)->postJson($url, ['token' => p3Occasione($this->city->id, 'home_card')])->assertNoContent();

    expect(SponsorshipSlotHourly::query()->count())->toBe(0);
});

it('limita le occasioni per indirizzo, città e collocazione a dodici all ora', function (): void {
    foreach (range(1, 20) as $ignored) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->postJson(route('sponsorships.opportunity'), ['token' => p3Occasione($this->city->id, 'list_top')])
            ->assertNoContent();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
        ->postJson(route('sponsorships.opportunity'), ['token' => p3Occasione($this->city->id, 'map_sheet')])
        ->assertNoContent();

    expect((int) SponsorshipSlotHourly::query()->where('placement', 'list_top')->sum('views'))->toBe(12)
        ->and((int) SponsorshipSlotHourly::query()->where('placement', 'map_sheet')->sum('views'))->toBe(1);
});

/* ─────────────────  Obiettivo raggiunto, banner, strada vecchia  ───────────────── */

it('conta il clic su una card ancora in pagina quando l unità ha appena raggiunto l obiettivo', function (): void {
    $this->campagna->forceFill(['impressions_goal' => 10, 'impressions' => 9])->saveQuietly();
    $prima = p3Gettone($this->campagna);
    $ultima = p3Gettone($this->campagna);

    p3Misura($this->campagna, 'impressions', $ultima)->assertNoContent();
    expect($this->campagna->fresh()->impressions)->toBe(10);

    /* La card con il primo gettone è ancora a schermo: il clic conta, la sua
       visualizzazione no (l'obiettivo è anche un massimo). */
    p3Misura($this->campagna, 'clicks', $prima)->assertNoContent();
    expect(p3Conti($this->campagna))->toMatchArray(['impressions' => 10, 'clicks' => 1]);

    /* Una nuova visualizzazione non conta, e nemmeno un clic con un gettone
       emesso oltre una casella fa. */
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna))->assertNoContent();
    $vecchio = app(ImpressionToken::class)->sign($this->campagna->id, 'list_top', CarbonImmutable::now()->subHours(2));
    p3Misura($this->campagna, 'clicks', $vecchio)->assertNoContent();

    expect(p3Conti($this->campagna))->toMatchArray(['impressions' => 10, 'clicks' => 1]);
});

it('non conta il clic dopo l obiettivo di un campagna in pausa', function (): void {
    $this->campagna->forceFill(['impressions_goal' => 10, 'impressions' => 10, 'status' => SponsorshipStatus::Paused])->saveQuietly();

    p3Misura($this->campagna, 'clicks', p3Gettone($this->campagna))->assertNoContent();

    expect($this->campagna->fresh()->clicks)->toBe(0);
});

it('non conta il banner del sito guardato dal committente collegato, per la rotta con la sessione', function (): void {
    $titolare = User::factory()->create();
    $titolare->venuesIncludingInvited()->attach($this->venue->id, ['role' => 'owner', 'accepted_at' => now()]);

    /* Il banner del sito ora manda le misure qui, alla rotta con la sessione
       (`sponsorship-banner.js`): prima andavano all'API, che non vede la
       sessione web, e il committente collegato contava. */
    $this->actingAs($titolare);
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna, 'banner'))->assertNoContent();
    expect($this->campagna->fresh()->impressions)->toBe(0);

    auth()->guard('web')->logout();
    p3Misura($this->campagna, 'impressions', p3Gettone($this->campagna, 'banner'))->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(1)
        ->and(SponsorshipSlotHourly::query()->sole()->placement)->toBe('banner');
});

it('non supera l obiettivo nemmeno con il gettone giornaliero delle app già installate', function (): void {
    $this->campagna->forceFill(['impressions_goal' => 10, 'impressions' => 10])->saveQuietly();
    $giornaliero = hash_hmac('sha256', $this->campagna->id.'|list_top|'.now('UTC')->format('Y-m-d'), (string) config('app.key'));

    /* `visible()` qui lo esclude già; senza, la misura arrivata insieme
       all'ultima lo superava: provo il controllo sotto il lucchetto. */
    $recorder = app(RecordSponsorshipMetric::class);
    $recorder->record($this->campagna, 'impressions', request()->duplicate(), 'android');

    expect($this->campagna->fresh()->impressions)->toBe(10);

    $this->campagna->forceFill(['impressions' => 9])->saveQuietly();
    $this->withHeaders(['X-Metric-Token' => $giornaliero, 'X-Installation-ID' => 'installazione-di-prova-123'])
        ->postJson('/api/v1/reports/sponsorships/'.$this->campagna->id.'/metrics/impressions?city='.$this->city->slug)
        ->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(10);
});

it('chiude la strada del gettone giornaliero dopo la data in configurazione', function (): void {
    $giornaliero = hash_hmac('sha256', $this->campagna->id.'|list_top|'.now('UTC')->format('Y-m-d'), (string) config('app.key'));
    config()->set('sponsorship.legacy_metric_token_until', '2026-09-04');

    $this->withHeaders(['X-Metric-Token' => $giornaliero, 'X-Installation-ID' => 'installazione-di-prova-123'])
        ->postJson('/api/v1/reports/sponsorships/'.$this->campagna->id.'/metrics/impressions?city='.$this->city->slug)
        ->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(0);

    config()->set('sponsorship.legacy_metric_token_until', '2026-09-05');
    $this->withHeaders(['X-Metric-Token' => $giornaliero, 'X-Installation-ID' => 'installazione-di-prova-456'])
        ->postJson('/api/v1/reports/sponsorships/'.$this->campagna->id.'/metrics/impressions?city='.$this->city->slug)
        ->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(1);
});

/* ─────────────────  La potatura  ───────────────── */

it('toglie i numeri casuali solo dopo che il loro gettone è scaduto, e gli aggregati orari dopo 70 giorni', function (): void {
    $adesso = CarbonImmutable::now('UTC');
    $riga = fn (string $nonce, CarbonImmutable $quando): array => [
        'nonce' => $nonce, 'sponsorship_id' => $this->campagna->id, 'placement' => 'list_top', 'seen_at' => $quando->toDateTimeString(), 'clicked_at' => null,
    ];
    DB::table('sponsorship_impression_nonces')->insert([
        $riga('aaaaaaaaaaaaaaaa', $adesso->subHours(7)),
        $riga('bbbbbbbbbbbbbbbb', $adesso->subMinutes(6 * 60 + 2)),
        $riga('cccccccccccccccc', $adesso->subHours(5)),
    ]);
    $ora = fn (string $giorno): array => ['city_id' => $this->city->id, 'placement' => 'list_top', 'channel' => 'web', 'day' => $giorno, 'hour' => 10, 'views' => 5, 'sponsored_views' => 1, 'clicks' => 0];
    DB::table('sponsorship_slot_hourly')->insert([
        $ora($adesso->subDays(80)->toDateString()),
        $ora($adesso->subDays(60)->toDateString()),
    ]);

    $this->artisan('sponsorships:prune', ['--dry-run' => true])->assertSuccessful();
    expect(SponsorshipImpressionNonce::query()->count())->toBe(3);

    $this->artisan('sponsorships:prune')->assertSuccessful();

    /* A 6 ore e 2 minuti il gettone potrebbe valere ancora (vale fino al
       minuto 360 dall'emissione): il numero resta, o si conterebbe due volte. */
    expect(SponsorshipImpressionNonce::query()->orderBy('nonce')->pluck('nonce')->all())->toBe(['bbbbbbbbbbbbbbbb', 'cccccccccccccccc'])
        ->and(SponsorshipSlotHourly::query()->count())->toBe(1)
        ->and(SponsorshipSlotHourly::query()->sole()->day->toDateString())->toBe($adesso->subDays(60)->toDateString());
});

it('dopo la potatura un gettone scaduto resta rifiutato', function (): void {
    $gettone = p3Gettone($this->campagna);
    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();

    $this->travel(7)->hours();
    $this->artisan('sponsorships:prune')->assertSuccessful();
    expect(SponsorshipImpressionNonce::query()->count())->toBe(0);

    p3Misura($this->campagna, 'impressions', $gettone)->assertNoContent();
    expect($this->campagna->fresh()->impressions)->toBe(1);
});
