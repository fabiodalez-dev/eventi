<?php

declare(strict_types=1);

/*
 * Il file che dimostra ad Android che i collegamenti del sito sono dell'app
 * (D66). È statico e non passa da Laravel: la verifica di Android ha tempi
 * stretti, e il file non contiene il dominio, che resta provvisorio.
 */

function dichiarazioniAssetLinks(): array
{
    return json_decode((string) file_get_contents(public_path('.well-known/assetlinks.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('dichiara il pacchetto ufficiale dell app per tutti i collegamenti', function (): void {
    $dichiarazioni = dichiarazioniAssetLinks();

    expect($dichiarazioni)->toHaveCount(1)
        ->and($dichiarazioni[0]['relation'])->toBe(['delegate_permission/common.handle_all_urls'])
        ->and($dichiarazioni[0]['target']['namespace'])->toBe('android_app')
        ->and($dichiarazioni[0]['target']['package_name'])->toBe(config('account.android_package'));
});

it('elenca solo impronte SHA-256 ben formate e senza doppioni', function (): void {
    $impronte = dichiarazioniAssetLinks()[0]['target']['sha256_cert_fingerprints'];

    expect($impronte)->not->toBeEmpty()->and(array_unique($impronte))->toHaveCount(count($impronte));
    foreach ($impronte as $impronta) {
        expect($impronta)->toMatch('/^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$/');
    }
});

it('non scrive il dominio nel file', function (): void {
    expect((string) file_get_contents(public_path('.well-known/assetlinks.json')))->not->toContain('fabiodalez.it');
});
