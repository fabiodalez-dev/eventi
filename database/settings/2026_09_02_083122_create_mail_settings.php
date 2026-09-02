<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * I valori di partenza della configurazione della posta.
 *
 * Nascono **spenti e vuoti**: finche' nessuno entra nel pannello e li compila,
 * la posta continua a uscire esattamente come prima, dalla configurazione di
 * `.env`. Una migrazione che accendesse qualcosa cambierebbe il comportamento
 * di un sistema che funziona, nel momento in cui viene rilasciata e senza che
 * nessuno l'abbia chiesto.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.enabled', false);
        $this->migrator->add('mail.host', null);
        $this->migrator->add('mail.port', 587);
        $this->migrator->add('mail.encryption', 'tls');
        $this->migrator->add('mail.username', null);
        $this->migrator->addEncrypted('mail.password', null);
        $this->migrator->add('mail.from_address', null);
        $this->migrator->add('mail.from_name', null);
        $this->migrator->add('mail.verified_fingerprint', null);
        $this->migrator->add('mail.verified_at', null);
    }
};
