<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\City;
use Laravel\Pennant\Feature;

/**
 * Gli interruttori di `laravel/pennant`, dichiarati e letti da un posto solo.
 *
 * Lo stack tecnologico (§12 della tabella dei pacchetti) sceglie Pennant per
 * «accendere funzioni per singola città»: `city-import` è esattamente quello,
 * e ha per ambito la città. `newsletter` invece riguarda tutto il sistema,
 * quindi ha un ambito costante — senza, Pennant userebbe l'utente collegato
 * come ambito predefinito e scriverebbe una riga per ciascuno, per un valore
 * che è lo stesso per tutti.
 *
 * **La verità di un interruttore è la riga in `features`, non questo file.**
 * Le funzioni qui dichiarate dicono che cosa vale per un ambito su cui nessuno
 * ha ancora deciso nulla; da quel momento in poi decide chi ha deciso, con
 * `feature:set`. È la ragione per cui la città accesa non si legge da
 * `cities.settings`: un valore risolto da Pennant resta memorizzato, e
 * continuerebbe a rispondere il vecchio anche dopo la modifica.
 */
final class Features
{
    /**
     * L'import dei calendari di una città (§14.2). Spento, l'esecuzione oraria
     * salta le sorgenti di quella città e non ne accoda i lavori: è così che
     * una città si apre al pubblico prima che i suoi calendari siano stati
     * verificati, e che si spegne un import impazzito senza toccare le
     * sorgenti una per una.
     */
    public const CITY_IMPORT = 'city-import';

    /**
     * La newsletter del weekend (§15.9). Spenta, il consenso non viene più
     * chiesto in nessun modulo e la programmazione non mette in coda nulla —
     * ma i consensi già dati **restano**, con la loro data, perché sono un
     * atto della persona e non una funzione del sistema.
     */
    public const NEWSLETTER = 'newsletter';

    /**
     * L'ambito degli interruttori che non riguardano una città in particolare.
     */
    private const GLOBAL_SCOPE = 'sistema';

    /**
     * Chiamato dal provider: dichiara il valore predefinito di ogni
     * interruttore per un ambito mai deciso.
     */
    public static function define(): void
    {
        Feature::define(
            self::CITY_IMPORT,
            static fn (mixed $scope): bool => $scope instanceof City
                ? config()->boolean('pennant.defaults.city-import')
                : false,
        );

        Feature::define(
            self::NEWSLETTER,
            static fn (): bool => config()->boolean('pennant.defaults.newsletter'),
        );
    }

    public static function importActiveFor(City $city): bool
    {
        return Feature::for($city)->active(self::CITY_IMPORT);
    }

    public static function newsletterActive(): bool
    {
        return Feature::for(self::GLOBAL_SCOPE)->active(self::NEWSLETTER);
    }

    /**
     * L'ambito con cui `feature:set` accende e spegne ciò che non è di una
     * città. Sta qui perché il nome dell'ambito è un dato salvato in
     * `features.scope`: scriverlo in due punti significa, prima o poi,
     * spegnere un interruttore e leggerne un altro.
     */
    public static function globalScope(): string
    {
        return self::GLOBAL_SCOPE;
    }

    /**
     * I nomi degli interruttori esistenti, per la validazione di `feature:set`.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [self::CITY_IMPORT, self::NEWSLETTER];
    }
}
