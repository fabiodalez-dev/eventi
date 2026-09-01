<?php

declare(strict_types=1);

use App\DTOs\Installer\Requirement;
use App\Enums\InstallerStep;
use App\Enums\RequirementStatus;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\RequirementsChecker;
use Tests\Support\InstallerSandbox;

/*
 * Bloccanti contro avvisi, dal lato di chi installa (D42, punto 4).
 *
 * La classificazione è già verificata altrove; qui conta la conseguenza: un
 * bloccante deve **fermare**, un avviso deve **lasciar passare**. Sono i due
 * modi opposti di sbagliare — un installer che blocca su un avviso non fa
 * installare nessuno, uno che avvisa su un errore fatale fa installare tutti
 * male — e nessuno dei due si vede leggendo l'elenco dei requisiti.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
    InstallerSandbox::pretendEmptyDatabase($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

/**
 * Un controllore dei requisiti con un solo bloccante dichiarato.
 *
 * Simulare il guasto vero — spegnere `intl`, togliere i permessi a `storage/`
 * sulla macchina che sta eseguendo i test — non è possibile né desiderabile:
 * ciò che si verifica qui è cosa fa il wizard *dato* un bloccante, non come lo
 * riconosce.
 */
function checkerWithBlocking(): RequirementsChecker
{
    return new class(base_path(), app(EnvWriter::class)) extends RequirementsChecker
    {
        public function mandatory(): array
        {
            return [new Requirement(
                'writable_env',
                RequirementStatus::Blocking,
                ['path' => '.env'],
                'chmod 0664 .env',
            )];
        }
    };
}

it('non lascia superare il passo quando manca qualcosa di indispensabile', function (): void {
    $this->app->instance(RequirementsChecker::class, checkerWithBlocking());

    $this->post('/installazione/requisiti')
        ->assertRedirect(InstallerStep::Requirements->url())
        ->assertSessionHas('installer_error', __('installer.requirements.blocked'));

    /* Il passo non risulta completato: il successivo resta chiuso. */
    $this->get('/installazione/database')->assertRedirect(InstallerStep::Requirements->url());
});

it('mostra il comando da dare per rimediare a un bloccante', function (): void {
    /*
     * Un requisito che dice solo «manca» costringe chi installa a cercare
     * altrove proprio nel momento in cui è fermo — e chi installa su una
     * shared hosting spesso non sa quale comando cercare.
     */
    $this->app->instance(RequirementsChecker::class, checkerWithBlocking());

    $this->get('/installazione/requisiti')
        ->assertOk()
        ->assertSee(__('installer.requirements.items.writable_env.hint'))
        ->assertSee('chmod 0664 .env')
        ->assertSee(__('installer.actions.recheck'));
});

it('lascia proseguire con un avviso acceso', function (): void {
    /*
     * L'avviso è vero, non finto: le locandine possono arrivare a un gigabyte
     * secondo la configurazione dei media, il limite di PHP di questa macchina
     * è più basso, e il caricamento dei manifesti grandi fallirà. Il sito però
     * risponde — che è la sola domanda che decide fra bloccante e avviso.
     */
    config(['media.max_upload_bytes' => 1024 * 1024 * 1024]);

    $checker = app(RequirementsChecker::class);
    $warnings = array_filter(
        $checker->advisory(),
        static fn (Requirement $requirement): bool => $requirement->status === RequirementStatus::Warning,
    );

    expect($warnings)->not->toBeEmpty()
        ->and($checker->passes($checker->all()))->toBeTrue();

    $this->post('/installazione/requisiti')->assertRedirect(InstallerStep::Database->url());
    $this->get('/installazione/database')->assertOk();
});

it('nomina la funzione che l’avviso fa perdere, invece di dire soltanto «manca»', function (): void {
    /*
     * Un avviso senza il nome di ciò che smette di funzionare è rumore che si
     * impara a ignorare — e l'avviso successivo, quello importante, verrà
     * ignorato con lo stesso gesto.
     */
    config(['media.max_upload_bytes' => 1024 * 1024 * 1024]);

    $this->get('/installazione/requisiti')
        ->assertOk()
        ->assertSee(__('installer.requirements.advisory_intro'))
        ->assertSee('upload_max_filesize', false);
});

it('dichiara bloccante una cartella che non riesce a creare', function (): void {
    /*
     * La riparazione automatica prova `0775`, mai `0777`. Quando nemmeno
     * quella basta — qui il genitore è in sola lettura, come una `storage/`
     * sotto un utente diverso da quello del server web — la voce deve restare
     * bloccante: proseguire produrrebbe un sito che non riesce a scrivere né
     * una sessione né un log.
     */
    $parent = $this->sandbox->directory.'/radice-in-sola-lettura';
    mkdir($parent, 0500);

    $checker = new RequirementsChecker($parent, app(EnvWriter::class));

    $directories = array_values(array_filter(
        $checker->mandatory(),
        static fn (Requirement $requirement): bool => $requirement->key === 'writable_directory',
    ));

    expect($directories)->toHaveCount(2)
        ->and($directories[0]->status)->toBe(RequirementStatus::Blocking)
        ->and($directories[0]->command)->toBe('chmod -R 0775 storage')
        ->and($checker->passes($checker->mandatory()))->toBeFalse();

    chmod($parent, 0755);
    rmdir($parent);
});

it('legge «nessun limite» dove PHP scrive -1, invece di leggere zero', function (): void {
    /*
     * `upload_max_filesize = -1` significa nessun limite: è il valore più
     * permissivo che esista. Letto come numero varrebbe meno di dodici
     * megabyte e produrrebbe un avviso su un server che non ha alcun problema
     * — cioè un avviso falso, che è il modo più efficace per far ignorare
     * quelli veri. Le due direttive non sono modificabili a caldo, quindi la
     * conversione si interroga direttamente.
     */
    $toBytes = new ReflectionMethod(RequirementsChecker::class, 'toBytes');
    $checker = app(RequirementsChecker::class);

    expect($toBytes->invoke($checker, '-1'))->toBe(PHP_INT_MAX)
        ->and($toBytes->invoke($checker, '12M'))->toBe(12 * 1024 * 1024)
        ->and($toBytes->invoke($checker, '1G'))->toBe(1024 * 1024 * 1024)
        ->and($toBytes->invoke($checker, '512K'))->toBe(512 * 1024)
        ->and($toBytes->invoke($checker, '0'))->toBe(0);
});

it('non mette la stessa voce fra gli indispensabili e fra gli avvisi', function (): void {
    /*
     * `imagick` compare due volte con due significati diversi — bloccante come
     * «uno dei due motori immagini», avviso come «questo in particolare» — ed
     * è proprio per questo che le due voci hanno chiavi distinte. Una chiave
     * condivisa mostrerebbe la stessa riga due volte con due esiti opposti.
     */
    $keys = static fn (array $requirements): array => array_map(
        static fn (Requirement $requirement): string => $requirement->key,
        $requirements,
    );

    $checker = app(RequirementsChecker::class);

    expect(array_intersect($keys($checker->mandatory()), $keys($checker->advisory())))->toBe([]);
});
