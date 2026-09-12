<?php

declare(strict_types=1);

use App\Exceptions\ImportException;
use App\Services\Import\HostResolver;
use App\Services\Import\ImportUrlGuard;

/**
 * DNS rebinding: il controllo e la connessione devono parlare della stessa
 * macchina.
 *
 * `ImportUrlGuard::assert()` risolveva il nome, verificava gli indirizzi e
 * restituiva una **stringa**; poi curl risolveva di nuovo. Fra le due
 * risoluzioni chi controlla il proprio DNS cambia risposta — la prima volta un
 * indirizzo pubblico, la seconda `127.0.0.1` — e il controllo diventava una
 * formalità. `resolvedAddress()` restituisce l'indirizzo già verificato, che
 * `IcsImportDriver` impone a curl con `CURLOPT_RESOLVE`.
 */
it('restituisce l’indirizzo verificato da imporre alla connessione', function (): void {
    $this->app->bind(HostResolver::class, fn (): HostResolver => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });

    expect(app(ImportUrlGuard::class)->resolvedAddress('https://calendario.example.test/ics'))
        ->toBe('93.184.216.34');
});

it('non pinna niente quando l’host è già un indirizzo', function (): void {
    /* Lì non c'è nessuna risoluzione da anticipare: l'indirizzo scritto
       nell'URL l'ha già verificato `problem()`. */
    expect(app(ImportUrlGuard::class)->resolvedAddress('https://93.184.216.34/ics'))->toBeNull();
});

it('non pinna un indirizzo privato nemmeno se il risolutore lo offre', function (): void {
    $this->app->bind(HostResolver::class, fn (): HostResolver => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['127.0.0.1', '10.0.0.5'];
        }
    });

    $guard = app(ImportUrlGuard::class);

    /* Il nome è comunque rifiutato in blocco — è il controllo principale — e
       qui non resta niente da pinnare. */
    expect($guard->reject('https://interno.example.test/ics'))->not->toBeNull();
    expect(fn () => $guard->resolvedAddress('https://interno.example.test/ics'))->toThrow(ImportException::class);
});

it('non pinna niente quando il nome non si risolve', function (): void {
    $this->app->bind(HostResolver::class, fn (): HostResolver => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return [];
        }
    });

    /* Un nome che non si risolve resta ammesso di proposito: la connessione
       fallirà e il guasto si racconta come irraggiungibilità. */
    expect(fn () => app(ImportUrlGuard::class)->resolvedAddress('https://inesistente.example.test/ics'))->toThrow(ImportException::class);
});
