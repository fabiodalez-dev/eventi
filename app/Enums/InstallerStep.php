<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I sette passi del wizard di installazione (D42, punto 3).
 *
 * Il valore è anche il segmento dell'indirizzo: `/installazione/database`.
 * L'ordine è quello di dichiarazione ed è ciò su cui si regge l'anti-salto —
 * chi chiede il passo N senza aver completato N-1 torna al primo incompleto.
 */
enum InstallerStep: string
{
    case Requirements = 'requisiti';
    case Database = 'database';
    case Application = 'applicazione';
    case City = 'citta';
    case Admin = 'amministratore';
    case Run = 'esecuzione';
    case Done = 'fine';

    public function label(): string
    {
        return __('installer.steps.'.$this->value.'.title');
    }

    /** L'indirizzo del passo. Le rotte si chiamano `installer.<valore>`. */
    public function url(): string
    {
        return route('installer.'.$this->value);
    }

    /** La posizione nella sequenza, a partire da 1: è ciò che si mostra in testa alla pagina. */
    public function number(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** Il passo che viene dopo, o `null` per l'ultimo. */
    public function next(): ?self
    {
        return self::cases()[$this->number()] ?? null;
    }

    /**
     * I passi che devono essere già completati per poter aprire questo.
     *
     * @return array<int, self>
     */
    public function previous(): array
    {
        return array_slice(self::cases(), 0, $this->number() - 1);
    }

    /**
     * I passi che raccolgono dati da un modulo. `Requirements` e `Done` non ne
     * hanno, `Run` non è un modulo ma una checklist.
     */
    public function collectsData(): bool
    {
        return in_array($this, [self::Database, self::Application, self::City, self::Admin], true);
    }
}
