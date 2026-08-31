<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportSourceType;
use App\Exceptions\ImportException;
use App\Models\ImportSource;
use Illuminate\Contracts\Container\Container;

/**
 * L'unico punto che sa quale classe serve un tipo di sorgente.
 *
 * §14.2 vuole che «le sorgenti future si aggiungano senza toccare il core»:
 * il core è `ImportRunner`, che non nomina mai un driver, e questa mappa è la
 * riga sola da modificare per aggiungerne uno.
 *
 * `manual` non ha e non avrà un driver: descrive una sorgente che una persona
 * ricopia a mano, ed è dichiarata in tabella per essere censita, non eseguita.
 */
final class ImportDriverFactory
{
    /**
     * @var array<string, class-string<ImportSourceDriver>>
     */
    private const DRIVERS = [
        ImportSourceType::Ics->value => IcsImportDriver::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(ImportSource $source): ImportSourceDriver
    {
        $driver = self::DRIVERS[$source->type->value] ?? null;

        if ($driver === null) {
            throw ImportException::noDriver($source->type->label());
        }

        return $this->container->make($driver);
    }

    public function supports(ImportSource $source): bool
    {
        return isset(self::DRIVERS[$source->type->value]);
    }

    /**
     * I tipi che sanno eseguirsi. Serve a non accodare ogni ora una sorgente
     * `manual`, che fallirebbe sempre e riempirebbe la dashboard di §14.5 di
     * guasti che non lo sono.
     *
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::DRIVERS);
    }
}
