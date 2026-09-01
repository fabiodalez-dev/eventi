<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le operazioni della checklist di esecuzione (D42, punto 3, passo 6).
 *
 * Non un passo monolitico: ognuna è un POST proprio e idempotente, così un
 * database lento non porta in timeout l'intera installazione — e riprovare
 * un'operazione già riuscita non rompe niente.
 *
 * L'ordine di dichiarazione è l'ordine di esecuzione, e non è arbitrario:
 * senza `.env` non c'è connessione, senza migrazioni non ci sono tabelle da
 * verificare, senza seed non ci sono ruoli da assegnare all'amministratore.
 */
enum InstallerTask: string
{
    case Env = 'env';
    case Migrate = 'migrazioni';
    case VerifyTables = 'verifica-tabelle';
    case Seed = 'dati-di-base';
    case Content = 'citta-e-amministratore';
    case StorageLink = 'collegamento-storage';
    case Cache = 'cache';

    public function label(): string
    {
        return __('installer.tasks.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('installer.tasks.'.$this->value.'.description');
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}
