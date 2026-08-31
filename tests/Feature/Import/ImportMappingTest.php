<?php

declare(strict_types=1);

use App\DTOs\ImportMapping;
use App\Models\ImportSource;
use App\Support\Import\SourceRef;

function mappingFor(mixed $mapping): ImportMapping
{
    return ImportMapping::fromSource(ImportSource::factory()->make(['mapping' => $mapping]));
}

it('usa le parole di esclusione predefinite quando la sorgente tace', function (): void {
    $mapping = mappingFor(null);

    expect($mapping->excludeKeywords)->toBe(config()->array('import.exclude_keywords'))
        ->and($mapping->excludes('Chiuso per ferie'))->toBeTrue()
        ->and($mapping->excludes('Riunione staff'))->toBeTrue()
        ->and($mapping->excludes('Manutenzione impianto'))->toBeTrue()
        ->and($mapping->excludes('Evento privato'))->toBeTrue()
        ->and($mapping->excludes('Chiusura estiva'))->toBeTrue()
        ->and($mapping->excludes("Concerto del quartetto d'archi"))->toBeFalse();
});

it('confronta parole intere e non sottostringhe', function (): void {
    $mapping = mappingFor(['exclude_keywords' => ['arte', 'privato']]);

    expect($mapping->excludes('Mostra d\'arte'))->toBeTrue()
        // Una sottostringa ucciderebbe questi due titoli, che sono eventi veri.
        ->and($mapping->excludes('Cartellone della stagione'))->toBeFalse()
        ->and($mapping->excludes('Visita alle collezioni private'))->toBeFalse();
});

it('ignora maiuscole, accenti e punteggiatura', function (): void {
    $mapping = mappingFor(['exclude_keywords' => ['chiuso', 'società']]);

    expect($mapping->excludes('CHIUSO!'))->toBeTrue()
        ->and($mapping->excludes('Assemblea della Societa'))->toBeTrue()
        ->and($mapping->excludes('Assemblea della Società'))->toBeTrue();
});

it('riconosce anche le esclusioni di piu parole', function (): void {
    $mapping = mappingFor(['exclude_keywords' => ['prova tecnica']]);

    expect($mapping->excludes('Prova tecnica delle luci'))->toBeTrue()
        ->and($mapping->excludes('Prova generale'))->toBeFalse()
        ->and($mapping->excludes('Tecnica di prova'))->toBeFalse();
});

it('non esclude nulla con un elenco vuoto', function (): void {
    $mapping = mappingFor(['exclude_keywords' => []]);

    expect($mapping->excludes('Chiuso per ferie'))->toBeFalse();
});

it('accetta un fuso dichiarato e scarta quelli inesistenti', function (): void {
    expect(mappingFor(['timezone' => 'Europe/London'])->timezone)->toBe('Europe/London')
        ->and(mappingFor(['timezone' => 'Marte/Olympus'])->timezone)->toBeNull()
        ->and(mappingFor(['timezone' => ''])->timezone)->toBeNull();
});

it('accorcia con un impronta stabile le chiavi troppo lunghe per la colonna', function (): void {
    $source = ImportSource::factory()->create();
    $lungo = str_repeat('a', 400).'@example';

    $first = SourceRef::make($source, $lungo);
    $second = SourceRef::make($source, $lungo);

    expect(strlen($first))->toBeLessThanOrEqual(SourceRef::MAX_LENGTH)
        // Stabile fra un'esecuzione e l'altra: e' la stabilita, non la
        // leggibilita, che l'idempotenza richiede.
        ->and($second)->toBe($first)
        ->and($first)->toStartWith(SourceRef::prefix($source))
        ->and(SourceRef::make($source, $lungo.'x'))->not->toBe($first);
});

it('lega la chiave alla sorgente che l ha scritta', function (): void {
    $first = ImportSource::factory()->create();
    $second = ImportSource::factory()->create();

    expect(SourceRef::make($first, 'uid@example'))->not->toBe(SourceRef::make($second, 'uid@example'))
        ->and(SourceRef::likePattern($first))->toBe($first->getKey().':%');
});
