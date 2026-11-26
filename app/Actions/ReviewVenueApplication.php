<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\VenueApplicationRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le due porte di una richiesta di iscrizione (§7.3, §9.2): «Approva» e
 * «Rifiuta», dalla riga dell'elenco e dalla pagina della richiesta del
 * pannello e dall'app della redazione.
 *
 * Stavano nelle azioni di `VenueApplicationResource`; qui sono una regola
 * sola. Entrambe rileggono la richiesta **sotto lock**, perché la pagina da
 * cui si tocca l'ha letta prima: due tocchi, o due persone nello stesso
 * minuto, non creano due locali e non rifiutano una richiesta appena
 * accolta. Il permesso (`VenueApplicationPolicy::update`) lo controlla chi
 * chiama.
 */
final class ReviewVenueApplication
{
    /** Il contatore del menu del pannello (`nav-badge:*`, 60 secondi): si azzera dopo ogni decisione. */
    public const BADGE_CACHE_KEY = 'nav-badge:venue-applications-pending';

    public function __construct(private readonly ApproveVenueApplication $approve) {}

    /**
     * Accoglie la richiesta: crea il locale (o l'organizzatore, o consegna le
     * chiavi del locale rivendicato) con `ApproveVenueApplication`.
     *
     * @throws ValidationException su `status` se la richiesta non è in coda (email da confermare), è già approvata o l'ha mandata `$moderator`
     */
    public function approve(VenueApplication $application, User $moderator): Venue|Organizer
    {
        $subject = DB::transaction(function () use ($application, $moderator): Venue|Organizer {
            $locked = VenueApplication::query()->lockForUpdate()->find($application->getKey());

            if (! $locked instanceof VenueApplication) {
                throw ValidationException::withMessages(['status' => __('venue_applications.awaiting_email')]);
            }
            if ($locked->status === ApplicationStatus::Approved) {
                throw ValidationException::withMessages(['status' => __('venue_applications.admin.already_approved')]);
            }
            /* Anche qui e non solo nella policy: una regola sola per pannello e app. */
            if ($locked->sentBy($moderator)) {
                throw ValidationException::withMessages(['status' => __('venue_applications.admin.own_application')]);
            }

            return $this->approve->handle($locked, $moderator);
        });

        Cache::forget(self::BADGE_CACHE_KEY);

        return $subject;
    }

    /**
     * Respinge una richiesta ancora in attesa, con il motivo nelle note (fra
     * un mese, quando la stessa persona chiederà perché, la risposta dev'essere
     * qui). Avvisa chi l'ha mandata solo se lo si chiede e solo se ha un
     * account. `null` se nel frattempo qualcuno aveva già deciso.
     *
     * @return array{application: VenueApplication, notified: bool}|null
     */
    public function reject(VenueApplication $application, User $moderator, string $notes, bool $notify = false, ?string $message = null): ?array
    {
        $rejected = DB::transaction(function () use ($application, $moderator, $notes): ?VenueApplication {
            $locked = VenueApplication::query()->lockForUpdate()->find($application->getKey());

            if (! $locked instanceof VenueApplication || $locked->status !== ApplicationStatus::Pending) {
                return null;
            }

            $locked->forceFill([
                'status' => ApplicationStatus::Rejected,
                'reviewed_by' => $moderator->getKey(),
                'reviewed_at' => now(),
                'notes' => $notes,
            ])->save();

            return $locked;
        });

        if ($rejected === null) {
            return null;
        }

        Cache::forget(self::BADGE_CACHE_KEY);

        $sender = $rejected->user;
        $notified = $notify && $sender instanceof User;
        if ($notified) {
            $sender->notify(new VenueApplicationRejected($rejected, $message));
        }

        return ['application' => $rejected, 'notified' => $notified];
    }
}
