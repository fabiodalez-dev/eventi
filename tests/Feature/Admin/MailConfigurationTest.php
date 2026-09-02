<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Pages\MailConfiguration;
use App\Mail\MailConfigurationTest as MessaggioDiProva;
use App\Models\User;
use App\Settings\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * La configurazione del server di posta dal pannello.
 *
 * In produzione la posta esce dal `sendmail` del server: funziona, ma un
 * messaggio spedito da un hosting condiviso senza SPF ne' DKIM del mittente
 * finisce nella posta indesiderata con una regolarita' che si nota. Cambiare
 * fornitore significava mettere mano al `.env` via SSH.
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->super = User::factory()->create();
    $this->super->syncRoles([UserRole::SuperAdmin->value]);
});

it('la vede solo il super amministratore', function (): void {
    /*
     * Chi puo' scrivere qui puo' far uscire posta a nome del sito, e puo'
     * dirottare su un server proprio i messaggi che il sito spedisce — dentro
     * ci sono collegamenti di accesso e di reimpostazione password. Non e' una
     * preferenza fra le altre.
     */
    $this->actingAs($this->super);
    expect(MailConfiguration::canAccess())->toBeTrue();

    $amministratore = User::factory()->create();
    $amministratore->syncRoles([UserRole::Admin->value]);

    $this->actingAs($amministratore);
    expect(MailConfiguration::canAccess())->toBeFalse();

    $moderatore = User::factory()->create();
    $moderatore->syncRoles([UserRole::Moderator->value]);

    $this->actingAs($moderatore);
    expect(MailConfiguration::canAccess())->toBeFalse();
});

it('nasce spenta e non tocca la posta di nessuno', function (): void {
    /*
     * Finche' nessuno la compila, la posta esce come e' sempre uscita: una
     * migrazione che accendesse qualcosa cambierebbe il comportamento di un
     * sistema che funziona, senza che nessuno l'abbia chiesto.
     */
    $impostazioni = app(MailSettings::class);

    expect($impostazioni->enabled)->toBeFalse()
        ->and($impostazioni->active())->toBeFalse()
        ->and($impostazioni->host)->toBeNull();
});

it('non si accende senza una prova riuscita', function (): void {
    /*
     * Il cuore della pagina. Senza questo vincolo un refuso nella password
     * spegnerebbe in silenzio TUTTE le notifiche del sito: i lavori in coda
     * fallirebbero uno a uno e non se ne accorgerebbe nessuno finche' qualcuno
     * non si lamenta di non aver ricevuto un promemoria.
     */
    $this->actingAs($this->super);

    Livewire::test(MailConfiguration::class)
        ->fillForm([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'tizio',
            'password' => 'segreta',
            /* Si prova ad accendere senza aver mai provato niente. */
            'enabled' => true,
        ])
        ->call('salva');

    $impostazioni = app(MailSettings::class)->refresh();

    expect($impostazioni->host)->toBe('smtp.example.com')
        /* I dati si conservano... */
        ->and($impostazioni->username)->toBe('tizio')
        /* ...ma in vigore non ci va. */
        ->and($impostazioni->enabled)->toBeFalse()
        ->and($impostazioni->active())->toBeFalse();
});

it('si accende dopo una prova riuscita, e la prova arriva a chi la chiede', function (): void {
    Mail::fake();

    $this->actingAs($this->super);

    Livewire::test(MailConfiguration::class)
        ->fillForm([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'tizio',
            'password' => 'segreta',
        ])
        ->call('inviaProva')
        ->fillForm(['enabled' => true])
        ->call('salva');

    Mail::assertSent(MessaggioDiProva::class, fn (MessaggioDiProva $m): bool => $m->hasTo($this->super->email));

    $impostazioni = app(MailSettings::class)->refresh();

    expect($impostazioni->verified())->toBeTrue()
        ->and($impostazioni->enabled)->toBeTrue()
        ->and($impostazioni->active())->toBeTrue();
});

it('cambiare le credenziali annulla la verifica', function (): void {
    /*
     * Una prova riuscita ieri su un altro host non dice niente su quello di
     * oggi. L'impronta copre host, porta, cifratura, utenza e password.
     */
    Mail::fake();
    $this->actingAs($this->super);

    Livewire::test(MailConfiguration::class)
        ->fillForm(['host' => 'smtp.buono.test', 'port' => 587, 'username' => 'tizio', 'password' => 'segreta'])
        ->call('inviaProva')
        ->fillForm(['enabled' => true])
        ->call('salva');

    expect(app(MailSettings::class)->refresh()->active())->toBeTrue();

    /* Ora si cambia la password e si risalva chiedendo di restare accesa. */
    Livewire::test(MailConfiguration::class)
        ->fillForm(['host' => 'smtp.buono.test', 'port' => 587, 'username' => 'tizio', 'password' => 'un-altra', 'enabled' => true])
        ->call('salva');

    $impostazioni = app(MailSettings::class)->refresh();

    expect($impostazioni->verified())->toBeFalse('la prova valeva per la password di prima')
        ->and($impostazioni->enabled)->toBeFalse()
        ->and($impostazioni->active())->toBeFalse();
});

it('cambiare solo il mittente non annulla la verifica', function (): void {
    /*
     * Il mittente non entra nell'impronta di proposito: non puo' rompere la
     * connessione al server, e obbligare a rifare la prova per aver corretto
     * un nome visualizzato sarebbe fastidio senza contropartita.
     */
    Mail::fake();
    $this->actingAs($this->super);

    Livewire::test(MailConfiguration::class)
        ->fillForm(['host' => 'smtp.buono.test', 'port' => 587, 'username' => 'tizio', 'password' => 'segreta'])
        ->call('inviaProva')
        ->fillForm(['enabled' => true, 'from_name' => 'inCittà'])
        ->call('salva');

    $impostazioni = app(MailSettings::class)->refresh();

    expect($impostazioni->from_name)->toBe('inCittà')
        ->and($impostazioni->active())->toBeTrue();
});

it('conserva la password cifrata, non in chiaro', function (): void {
    /*
     * Le credenziali di un server di posta valgono quanto quelle di un
     * account: chi le ottiene puo' spedire a nome del sito. Cifrarle non
     * protegge da chi ha la APP_KEY, ma protegge da un dump finito nel posto
     * sbagliato o da una query di diagnostica copiata in una chat.
     */
    $impostazioni = app(MailSettings::class);
    $impostazioni->password = 'una-password-riconoscibile';
    $impostazioni->save();

    $grezzo = DB::table(config('settings.repositories.database.table'))
        ->where('group', 'mail')
        ->where('name', 'password')
        ->value('payload');

    expect((string) $grezzo)->not->toContain('una-password-riconoscibile')
        /* E rileggendola dal contenitore torna in chiaro. */
        ->and(app(MailSettings::class)->refresh()->password)->toBe('una-password-riconoscibile');
});
