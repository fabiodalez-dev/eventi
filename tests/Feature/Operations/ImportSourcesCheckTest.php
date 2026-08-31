<?php

declare(strict_types=1);

use App\Enums\ImportRunStatus;
use App\Models\ImportSource;
use App\Support\Health\ImportSourcesCheck;
use Spatie\Health\Enums\Status;

/**
 * Il controllo proprio di §16: le sorgenti di import (§14.2) stanno ancora
 * leggendo qualcosa? Un calendario che smette di rispondere non fa cadere
 * niente e non riempie nessun log — il sintomo arriva settimane dopo, come
 * «non ci sono più eventi nuovi».
 */
function sorgente(ImportRunStatus|string|null $status, bool $active = true): ImportSource
{
    return ImportSource::factory()->create([
        'city_id' => testCity()->getKey(),
        'is_active' => $active,
        'last_status' => $status instanceof ImportRunStatus ? $status->value : $status,
    ]);
}

it('sta bene quando non c\'è alcuna sorgente attiva', function (): void {
    $result = ImportSourcesCheck::new()->run();

    expect($result->status->value)->toBe(Status::ok()->value)
        ->and($result->getNotificationMessage())->toBe(__('health.import_sources.none'));
});

it('sta bene quando tutte le sorgenti hanno letto', function (): void {
    sorgente(ImportRunStatus::Success);
    sorgente(ImportRunStatus::Partial);

    expect(ImportSourcesCheck::new()->run()->status->value)->toBe(Status::ok()->value);
});

it('avvisa quando una sorgente su due è caduta', function (): void {
    sorgente(ImportRunStatus::Success);
    sorgente(ImportRunStatus::Failed);

    $result = ImportSourcesCheck::new()->run();

    expect($result->status->value)->toBe(Status::warning()->value)
        ->and($result->meta)->toBe(['active' => 2, 'failed' => 1]);
});

it('è rosso quando sono tutte in errore', function (): void {
    sorgente(ImportRunStatus::Failed);
    sorgente(ImportRunStatus::Failed);

    $result = ImportSourcesCheck::new()->run();

    expect($result->status->value)->toBe(Status::failed()->value)
        ->and($result->getNotificationMessage())->toContain('Tutte le 2 sorgenti');
});

/**
 * Una sorgente spenta non conta: è stata spenta apposta, e includerla nel
 * conteggio farebbe suonare l'allarme per una decisione della redazione.
 */
it('non guarda le sorgenti disattivate', function (): void {
    sorgente(ImportRunStatus::Success);
    sorgente(ImportRunStatus::Failed, active: false);

    $result = ImportSourcesCheck::new()->run();

    expect($result->status->value)->toBe(Status::ok()->value)
        ->and($result->meta)->toBe(['active' => 1, 'failed' => 0]);
});

/**
 * Una sorgente appena dichiarata non ha ancora letto niente: non è in errore,
 * è nuova.
 */
it('non considera in errore una sorgente che non ha ancora girato', function (): void {
    sorgente(null);

    expect(ImportSourcesCheck::new()->run()->status->value)->toBe(Status::ok()->value);
});
