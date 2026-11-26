<?php

declare(strict_types=1);

namespace App\Support\Editorial;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\User;
use App\Support\CurrentCity;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Il banco della redazione: chi lavora nel pannello `/admin` (o nell'area
 * «Redazione» dell'app), con quale ruolo e su quale città.
 *
 * Fino all'app della redazione la domanda «sono nella redazione?» la
 * facevano sei punti chiedendo a Filament il pannello corrente
 * (`Filament::getCurrentPanel()?->getId() === 'admin'`): fuori dal pannello
 * la risposta era sempre no, e l'API non poteva usarli. Adesso si chiede qui,
 * e la risposta arriva da una delle due strade, come per `ManagementSubject`:
 *
 * - nell'API la lega alla richiesta `ResolveEditorialDesk`, dopo aver
 *   controllato `User::isEditorialStaff()` (la stessa barriera di
 *   `canAccessPanel`);
 * - nel pannello `admin` la si ricava dall'utente collegato e dalla città
 *   corrente, come prima.
 *
 * Il banco sta negli attributi della richiesta e non nel container, per lo
 * stesso motivo del soggetto di gestione: un oggetto nel container
 * sopravviverebbe alla richiesta.
 *
 * Il banco dice solo chi è e dove lavora: che cosa può fare lo decidono
 * sempre le policy, azione per azione.
 */
final class EditorialDesk
{
    /** I ruoli della redazione, dal più alto: il primo che la persona ha è il suo. */
    public const ROLES = [UserRole::SuperAdmin, UserRole::Admin, UserRole::Moderator];

    public function __construct(
        public readonly User $user,
        public readonly UserRole $role,
        public readonly ?City $city,
    ) {}

    /**
     * Il banco della richiesta in corso: quello legato dall'API, altrimenti
     * quello del pannello `admin`. `null` fuori da entrambi (sito pubblico,
     * pannelli di gestione, comandi) e per chi non è della redazione.
     */
    public static function current(): ?self
    {
        $request = app()->bound('request') ? app('request') : null;
        $bound = $request instanceof Request ? $request->attributes->get(self::class) : null;

        return $bound instanceof self ? $bound : self::fromPanel();
    }

    /** Vero solo quando il banco l'ha legato l'API (`ResolveEditorialDesk`): la richiesta arriva dall'app. */
    public static function boundToRequest(): bool
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request && $request->attributes->get(self::class) instanceof self;
    }

    /** Vero dentro la redazione, dal pannello o dall'API: la domanda che i servizi facevano a Filament. */
    public static function active(): bool
    {
        return self::current() !== null;
    }

    /** Il banco della richiesta, o 404: per i punti che senza non hanno senso. */
    public static function require(): self
    {
        $desk = self::current();
        abort_if($desk === null, 404);

        return $desk;
    }

    public static function bind(Request $request, self $desk): void
    {
        $request->attributes->set(self::class, $desk);
    }

    /** Il banco di una persona, se è della redazione: ruolo più alto e città corrente. */
    public static function for(User $user, ?City $city = null): ?self
    {
        $role = self::roleOf($user);

        return $role === null ? null : new self($user, $role, $city ?? self::defaultCity());
    }

    /** Il ruolo più alto fra quelli della redazione, o `null` per chi non ne fa parte. */
    public static function roleOf(User $user): ?UserRole
    {
        foreach (self::ROLES as $role) {
            if ($user->hasRole($role->value)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * La città di lavoro quando non ne è stata chiesta una: quella corrente
     * (la prima attiva), altrimenti la prima in assoluto. È la regola dei
     * riquadri del riepilogo (`EditorialWidget::resolveCity`).
     */
    public static function defaultCity(): ?City
    {
        return app(CurrentCity::class)->get() ?? City::query()->orderBy('id')->first();
    }

    /** Il fuso in cui la redazione legge «oggi»: quello della città di lavoro. */
    public function timezone(): string
    {
        $timezone = $this->city?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : app(CurrentCity::class)->timezone();
    }

    /** Admin e super admin: le pagine che il pannello apre con `hasAnyRole(['admin', 'super_admin'])`. */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin || $this->role === UserRole::SuperAdmin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /**
     * Nel pannello il banco è la persona collegata, ma solo nel pannello
     * `admin`: nei pannelli del locale e dell'organizzatore non si è nella
     * redazione nemmeno se si è anche staff.
     */
    private static function fromPanel(): ?self
    {
        if (Filament::getCurrentPanel()?->getId() !== 'admin') {
            return null;
        }
        $user = auth()->user();
        $role = $user instanceof User ? self::roleOf($user) : null;

        /* La città è quella che il pannello usava già (`CurrentCity`), anche
           quando è nulla: il fuso ricade allora su quello dell'applicazione. */
        return $user instanceof User && $role !== null ? new self($user, $role, app(CurrentCity::class)->get()) : null;
    }
}
