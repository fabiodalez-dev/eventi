<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A che punto e' un ordine di sponsorizzazione.
 *
 * Il pagamento non chiude l'ordine: un ordine pagato e' una richiesta, e la
 * redazione decide (D53, «chi decide non e' chi modera»). Da qui i due esiti
 * finali dopo `Paid`, e `Cancelled` solo prima di pagare.
 *
 * `Requested` e' la strada senza pagamento online: chi gestisce il locale o
 * l'organizzazione chiede posizione, durata ed evento, e la redazione decide
 * come per un ordine pagato, registrando a mano il pagamento o concedendola
 * gratis. Finche' non approva non si attiva niente. La colonna e' una
 * stringa: lo stato nuovo non ha bisogno di migrazioni.
 */
enum SponsorshipOrderStatus: string
{
    case Requested = 'requested';
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('promotions.orders.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'info',
            self::PendingPayment => 'warning',
            self::Paid => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Requested => in_array($next, [self::Approved, self::Rejected, self::Cancelled], true),
            self::PendingPayment => in_array($next, [self::Paid, self::Cancelled], true),
            self::Paid => in_array($next, [self::Approved, self::Rejected], true),
            self::Approved, self::Rejected, self::Cancelled => false,
        };
    }

    /** Aspetta la decisione della redazione: pagato online oppure richiesto senza pagamento. */
    public function awaitsDecision(): bool
    {
        return $this === self::Paid || $this === self::Requested;
    }

    /** @return list<self> */
    public static function awaitingDecision(): array
    {
        return [self::Paid, self::Requested];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
