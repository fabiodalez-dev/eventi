<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Pages\PageResource;
use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use App\Models\User;
use App\Support\DateFormatter;
use Database\Seeders\PageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Le pagine informative di §11.1 e §16.
 *
 * Il punto non è che la rotta risponda: è che i testi seminati siano **veri e
 * completi**. Un'informativa privacy che non nomina le coordinate GPS, o che
 * non dice come si cancella l'account, è una pagina che risponde 200 e non
 * serve a niente.
 */
beforeEach(function (): void {
    testCity();
});

describe('rotta pubblica', function (): void {
    it('mostra una pagina pubblicata', function (): void {
        $page = Page::factory()->create([
            'title' => 'Informativa privacy',
            'slug' => 'privacy',
            'body' => "## Chi tratta i dati\n\nIl titolare del trattamento è il progetto.",
        ]);

        $response = $this->get('/pagine/privacy');

        $response->assertOk()
            ->assertSee('Informativa privacy')
            ->assertSee('Chi tratta i dati')
            ->assertSee('Il titolare del trattamento è il progetto.');

        expect($page->slug)->toBe('privacy');
    });

    it('risponde 404 su una pagina non pubblicata', function (): void {
        Page::factory()->draft()->create(['slug' => 'bozza-privacy']);

        $this->get('/pagine/bozza-privacy')->assertNotFound();
    });

    it('risponde 404 su uno slug che non esiste', function (): void {
        $this->get('/pagine/inventata')->assertNotFound();
    });

    it('dichiara il proprio indirizzo canonico', function (): void {
        Page::factory()->create(['slug' => 'termini']);

        $this->get('/pagine/termini')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('pages.show', ['slug' => 'termini']).'">', false);
    });

    it('converte il markdown e scarta qualunque marcatura grezza', function (): void {
        Page::factory()->create([
            'slug' => 'prova',
            'body' => "## Titolo\n\nTesto <script>alert(1)</script> e <iframe src=\"https://esempio.test\"></iframe>.",
        ]);

        $html = $this->get('/pagine/prova')->assertOk()->getContent();

        expect($html)
            ->toContain('<h2>Titolo</h2>')
            ->not->toContain('<script>alert(1)</script>')
            ->not->toContain('<iframe');
    });

    it('mostra la data di ultimo aggiornamento', function (): void {
        $page = Page::factory()->create(['slug' => 'privacy']);

        $this->get('/pagine/privacy')
            ->assertOk()
            ->assertSee(__('pages.updated_at', [
                'date' => app(DateFormatter::class)->instantDate($page->updated_at),
            ]));
    });
});

describe('collegamenti nel piè di pagina', function (): void {
    it('elenca le pagine pubblicate e non quelle in bozza', function (): void {
        Page::factory()->create(['title' => 'Informativa privacy', 'slug' => 'privacy', 'sort_order' => 10]);
        Page::factory()->draft()->create(['title' => 'Bozza riservata', 'slug' => 'bozza', 'sort_order' => 20]);

        $html = $this->get('/')->assertOk()->getContent();

        expect($html)
            ->toContain('href="'.route('pages.show', ['slug' => 'privacy']).'"')
            ->toContain('Informativa privacy')
            ->not->toContain('Bozza riservata');
    });
});

describe('i testi seminati', function (): void {
    beforeEach(function (): void {
        (new PageSeeder)->run();
    });

    it('semina le cinque pagine richieste, pubblicate e non vuote', function (string $slug): void {
        $page = Page::query()->where('slug', $slug)->first();

        expect($page)->not->toBeNull()
            ->and($page->is_published)->toBeTrue()
            ->and(mb_strlen($page->body))->toBeGreaterThan(800)
            ->and($page->excerpt)->not->toBeEmpty();

        $this->get('/pagine/'.$slug)->assertOk();
    })->with(['privacy', 'cookie', 'termini', 'chi-siamo', 'contatti']);

    it('è idempotente e non calpesta le correzioni della redazione', function (): void {
        Page::query()->where('slug', 'privacy')->update(['title' => 'Titolo corretto a mano']);

        (new PageSeeder)->run();

        expect(Page::query()->where('slug', 'privacy')->count())->toBe(1)
            ->and(Page::query()->where('slug', 'privacy')->value('title'))->toBe('Titolo corretto a mano');
    });

    /*
     * §16 in una riga: «Nessuna coordinata GPS dell'utente viene salvata».
     * Se un giorno quella promessa smettesse di essere vera, il testo va
     * riscritto — e questo test è ciò che lo ricorda.
     */
    it('promette esplicitamente che le coordinate non vengono salvate', function (): void {
        $body = (string) Page::query()->where('slug', 'privacy')->value('body');

        expect($body)->toContain('non vengono mai salvate');
    });

    it('dice quali dati raccoglie l\'account, uno per uno', function (string $atteso): void {
        $body = (string) Page::query()->where('slug', 'privacy')->value('body');

        expect($body)->toContain($atteso);
    })->with([
        'email' => 'Indirizzo email',
        'nome facoltativo' => 'facoltativo',
        'fuso orario' => 'Fuso orario',
        'lingua' => 'lingua',
    ]);

    it('spiega come cancellare l\'account', function (): void {
        $body = (string) Page::query()->where('slug', 'privacy')->value('body');

        expect($body)->toContain('Come cancellare l\'account')
            ->and($body)->toContain('Cancella l\'account');
    });

    it('elenca i diritti e nomina l\'autorità di controllo', function (): void {
        $body = (string) Page::query()->where('slug', 'privacy')->value('body');

        expect($body)->toContain('Garante per la protezione dei dati personali')
            ->and($body)->toContain('accedere')
            ->and($body)->toContain('correggerli')
            ->and($body)->toContain('cancellarli')
            ->and($body)->toContain('formato leggibile');
    });

    it('dichiara per quanto conserva i dati', function (): void {
        $body = (string) Page::query()->where('slug', 'privacy')->value('body');

        expect($body)->toContain('Per quanto li conserviamo');
    });

    /*
     * §16: «in fase di iscrizione il locale dichiara di avere diritto di
     * utilizzo dei contenuti caricati». La dichiarazione deve stare nei
     * Termini, non in una nota a piè di modulo.
     */
    it('mette nei termini la dichiarazione sulle locandine e la procedura di rimozione', function (): void {
        $body = (string) Page::query()->where('slug', 'termini')->value('body');

        expect($body)->toContain('diritto di usarla')
            ->and($body)->toContain('Rimuoviamo il contenuto contestato');
    });

    /* D9: la monetizzazione non c'è ancora ma i Termini devono prevederla. */
    it('prevede nei termini la possibilità futura di visibilità a pagamento', function (): void {
        $body = (string) Page::query()->where('slug', 'termini')->value('body');

        expect($body)->toContain('a pagamento')
            ->and($body)->toContain('senza scopo di lucro');
    });

    it('nella cookie policy dice cosa il sito fa davvero, annunci compresi', function (): void {
        $body = (string) Page::query()->where('slug', 'cookie')->value('body');

        expect($body)
            ->toContain('consenso')
            ->toContain('localStorage')
            /*
             * Qui c'era `->toContain('non usa cookie di profilazione')`, e
             * pretendeva una frase che nel frattempo era diventata falsa: da
             * quando gli annunci scelgono in base agli eventi salvati, il sito
             * profila — poco, e solo al proprio interno, ma profila.
             *
             * Il test non se n'è accorto perché chiedeva la presenza di una
             * promessa, non la sua verità. È il modo in cui un'informativa
             * invecchia con la benedizione della suite: ora pretende che la
             * cosa sia **detta**, che è l'unica verifica che regge al passare
             * del tempo.
             */
            ->toContain('profilazione')
            ->not->toContain('non ha pubblicità');
    });
});

describe('pannello di redazione', function (): void {
    it('apre le pagine informative a chi ha il permesso e le nega agli altri', function (): void {
        (new RolesAndPermissionsSeeder)->run();

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);

        $moderator = User::factory()->create();
        $moderator->assignRole(UserRole::Moderator->value);

        $page = Page::factory()->create();

        $this->actingAs($admin)->get('/admin/pages')->assertOk();

        /*
         * L'indirizzo della scheda porta lo **slug**, non l'identificativo:
         * `Page::getRouteKeyName()` vale anche dentro il pannello, come per
         * categorie e tag.
         */
        $this->actingAs($admin)->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk();

        $this->actingAs($moderator)->get('/admin/pages')->assertForbidden();
    });
});

describe('la redazione scrive senza un rilascio', function (): void {
    beforeEach(function (): void {
        (new RolesAndPermissionsSeeder)->run();

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);
        $this->actingAs($admin);
    });

    it('crea una pagina dal pannello e la pubblica sul sito', function (): void {
        Livewire::test(CreatePage::class)
            ->fillForm([
                'title' => 'Dichiarazione di accessibilità',
                'excerpt' => 'A che punto siamo con l’accessibilità di questo sito.',
                'body' => "## Stato\n\nIl sito è navigabile da tastiera.",
                'is_published' => true,
                'sort_order' => 60,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = Page::query()->where('title', 'Dichiarazione di accessibilità')->firstOrFail();

        expect($page->slug)->toBe('dichiarazione-di-accessibilita');

        $this->get('/pagine/'.$page->slug)
            ->assertOk()
            ->assertSee('Il sito è navigabile da tastiera.');
    });

    it('corregge un testo già pubblicato senza cambiarne l\'indirizzo', function (): void {
        (new PageSeeder)->run();

        $page = Page::query()->where('slug', 'privacy')->firstOrFail();

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'title' => 'Informativa sulla privacy',
                'body' => "## Correzione\n\nUna riga aggiunta oggi.",
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        /*
         * Il titolo cambia, l'indirizzo no: uno slug che insegue il titolo
         * spezzerebbe i collegamenti già spediti (§16).
         */
        expect($page->refresh()->slug)->toBe('privacy')
            ->and($page->title)->toBe('Informativa sulla privacy');

        $this->get('/pagine/privacy')
            ->assertOk()
            ->assertSee('Una riga aggiunta oggi.');
    });

    it('nasconde dal sito una pagina spenta dal pannello', function (): void {
        $page = Page::factory()->create(['slug' => 'contatti']);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['is_published' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/pagine/contatti')->assertNotFound();
    });
});
