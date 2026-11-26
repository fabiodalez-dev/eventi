<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * A che punto è l'iscrizione di un account, come la vede la redazione.
 *
 * Le colonne che lo dicono sono due e raccontano cose diverse:
 * `email_verified_at` è la conferma dell'indirizzo, `email_claim_pending_at`
 * dice che chi ha scelto la password non ha ancora dimostrato di possedere la
 * casella (`ClaimMailbox`, D64). Chi si registra nasce con la seconda piena e
 * la prima vuota; il collegamento della registrazione aperto nel browser (o
 * nell'app) che si è registrato le sistema tutte e due insieme. Aperto da un
 * altro dispositivo conferma l'indirizzo ma lascia il sospeso: la password non
 * apre ancora l'account finché non arriva il collegamento di accesso.
 *
 * Gli account creati dallo staff o importati senza conferma ricadono in
 * «in attesa»: per la redazione la domanda è la stessa — questa persona ha
 * mai dimostrato di leggere quella casella?
 */
enum RegistrationState: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Confirmed = 'confirmed';
    case Pending = 'pending';
    case FirstAccess = 'first_access';

    /** I giorni che contano come «iscrizioni recenti» nel riepilogo e nel menu. */
    public const RECENT_DAYS = 7;

    public static function for(User $user): self
    {
        return match (true) {
            $user->email_verified_at === null => self::Pending,
            $user->email_claim_pending_at !== null => self::FirstAccess,
            default => self::Confirmed,
        };
    }

    /**
     * Gli account che non hanno ancora completato l'iscrizione: indirizzo da
     * confermare oppure primo accesso da fare.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function scopeIncomplete(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->whereNull('email_verified_at')
            ->orWhereNotNull('email_claim_pending_at'));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scope(Builder $query): Builder
    {
        return match ($this) {
            self::Pending => $query->whereNull('email_verified_at'),
            self::FirstAccess => $query->whereNotNull('email_verified_at')->whereNotNull('email_claim_pending_at'),
            self::Confirmed => $query->whereNotNull('email_verified_at')->whereNull('email_claim_pending_at'),
        };
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', CarbonImmutable::now()->subDays(self::RECENT_DAYS));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    public function getLabel(): string
    {
        return __('users.registration.states.'.$this->value);
    }

    public function getDescription(): string
    {
        return __('users.registration.help.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Pending => 'warning',
            self::FirstAccess => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Confirmed => Heroicon::OutlinedCheckCircle,
            self::Pending => Heroicon::OutlinedEnvelope,
            self::FirstAccess => Heroicon::OutlinedKey,
        };
    }
}
