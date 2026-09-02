<?php

declare(strict_types=1);

use App\Support\Turnstile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/*
 * La verifica del gettone Turnstile.
 *
 * **Che il gettone sia valido non basta.** La site key e' pubblica per
 * costruzione — sta nell'HTML di ogni pagina — quindi chiunque puo' copiare
 * quel markup su un dominio proprio, far risolvere il widget da persone vere o
 * da un servizio che lo fa a pagamento, e spedire i gettoni a questo endpoint.
 * Sono gettoni autentici: `success` risponde `true`.
 *
 * I due controlli in piu' — da quale host e' stato risolto, e per quale
 * modulo — sono quelli che la documentazione di Cloudflare chiama obbligatori,
 * e che il progetto non faceva.
 */

beforeEach(function (): void {
    config()->set('services.turnstile.site_key', 'chiave-di-prova');
    config()->set('services.turnstile.secret_key', 'segreto-di-prova');
    config()->set('services.turnstile.hostnames', ['eventi.esempio.test']);
    config()->set('services.turnstile.timeout', 5);
});

function rispostaCloudflare(array $campi): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(array_merge([
            'success' => true,
            'hostname' => 'eventi.esempio.test',
            'challenge_ts' => now()->toIso8601String(),
        ], $campi)),
    ]);
}

function validaGettone(string $gettone, ?string $azione = null): bool
{
    return Validator::make(
        [Turnstile::FIELD => $gettone],
        Turnstile::rules($azione),
    )->fails();
}

it('accetta un gettone valido risolto dal nostro dominio', function (): void {
    rispostaCloudflare([]);

    expect(validaGettone('un-gettone'))->toBeFalse();
});

it('rifiuta un gettone che Cloudflare non riconosce', function (): void {
    rispostaCloudflare(['success' => false, 'error-codes' => ['invalid-input-response']]);

    expect(validaGettone('un-gettone'))->toBeTrue();
});

it('rifiuta un gettone autentico risolto su un altro dominio', function (): void {
    /*
     * Il caso che il solo `success` non copre: il gettone e' vero, Cloudflare
     * dice di si', ma e' stato risolto su un sito che ha copiato il nostro
     * markup con la nostra site key pubblica.
     */
    rispostaCloudflare(['hostname' => 'copione.example.com']);

    expect(validaGettone('un-gettone'))->toBeTrue();
});

it('non giudica il dominio quando non sa quale aspettarsi', function (): void {
    /* Elenco vuoto significa «non lo so»: rifiutare tutto sarebbe peggio del
       rischio, in un'installazione senza `APP_URL` sensato. */
    config()->set('services.turnstile.hostnames', []);
    config()->set('app.url', '');

    rispostaCloudflare(['hostname' => 'qualunque.example.com']);

    expect(validaGettone('un-gettone'))->toBeFalse();
});

it('ricava il dominio atteso da APP_URL, cosi segue il trasloco', function (): void {
    /*
     * Questo dominio e' provvisorio (§20.1). Scriverlo a mano significherebbe
     * che il giorno del cambio i moduli rifiutano tutti gli invii, con un
     * messaggio che parla di verifica fallita e non dice una parola sul
     * dominio.
     */
    config()->set('services.turnstile.hostnames', []);
    config()->set('app.url', 'https://nuovo-nome.example/');

    expect(Turnstile::hostnames())->toBe(['nuovo-nome.example']);
});

it('rifiuta un gettone risolto per un altro modulo', function (): void {
    /* Senza questo, un gettone ottenuto sul modulo meno sorvegliato vale per
       tutti gli altri: se ne risolve uno dove costa meno e lo si spende dove
       serve. */
    rispostaCloudflare(['action' => 'segnalazione']);

    expect(validaGettone('un-gettone', 'registrazione-utente'))->toBeTrue();
});

it('accetta il gettone del modulo giusto', function (): void {
    rispostaCloudflare(['action' => 'registrazione-utente']);

    expect(validaGettone('un-gettone', 'registrazione-utente'))->toBeFalse();
});

it('non pretende un azione se il modulo non l ha dichiarata', function (): void {
    /* `data-action` e' facoltativo: un modulo che non lo mette non deve
       smettere di funzionare. */
    rispostaCloudflare(['action' => 'qualcosa']);

    expect(validaGettone('un-gettone'))->toBeFalse();
});

it('lascia passare se Cloudflare non risponde', function (): void {
    /*
     * Un guasto loro non deve diventare un guasto nostro: restano in piedi il
     * campo esca e il limite di frequenza, che sono nostri. Chiudere il modulo
     * perche' un terzo e' irraggiungibile trasformerebbe un disservizio altrui
     * nel nostro.
     */
    Http::fake(['challenges.cloudflare.com/*' => Http::response('', 503)]);

    expect(validaGettone('un-gettone'))->toBeFalse();
});

it('ogni modulo pubblico dichiara la propria azione', function (): void {
    /* Se due moduli condividessero l'azione, il controllo non distinguerebbe
       piu' fra loro e tornerebbe a valere quello che valeva prima. */
    $azioni = [];

    foreach ([
        'app/Http/Requests/Web/StoreEventSubmissionRequest.php',
        'app/Http/Requests/Web/StoreVenueApplicationRequest.php',
        'app/Http/Requests/Web/StoreReportRequest.php',
        'app/Http/Requests/Web/Account/RegisterRequest.php',
    ] as $file) {
        preg_match("/Turnstile::rules\('([^']+)'\)/", (string) file_get_contents(base_path($file)), $trovato);

        expect($trovato[1] ?? null)->not->toBeNull($file.' non dichiara un azione');

        $azioni[] = $trovato[1];
    }

    expect($azioni)->toHaveCount(count(array_unique($azioni)), 'due moduli con la stessa azione non si distinguono');
});
