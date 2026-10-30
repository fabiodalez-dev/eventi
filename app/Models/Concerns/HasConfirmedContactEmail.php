<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Contact\ContactEmailConfirmation;
use Illuminate\Support\Carbon;

/**
 * Il recapito del modulo contatti pubblico passa da un doppio opt-in.
 *
 * `contact_email` lo scrive chi gestisce la scheda, senza prova che la casella
 * sia sua: finché `contact_email_verified_at` è vuoto il modulo non si mostra
 * e `PublicContactService` rifiuta l'invio. La regola sta nel modello e non
 * nei moduli perché vale per ogni strada che scrive il recapito (pannello del
 * locale, degli organizzatori, redazione, console).
 *
 * - Cambiare l'indirizzo azzera la conferma; cambiarne solo maiuscole o spazi
 *   no, perché la casella è la stessa.
 * - Togliere un indirizzo confermato e rimetterlo uguale lo lascia da
 *   riconfermare: il passaggio per «vuoto» ha già azzerato la data.
 * - Se l'indirizzo è l'email verificata di chi lo salva, la casella è già
 *   dimostrata e la conferma è immediata.
 * - Quando a salvare è una persona collegata parte l'email di conferma; da
 *   console (import, seeder) il recapito resta in attesa senza spedire posta a
 *   sorpresa, e il pannello offre l'invio.
 *
 * @property string|null $contact_email
 * @property Carbon|null $contact_email_verified_at
 */
trait HasConfirmedContactEmail
{
    public static function bootHasConfirmedContactEmail(): void
    {
        static::saving(static function (self $model): void {
            $model->resetContactEmailConfirmationIfChanged();
        });

        static::saved(static function (self $model): void {
            $changed = $model->wasRecentlyCreated ? filled($model->contact_email) : $model->wasChanged('contact_email');
            /* Un indirizzo cambiato e non confermato subito azzera la data anche
               se era già vuota quando il modulo l'ha letta: Eloquent scrive
               solo le colonne cambiate, e una conferma arrivata fra la lettura
               e il salvataggio resterebbe attaccata all'indirizzo nuovo. */
            if ($changed && $model->contact_email_verified_at === null) {
                $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(['contact_email_verified_at' => null]);
            }
            if ($changed && ! $model->hasConfirmedContactEmail() && auth()->user() instanceof User) {
                app(ContactEmailConfirmation::class)->send($model);
            }
        });
    }

    public function initializeHasConfirmedContactEmail(): void
    {
        $this->mergeCasts(['contact_email_verified_at' => 'datetime']);
    }

    public function hasConfirmedContactEmail(): bool
    {
        return filled($this->contact_email) && $this->contact_email_verified_at !== null;
    }

    public function markContactEmailConfirmed(): void
    {
        $this->forceFill(['contact_email_verified_at' => now()])->save();
    }

    public static function normalizeContactEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    private function resetContactEmailConfirmationIfChanged(): void
    {
        /* Una data scritta di proposito nello stesso salvataggio (backfill,
           conferma dal collegamento) vince sull'azzeramento. */
        if (! $this->isDirty('contact_email') || $this->isDirty('contact_email_verified_at')) {
            return;
        }

        $nuovo = self::normalizeContactEmail($this->contact_email);
        if ($this->exists && $nuovo !== '' && $nuovo === self::normalizeContactEmail($this->getOriginal('contact_email'))) {
            return;
        }

        $this->contact_email_verified_at = null;

        $actor = auth()->user();
        if ($nuovo !== '' && $actor instanceof User && $actor->hasVerifiedEmail() && self::normalizeContactEmail($actor->email) === $nuovo) {
            $this->contact_email_verified_at = now();
        }
    }
}
