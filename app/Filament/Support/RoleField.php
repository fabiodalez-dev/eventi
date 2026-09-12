<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\SecurityLog;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Il campo con cui si assegnano i ruoli di piattaforma, **senza poter salire
 * più in alto di dove si è**.
 *
 * ## Il difetto che chiude
 *
 * `UserPolicy::outranks()` difende i super amministratori **esistenti**: un
 * amministratore non può modificare la scheda di chi è già super
 * amministratore. Non difendeva però il gradino: `update()` gli dice sì su
 * qualunque scheda che non sia di un super amministratore — **compresa la
 * propria** — e il menu dei ruoli offriva tutti i valori dell'enum,
 * `super_admin` incluso, scrivendoli con `->relationship('roles', 'name')`.
 *
 * Bastava quindi aprire la propria scheda in `/admin/users/{me}/edit`,
 * aggiungere `super_admin` e salvare. La barriera che tutte le Policy
 * proteggono si scavalcava dall'interno, in due clic, e senza lasciare traccia
 * più significativa di una modifica di profilo.
 *
 * ## Due controlli, non uno
 *
 * Il menu mostra solo i ruoli conferibili — è ciò che evita di offrire un
 * gesto che poi viene rifiutato — ma il controllo che conta è il secondo, al
 * salvataggio: una richiesta Livewire si forgia, e un `<option>` assente dal
 * documento non è una difesa. `saveRelationshipsUsing()` è il punto in cui
 * l'insieme che arriva viene intersecato con quello permesso.
 *
 * I ruoli che chi modifica **non** può conferire, ma che il bersaglio ha già,
 * vengono conservati: la correzione toglie la possibilità di salire, non
 * quella di amministrare.
 */
final class RoleField
{
    public static function make(): Select
    {
        return Select::make('roles')
            ->label(__('users.platform_roles'))
            ->relationship('roles', 'name')
            ->multiple()
            ->preload()
            ->options(fn (): array => Role::query()
                ->whereIn('name', self::conferibili())
                ->pluck('name', 'id')
                ->map(static fn (string $nome): string => self::etichetta($nome))
                ->all())
            ->getOptionLabelFromRecordUsing(
                fn (Role $record): string => self::etichetta($record->name),
            )
            ->saveRelationshipsUsing(function (?User $record, mixed $state): void {
                if ($record === null) {
                    return;
                }

                $conferibili = Role::query()->whereIn('name', self::conferibili())->pluck('id')->all();

                /* Ciò che il bersaglio ha già e chi modifica non potrebbe
                   dargli: non è nostro da togliere. */
                $intoccabili = $record->roles()->whereNotIn('roles.id', $conferibili)->pluck('roles.id')->all();

                $richiesti = array_map('intval', array_filter((array) $state, static fn (mixed $v): bool => is_numeric($v)));

                $prima = $record->roles()->pluck('roles.name')->sort()->values()->all();

                $record->roles()->sync([
                    ...array_values(array_intersect($richiesti, $conferibili)),
                    ...$intoccabili,
                ]);

                $dopo = $record->roles()->pluck('roles.name')->sort()->values()->all();

                /* «Chi ha dato l'amministrazione a chi» è la prima domanda di
                   qualunque indagine su un pannello: si scrive solo quando
                   qualcosa è cambiato davvero, per non riempire il registro a
                   ogni salvataggio del profilo. */
                if ($prima !== $dopo) {
                    SecurityLog::scrivi('ruoli_cambiati', $record, [
                        'prima' => $prima,
                        'dopo' => $dopo,
                    ], Auth::user() instanceof User ? Auth::user() : null);
                }
            });
    }

    /**
     * I ruoli che chi sta modificando può conferire.
     *
     * Solo un super amministratore può creare un altro super amministratore.
     * Tutti gli altri arrivano fino ad `admin`: è il gradino che questo campo
     * esiste per non far saltare.
     *
     * @return list<string>
     */
    private static function conferibili(): array
    {
        $attore = Auth::user();

        if ($attore instanceof User && $attore->hasRole(UserRole::SuperAdmin->value)) {
            return array_map(static fn (UserRole $ruolo): string => $ruolo->value, UserRole::cases());
        }

        $senzaSuper = [];

        foreach (UserRole::cases() as $ruolo) {
            if ($ruolo !== UserRole::SuperAdmin) {
                $senzaSuper[] = $ruolo->value;
            }
        }

        return $senzaSuper;
    }

    /**
     * L'etichetta è quella dell'enum, che la sa già: duplicarla qui avrebbe
     * prodotto due elenchi da tenere allineati a mano.
     */
    private static function etichetta(string $nome): string
    {
        return UserRole::tryFrom($nome)?->label() ?? $nome;
    }
}
