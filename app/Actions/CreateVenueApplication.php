<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\Permission;
use App\Enums\VenueType;
use App\Models\User;
use App\Models\VenueApplication;
use App\Notifications\AdminVenueApplicationSubmitted;
use App\Notifications\VenueApplicationReceived;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Registra una richiesta di accreditamento di un locale (§11.1,
 * `/registra-il-tuo-locale`), una rivendicazione o un organizzatore.
 *
 * La pratica nasce `pending` e non crea alcun locale: l'approvazione è un atto
 * della redazione (§14.6), e fino ad allora nulla di quanto scritto qui compare
 * sul sito pubblico.
 *
 * **In coda solo con l'email confermata.** Una richiesta legata a un account
 * con l'email ancora da confermare nasce con `email_confirmed_at` vuoto e la
 * redazione non la vede (scope globale di `VenueApplication`): ci entra con
 * `queue()`, che chiama il listener sulla conferma dell'email o la persona
 * stessa dal modulo, dopo l'accesso.
 */
final class CreateVenueApplication
{
    /** Quante richieste in attesa può avere un account, o un indirizzo email scritto da ospite. */
    public const MAX_PENDING_PER_EMAIL = 3;

    /**
     * @param  array{venue_name: string, contact_name: string, contact_role?: string|null, contact_phone?: string|null, contact_email: string, address?: string|null, type?: string|null, website?: string|null, message?: string|null, venue_data?: array<string, mixed>|null, image_path?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null, ApplicationKind $kind = ApplicationKind::Venue, ?int $venueId = null, bool $confirmed = true): VenueApplication
    {
        $website = $data['website'] ?? null;

        $application = VenueApplication::create([
            'kind' => $kind,
            'user_id' => $userId,
            'venue_id' => $kind === ApplicationKind::Claim ? $venueId : null,
            'venue_name' => $data['venue_name'],
            'contact_name' => $data['contact_name'],
            'contact_role' => $data['contact_role'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'contact_email' => mb_strtolower(trim($data['contact_email'])),
            'address' => $data['address'] ?? null,
            'type' => VenueType::tryFrom((string) ($data['type'] ?? '')) ?? VenueType::Altro,
            'socials' => filled($website) ? ['website' => $website] : null,
            'venue_data' => self::venueData($data['venue_data'] ?? null),
            'image_path' => $data['image_path'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => ApplicationStatus::Pending,
            'email_confirmed_at' => null,
        ]);

        if ($confirmed) {
            $this->queue($application);
        }

        return $application;
    }

    /**
     * La richiesta entra in coda: la redazione la vede e riceve l'avviso, chi
     * l'ha scritta riceve la ricevuta. Una richiesta già in coda non si
     * rimette in coda (niente avvisi doppi).
     */
    public function queue(VenueApplication $application): void
    {
        if (! $application->isAwaitingEmail()) {
            return;
        }

        $application->forceFill(['email_confirmed_at' => now()])->save();

        /* La ricevuta va solo a un account: è il suo indirizzo, già
           confermato. A un indirizzo scritto in un modulo pubblico non parte
           niente, altrimenti il modulo diventerebbe un modo per mandare
           messaggi a chiunque. */
        if ($application->user instanceof User) {
            $application->user->notify(new VenueApplicationReceived($application));
        }

        $reviewers = $this->reviewers();
        if ($reviewers->isNotEmpty()) {
            Notification::send($reviewers, new AdminVenueApplicationSubmitted($application));
        }
    }

    /**
     * Una richiesta in attesa di conferma che la persona non vuole più: via,
     * con l'immagine.
     */
    public function discard(VenueApplication $application): void
    {
        if (filled($application->image_path)) {
            Storage::disk('local')->delete((string) $application->image_path);
        }

        $application->delete();
    }

    /**
     * Se chi scrive ha già troppe richieste aperte, o ha già una
     * rivendicazione aperta per lo stesso locale.
     *
     * Chi è collegato si conta per account. Con l'email confermata contano
     * solo le richieste già in coda: quelle in attesa di conferma può
     * averle scritte un ospite qualunque con il suo indirizzo, e non devono
     * impedirgli di mandare la propria (le vede in cima al modulo e le può
     * eliminare). Con l'email ancora da confermare contano tutte le sue.
     * Un ospite si conta per indirizzo, con tutte le richieste aperte che lo
     * usano: è il tetto che impedisce di riempire di richieste la casella o
     * l'account di qualcun altro.
     */
    public function limitReached(string $email, ?User $user = null, ?int $claimedVenueId = null): bool
    {
        $pending = VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->where('status', ApplicationStatus::Pending);

        if ($user instanceof User) {
            $pending->where('user_id', $user->getKey());
            if ($user->hasVerifiedEmail()) {
                $pending->whereNotNull('email_confirmed_at');
            }
        } else {
            $pending->where('contact_email', mb_strtolower(trim($email)));
        }

        if ((clone $pending)->count() >= self::MAX_PENDING_PER_EMAIL) {
            return true;
        }

        return $claimedVenueId !== null && (clone $pending)
            ->where('kind', ApplicationKind::Claim)
            ->where('venue_id', $claimedVenueId)
            ->exists();
    }

    /**
     * Solo le chiavi conosciute, solo valori scalari e non vuoti.
     *
     * @return array<string, string|int>|null
     */
    public static function venueData(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $clean = [];
        foreach (VenueApplication::VENUE_DATA_KEYS as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                $clean[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /** @return Collection<int, User> */
    private function reviewers(): Collection
    {
        /* Senza il permesso a database (un'installazione nuova, un test senza
           seeder) non c'è nessuno da avvisare: la richiesta resta in coda. */
        return rescue(
            static fn (): Collection => User::permission(Permission::ReviewVenueApplications->value)->get(),
            new Collection,
            false,
        );
    }
}
