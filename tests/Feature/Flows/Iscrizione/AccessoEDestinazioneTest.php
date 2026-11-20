<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Il primo accesso e quelli dopo (§15.2, D68): dove si arriva, da dove si
 * torna, cosa succede sbagliando, quanti tentativi si concedono e come si
 * esce. Il ritorno vale solo verso pagine di questo sito: ogni altro
 * indirizzo si scarta e si va nella propria area.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
    config(['community.enabled' => false]);
});

function iscrizioneUtente(string $email, string $password = 'password-di-prova-lunga'): User
{
    return User::factory()->create(['email' => $email, 'password' => Hash::make($password)]);
}

it('torna alla pagina che aveva chiesto anche dopo un primo tentativo sbagliato', function (): void {
    $user = iscrizioneUtente('torna.dopo.errore@example.test');

    $this->get('/le-mie-raccolte')->assertRedirect(route('login'));
    $this->get('/accedi')->assertOk();

    $this->from('/accedi')->post('/accedi', ['email' => 'torna.dopo.errore@example.test', 'password' => 'sbagliata'])
        ->assertRedirect('/accedi')
        ->assertSessionHasErrors('email');
    /* Il modulo torna con gli errori: la pagina di partenza resta ricordata. */
    $this->get('/accedi')->assertOk();

    $this->post('/accedi', ['email' => 'torna.dopo.errore@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(url('/le-mie-raccolte'))
        ->assertSessionHas('status', __('account.login.done'));
    $this->assertAuthenticatedAs($user);
    $this->get('/le-mie-raccolte')->assertOk();
});

it('scarta un ritorno che porta fuori dal sito e manda nella propria area', function (string $ritorno): void {
    $user = iscrizioneUtente('niente.fuori@example.test');

    $this->get('/accedi?intended='.urlencode($ritorno))->assertOk();
    expect(session('url.intended'))->toBeNull();

    $this->post('/accedi', ['email' => 'niente.fuori@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);

    /* Anche scritto nell'indirizzo di /accedi da collegati non porta fuori. */
    $this->get('/accedi?intended='.urlencode($ritorno))->assertRedirect(route('account.feed'));
})->with([
    'altro sito' => ['https://sito-malevolo.example/raccogli'],
    'senza schema' => ['//sito-malevolo.example/raccogli'],
    'barra rovesciata' => ['/\\sito-malevolo.example/raccogli'],
    'javascript' => ['javascript:alert(1)'],
    'sottodominio finto' => ['http://localhost.sito-malevolo.example/'],
]);

it('dimentica la pagina di una visita abbandonata quando si torna ad accedere da capo', function (): void {
    $user = iscrizioneUtente('cambia.idea@example.test');

    /* Parte dalle raccolte, poi chiude la scheda. */
    $this->get('/accedi?intended='.urlencode(url('/le-mie-raccolte')))->assertOk();
    expect(session('url.intended'))->toBe(url('/le-mie-raccolte'));

    /* Più tardi apre /accedi dal menu, senza ritorno. */
    $this->get('/accedi')->assertOk();
    expect(session('url.intended'))->toBeNull();

    $this->post('/accedi', ['email' => 'cambia.idea@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('cambia la sessione all accesso e ancora all uscita, così un identificativo rubato prima non vale', function (): void {
    iscrizioneUtente('sessione.nuova@example.test');

    $this->get('/accedi')->assertOk();
    $prima = session()->getId();
    $gettonePrima = session()->token();

    $this->post('/accedi', ['email' => 'sessione.nuova@example.test', 'password' => 'password-di-prova-lunga'])->assertRedirect();
    $dentro = session()->getId();
    expect($dentro)->not->toBe($prima);

    $this->post('/esci')->assertRedirect(route('home'));
    expect(session()->getId())->not->toBe($dentro)
        ->and(session()->token())->not->toBe($gettonePrima);
    $this->assertGuest();
});

it('tiene il cookie di lunga durata solo a chi spunta resta collegato e lo toglie all uscita', function (): void {
    iscrizioneUtente('cookie.lungo@example.test');
    $recaller = Auth::guard('web')->getRecallerName();

    $this->post('/accedi', ['email' => 'cookie.lungo@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'))
        ->assertCookieMissing($recaller);
    $this->post('/esci');

    $dentro = $this->post('/accedi', ['email' => 'cookie.lungo@example.test', 'password' => 'password-di-prova-lunga', 'remember' => '1'])
        ->assertRedirect(route('account.feed'))
        ->assertCookie($recaller);
    expect($dentro->getCookie($recaller, false)?->getExpiresTime())->toBeGreaterThan(now()->addDays(20)->getTimestamp());

    $fuori = $this->post('/esci')->assertRedirect(route('home'));
    expect($fuori->getCookie($recaller, false)?->getExpiresTime())->toBeLessThan(now()->getTimestamp());
});

it('fa entrare con l indirizzo scritto in maiuscolo e con spazi intorno', function (): void {
    $user = iscrizioneUtente('maiuscole.accesso@example.test');

    $this->post('/accedi', ['email' => '  MAIUSCOLE.Accesso@EXAMPLE.test ', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('conta i tentativi per account anche se l indirizzo cambia maiuscole e chi prova cambia rete', function (): void {
    config(['api.rate_limit.attempts_per_account' => 3]);
    $user = iscrizioneUtente('bersaglio@example.test');

    foreach (['bersaglio@example.test', 'BERSAGLIO@example.test', 'Bersaglio@Example.Test'] as $i => $variante) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i + 10)])
            ->post('/accedi', ['email' => $variante, 'password' => 'tentativo-'.$i])
            ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    }

    /* Il tetto è pieno: nemmeno la password giusta passa, e il modulo dice di aspettare. */
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
        ->post('/accedi', ['email' => 'bersaglio@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('login'))
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('form');
    $this->assertGuest();

    /* Passato il quarto d'ora il titolare entra. */
    $this->travel(16)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
        ->post('/accedi', ['email' => 'bersaglio@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('restituisce il tentativo a chi entra con la password giusta, così i suoi accessi non riempiono il tetto', function (): void {
    config(['api.rate_limit.attempts_per_account' => 2]);
    iscrizioneUtente('entra.spesso@example.test');

    foreach (range(1, 4) as $volta) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$volta])
            ->post('/accedi', ['email' => 'entra.spesso@example.test', 'password' => 'password-di-prova-lunga'])
            ->assertRedirect(route('account.feed'));
        $this->post('/esci');
    }

    /* Un errore conta, ma non chiude: il tetto è di due. */
    $this->post('/accedi', ['email' => 'entra.spesso@example.test', 'password' => 'sbagliata'])->assertSessionHasErrors('email');
    $this->post('/accedi', ['email' => 'entra.spesso@example.test', 'password' => 'password-di-prova-lunga'])->assertRedirect(route('account.feed'));
});

it('ferma chi prova troppe volte dalla stessa rete sullo stesso indirizzo, ma non chi entra con un altro', function (): void {
    iscrizioneUtente('una.coppia@example.test');
    $altro = iscrizioneUtente('altra.persona@example.test');

    foreach (range(1, config()->integer('api.rate_limit.auth')) as $volta) {
        $this->post('/accedi', ['email' => 'una.coppia@example.test', 'password' => 'sbagliata-'.$volta])->assertSessionHasErrors('email');
    }

    $this->post('/accedi', ['email' => 'una.coppia@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('form')
        ->assertSessionHasInput('email', 'una.coppia@example.test');

    /* Dalla stessa rete un'altra persona entra. */
    $this->post('/accedi', ['email' => 'altra.persona@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($altro);
});

it('non fa entrare un account cancellato né gli manda il collegamento di accesso', function (): void {
    $user = iscrizioneUtente('ex.iscritta@example.test');

    $this->post('/accedi', ['email' => 'ex.iscritta@example.test', 'password' => 'password-di-prova-lunga'])->assertRedirect(route('account.feed'));
    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('home'));
    $this->assertGuest();

    $this->post('/accedi', ['email' => 'ex.iscritta@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    $this->assertGuest();

    $this->post('/accedi/collegamento', ['email' => 'ex.iscritta@example.test'])
        ->assertRedirect(route('account.magic-link'))
        ->assertSessionHas('status', __('account.magic.sent'));
    Notification::assertNotSentTo(User::withTrashed()->findOrFail($user->id), MagicLoginLink::class);
});

it('esce verso l accesso solo se lo chiede, e verso la prima pagina per ogni altro valore', function (): void {
    iscrizioneUtente('esce.per.cambiare@example.test');

    $this->post('/accedi', ['email' => 'esce.per.cambiare@example.test', 'password' => 'password-di-prova-lunga'])->assertRedirect();
    $this->post('/esci', ['to' => 'login'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('account.login.logged_out'));
    $this->assertGuest();
    $this->get('/accedi')->assertOk()->assertSee(__('account.login.logged_out'));

    $this->post('/accedi', ['email' => 'esce.per.cambiare@example.test', 'password' => 'password-di-prova-lunga'])->assertRedirect();
    $this->post('/esci', ['to' => 'https://sito-malevolo.example/'])->assertRedirect(route('home'));
    $this->assertGuest();
});

it('porta alla pagina di partenza anche chi entra con il collegamento senza password', function (): void {
    $user = iscrizioneUtente('collegamento.ritorno@example.test');
    $partenza = url('/i-miei-salvataggi');

    $this->get('/accedi/collegamento?intended='.urlencode($partenza))->assertOk();
    $this->post('/accedi/collegamento', ['email' => 'collegamento.ritorno@example.test'])->assertRedirect(route('account.magic-link'));

    $link = (string) Notification::sent($user, MagicLoginLink::class)->last()->toMail($user)->actionUrl;

    $this->get($link)->assertRedirect($partenza);
    $this->assertAuthenticatedAs($user);
    $this->get('/i-miei-salvataggi')->assertOk();
});

it('rimanda nella propria area chi manda di nuovo il modulo di accesso quando è già dentro', function (): void {
    $user = iscrizioneUtente('gia.dentro@example.test');
    $altro = iscrizioneUtente('altro.account@example.test');
    $altro->forceFill(['last_active_at' => now()->subYear()])->save();
    $ultimaVolta = $altro->fresh()->last_active_at;

    $this->post('/accedi', ['email' => 'gia.dentro@example.test', 'password' => 'password-di-prova-lunga'])->assertRedirect();

    /* Da dentro il modulo non cambia account: per farlo si esce. */
    $this->post('/accedi', ['email' => 'altro.account@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
    expect($altro->fresh()->last_active_at?->equalTo($ultimaVolta))->toBeTrue();
});

it('apre comunque accesso, registrazione e collegamento quando il ritorno è troppo lungo o non è un testo, e lo scarta', function (string $ritorno): void {
    $user = iscrizioneUtente('ritorno.strano@example.test');

    /* Si arriva da una ricerca con molti filtri: il pulsante «Accedi» porta
       l'indirizzo intero della pagina. */
    $this->get('/registrati?'.$ritorno)->assertOk();
    $this->get('/accedi/collegamento?'.$ritorno)->assertOk();
    $this->get('/accedi?'.$ritorno)->assertOk();
    expect(session('url.intended'))->toBeNull();

    $this->post('/accedi', ['email' => 'ritorno.strano@example.test', 'password' => 'password-di-prova-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
})->with([
    'ritorno lunghissimo' => [fn (): string => http_build_query(['intended' => url('/cerca').'?q='.str_repeat('concerto ', 260)])],
    'ritorno come elenco' => [fn (): string => http_build_query(['intended' => [url('/le-mie-raccolte')]])],
]);
