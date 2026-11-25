<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Support\VenueApplications\ApplicationRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

/**
 * Mandare, confermare o ritirare una richiesta di registrazione o di
 * rivendicazione: quello che facevano i controller dei moduli del sito
 * (`VenueApplicationController`, `VenueClaimController`,
 * `OrganizerApplicationController`) e che ora fanno anche le rotte dell'app.
 *
 * Il sito ci arriva dopo aver deciso l'account (quello collegato, o quello
 * nuovo di un ospite); l'app sempre con l'account collegato e l'email
 * confermata. La coda, le ricevute e il tetto delle richieste aperte restano
 * di `CreateVenueApplication`.
 */
final class SubmitVenueApplication
{
    public function __construct(private readonly CreateVenueApplication $applications) {}

    /**
     * Un locale nuovo, con l'immagine facoltativa sul disco privato.
     *
     * @param  array<string, mixed>  $data  i campi già validati
     */
    public function venue(array $data, string $email, int $accountId, bool $confirmed, ?UploadedFile $image = null): VenueApplication
    {
        return $this->applications->handle([
            'venue_name' => (string) $data['venue_name'],
            'type' => self::text($data, 'type'),
            'address' => self::text($data, 'address'),
            'website' => self::text($data, 'website'),
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => self::text($data, 'contact_role'),
            'contact_phone' => self::text($data, 'contact_phone'),
            'contact_email' => $email,
            'message' => self::text($data, 'message'),
            'venue_data' => ApplicationRules::venueData($data),
            'image_path' => $image !== null ? ($image->store('venue-applications', 'local') ?: null) : null,
        ], $accountId, ApplicationKind::Venue, null, $confirmed);
    }

    /**
     * «Questo locale è mio»: la richiesta porta il locale già agganciato.
     *
     * @param  array<string, mixed>  $data  i campi già validati
     */
    public function claim(Venue $venue, array $data, string $email, int $accountId, bool $confirmed): VenueApplication
    {
        return $this->applications->handle([
            'venue_name' => $venue->name,
            'type' => $venue->type->value,
            'address' => $venue->address,
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => self::text($data, 'contact_role'),
            'contact_phone' => self::text($data, 'contact_phone'),
            'contact_email' => $email,
            'message' => (string) $data['message'],
        ], $accountId, ApplicationKind::Claim, (int) $venue->getKey(), $confirmed);
    }

    /**
     * Un'organizzazione: entra in coda subito, anche da ospite. L'account lo
     * crea (o lo trova) l'approvazione.
     *
     * @param  array<string, mixed>  $data  i campi già validati
     */
    public function organizer(array $data, string $email, ?int $userId): VenueApplication
    {
        return $this->applications->handle([
            'venue_name' => (string) $data['venue_name'],
            'website' => self::text($data, 'website'),
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => self::text($data, 'contact_role'),
            'contact_phone' => self::text($data, 'contact_phone'),
            'contact_email' => $email,
            'message' => self::text($data, 'message'),
        ], $userId, ApplicationKind::Organizer);
    }

    /**
     * Una richiesta di questo account che aspetta la conferma: scritta da
     * ospite con il suo indirizzo, prima che entrasse. Di un altro, già in
     * coda o già decisa: 404.
     */
    public function awaiting(?User $user, int $id): VenueApplication
    {
        return VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->awaitingEmail()
            ->whereKey($id)
            ->where('user_id', $user?->getKey())
            ->where('status', ApplicationStatus::Pending)
            ->firstOrFail();
    }

    /** La mette in coda: la redazione la vede, chi l'ha scritta riceve la ricevuta. */
    public function confirm(VenueApplication $application): void
    {
        $this->applications->queue($application);
    }

    /** Via la richiesta, con l'immagine. */
    public function discard(VenueApplication $application): void
    {
        $this->applications->discard($application);
    }

    /**
     * «Il tuo locale è già nel catalogo?»: i locali pubblici col nome cercato,
     * con lo stato di rivendicazione già calcolato.
     *
     * @return Collection<int, Venue>
     */
    public function catalogue(string $query): Collection
    {
        $like = '%'.addcslashes(mb_substr($query, 0, 80), '%_\\').'%';

        return Venue::query()
            ->where('status', VenueStatus::Approved)
            ->where('name', 'like', $like)
            ->withClaimState()
            ->orderBy('name')
            ->limit(6)
            ->get(['id', 'slug', 'name', 'address', 'municipality', 'status']);
    }

    /** @param  array<string, mixed>  $data */
    private static function text(array $data, string $key): ?string
    {
        return isset($data[$key]) ? (string) $data[$key] : null;
    }
}
