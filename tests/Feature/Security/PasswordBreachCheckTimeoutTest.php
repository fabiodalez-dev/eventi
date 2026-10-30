<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

/**
 * Il controllo delle password rubate lascia passare quando il servizio non
 * risponde, ma solo allo scadere del timeout. Quello del framework è di 30
 * secondi: su hosting condiviso la registrazione finiva in un 504.
 */
it('dà al controllo delle password rubate un timeout breve', function (): void {
    $verificatore = app(UncompromisedVerifier::class);

    expect($verificatore)->toBeInstanceOf(NotPwnedVerifier::class)
        ->and((fn (): int => $this->timeout)->call($verificatore))->toBe(3);
});

it('lascia passare la password se il servizio non risponde', function (): void {
    Http::fake(static fn () => throw new ConnectionException('irraggiungibile'));

    $validatore = Validator::make(['password' => 'una-password-lunga'], ['password' => Password::min(8)->uncompromised()]);

    expect($validatore->passes())->toBeTrue();
});
