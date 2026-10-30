<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Account\ClaimMailbox;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueAccessGranted;
use App\Support\EditorContent;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dà a una persona le chiavi di un locale (§10.6), o gliele offre.
 *
 * Serve a due gesti che sembrano diversi e sono lo stesso: la redazione che
 * accredita il referente di un locale appena approvato, e il referente che
 * invita un collaboratore. In entrambi i casi occorre — nell'ordine — un
 * account, una riga in `venue_user` e un messaggio che spieghi come entrare.
 * Scriverlo due volte significherebbe che una delle due strade, prima o poi,
 * dimentica un passaggio.
 *
 * **Un invito si accetta (D64).** Prima un referente che scriveva l'email di
 * un account esistente lo agganciava subito: il locale ne vedeva il nome e la
 * persona riceveva le email dello staff senza aver detto di sì. Ora:
 *
 * - **account esistente** → riga con `accepted_at` vuoto e messaggio con il
 *   collegamento firmato «Accetta l'invito», valido sette giorni; finché non
 *   accetta la persona non entra nel pannello, non riceve niente e il locale
 *   vede solo l'email mascherata (`RespondToVenueInvitation`);
 * - **account nuovo** (creato da questo invito) → stessa riga in sospeso, con
 *   `accepts_on_password`: vale come accettata quando la persona sceglie la
 *   password dal collegamento, che è la prova della casella;
 * - **accesso chiesto dalla persona** (`$requested`: una richiesta di
 *   accreditamento approvata) o **promozione di chi è già dentro** → entra
 *   subito, come prima.
 *
 * **L'account si crea con una password casuale, non con una password nota.**
 * Chi riceve l'invito sceglie la propria dal collegamento nel messaggio: una
 * password predefinita, anche temporanea, resterebbe valida finché qualcuno
 * non la cambia.
 *
 * Il ruolo globale si assegna quando l'appartenenza diventa vera (subito o
 * all'accettazione) e non viene mai declassato: se la persona è già
 * amministratore, l'invito aggiunge l'appartenenza al locale e non tocca
 * quello che era. Lo stesso vale per il ruolo nel locale.
 */
final class InviteVenueMemberAction
{
    /** Quanti giorni vale il collegamento «Accetta l'invito». */
    public const EXPIRES_DAYS = 7;

    public function execute(Venue $venue, string $email, string $name, VenueRole $role, bool $requested = false): User
    {
        $email = mb_strtolower(trim($email));
        $name = EditorContent::clean('name', $name);

        return DB::transaction(function () use ($venue, $email, $name, $role, $requested): User {
            $user = User::query()->where('email', $email)->first();
            $isNew = ! $user instanceof User;

            if ($isNew) {
                $user = User::query()->create([
                    'name' => trim($name) !== '' ? trim($name) : $email,
                    'email' => $email,
                    'password' => Str::password(32),
                ]);
            }

            /*
             * Chi è già del locale non viene mai declassato da un invito: un
             * referente che invita l'email di un altro referente lo
             * trasformerebbe in collaboratore, e da lì potrebbe toglierlo.
             * Il ruolo nel locale si alza (collaboratore → referente, quando
             * accredita la redazione), mai si abbassa: per abbassarlo c'è la
             * modifica della riga nel pannello di redazione. E chi è già
             * dentro (o invitato) con lo stesso ruolo non riceve un secondo
             * messaggio: per quello c'è «Reinvia» nell'elenco.
             */
            $row = $isNew ? null : $this->row($venue, $user, lock: true);
            $current = $row?->getAttribute('role');
            $accepted = $row !== null && $row->getAttribute('accepted_at') !== null;

            if (($current === VenueRole::Owner->value || $current === $role->value) && ($accepted || ! $requested)) {
                return $user;
            }

            $role = $current === VenueRole::Owner->value ? VenueRole::Owner : $role;
            $causa = auth()->user() instanceof User ? auth()->user() : null;

            if ($requested || $accepted) {
                $user->venuesIncludingInvited()->syncWithoutDetaching([
                    $venue->getKey() => [
                        'role' => $role->value,
                        'invited_at' => now(),
                        'accepted_at' => $row?->getAttribute('accepted_at') ?? now(),
                        'accepts_on_password' => false,
                    ],
                ]);
                self::ensureGlobalRole($user, $role);

                SecurityLog::scrivi('accesso_locale_concesso', $user, [
                    'venue_id' => $venue->getKey(),
                    'ruolo' => $role->value,
                ], causa: $causa);

                /*
                 * Un account già esistente ma con la casella non dimostrata può
                 * averlo registrato chiunque, con una password sua: senza questo
                 * passaggio l'accesso concesso gli aprirebbe il pannello. Le
                 * credenziali si azzerano e il messaggio porta il collegamento
                 * per sceglierne una, che arriva solo nella casella vera.
                 * Per un invito da accettare non serve: il pannello non si apre
                 * finché qualcuno non entra con quell'account **e** con il
                 * collegamento arrivato nella casella.
                 */
                $needsPassword = $isNew || app(ClaimMailbox::class)->revocaPerAccessoConcesso($user, $causa);

                $user->notify(new VenueAccessGranted($venue, $role, $needsPassword));

                return $user;
            }

            $acceptsOnPassword = $isNew || (bool) $row?->getAttribute('accepts_on_password');
            $invitedAt = CarbonImmutable::now()->startOfSecond();

            $user->venuesIncludingInvited()->syncWithoutDetaching([
                $venue->getKey() => [
                    'role' => $role->value,
                    'invited_at' => $invitedAt,
                    'accepted_at' => null,
                    'accepts_on_password' => $acceptsOnPassword,
                ],
            ]);

            SecurityLog::scrivi('invito_locale_inviato', $user, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $role->value,
            ], causa: $causa);

            $this->sendInvitation($venue, $user, $role, $acceptsOnPassword, $invitedAt);

            return $user;
        });
    }

    /**
     * «Reinvia» dall'elenco dei collaboratori: un collegamento nuovo, con la
     * scadenza che riparte. Quello vecchio smette di valere, perché porta la
     * data dell'invito precedente. Dice se c'era un invito da reinviare.
     */
    public function resend(Venue $venue, User $user, ?User $causa = null): bool
    {
        return DB::transaction(function () use ($venue, $user, $causa): bool {
            $row = $this->row($venue, $user, lock: true);

            if ($row === null || $row->getAttribute('accepted_at') !== null) {
                return false;
            }

            $role = VenueRole::from((string) $row->getAttribute('role'));
            $invitedAt = CarbonImmutable::now()->startOfSecond();
            $user->venuesIncludingInvited()->updateExistingPivot($venue->getKey(), ['invited_at' => $invitedAt]);

            SecurityLog::scrivi('invito_locale_reinviato', $user, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $role->value,
            ], causa: $causa);

            $this->sendInvitation($venue, $user, $role, (bool) $row->getAttribute('accepts_on_password'), $invitedAt);

            return true;
        });
    }

    /**
     * «Ritira l'invito»: la riga in sospeso sparisce e il collegamento già
     * spedito non porta più a niente. Un'appartenenza accettata non si tocca
     * da qui. Dice se c'era un invito da ritirare.
     */
    public function withdraw(Venue $venue, User $user, ?User $causa = null): bool
    {
        return DB::transaction(function () use ($venue, $user, $causa): bool {
            $row = $this->row($venue, $user, lock: true);

            if ($row === null || $row->getAttribute('accepted_at') !== null) {
                return false;
            }

            $user->venuesIncludingInvited()->wherePivotNull('accepted_at')->detach($venue->getKey());

            SecurityLog::scrivi('invito_locale_ritirato', $user, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $row->getAttribute('role'),
            ], causa: $causa);

            return true;
        });
    }

    /**
     * Il permesso di agire arriva dal ruolo globale (§3), l'indicazione di
     * *quale* locale dalla pivot: servono tutti e due, ed è questo il punto
     * che non li lascia mai separati. Si chiama quando l'appartenenza diventa
     * vera, non all'invito.
     */
    public static function ensureGlobalRole(User $user, VenueRole $role): void
    {
        $needed = $role === VenueRole::Owner ? UserRole::VenueOwner : UserRole::VenueEditor;

        if ($user->hasRole($needed->value)) {
            return;
        }

        $user->assignRole($needed->value);
    }

    /** La riga di `venue_user` fra la persona e il locale, invito compreso. */
    private function row(Venue $venue, User $user, bool $lock = false): ?Pivot
    {
        $query = $venue->membersIncludingInvited()->whereKey($user->getKey());

        $pivot = ($lock ? $query->lockForUpdate() : $query)->first()?->getRelationValue('pivot');

        return $pivot instanceof Pivot ? $pivot : null;
    }

    private function sendInvitation(Venue $venue, User $user, VenueRole $role, bool $acceptsOnPassword, CarbonImmutable $invitedAt): void
    {
        /*
         * L'account nato da questo invito non ha una password che qualcuno
         * conosca: il messaggio porta il collegamento per sceglierla, e
         * sceglierla vale come accettare. Agli altri arriva «Accetta
         * l'invito», da aprire dopo essere entrati con il proprio account.
         */
        $user->notify($acceptsOnPassword
            ? new VenueAccessGranted($venue, $role, needsPassword: true, pending: true)
            : new VenueAccessGranted($venue, $role, needsPassword: false, acceptUrl: RespondToVenueInvitation::url($venue, $user, $invitedAt)));
    }
}
