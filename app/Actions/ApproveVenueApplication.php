<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\City;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\OrganizerAccessGranted;
use App\Services\Geo\AddressGeocoder;
use App\Support\EditorContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Da richiesta di iscrizione a locale vero, in un gesto solo.
 *
 * **Prima non esisteva.** Le richieste arrivavano, si accumulavano in una
 * coda che sapeva contarle, e per accettarne una bisognava aprirla, leggerla,
 * andare in un'altra pagina e ricopiare i dati a mano. Il rischio non e' la
 * fatica: e' che si ricopi male, e che nessuno colleghi mai il locale nuovo
 * alla richiesta che l'aveva chiesto — perdendo il filo di chi aveva scritto
 * e perche'.
 *
 * **Il locale nasce in bozza, non pubblicato.** Accettare una richiesta vuol
 * dire «ci parliamo», non «sei online»: mancano quasi sempre l'indirizzo
 * esatto, le coordinate, una foto. Chi ha chiesto entra nel pannello e
 * completa; la pubblicazione resta una seconda decisione, presa guardando una
 * scheda finita invece che un modulo.
 */
final class ApproveVenueApplication
{
    public function __construct(
        private readonly InviteVenueMemberAction $invito,
        private readonly AddressGeocoder $geocoder,
    ) {}

    public function handle(VenueApplication $richiesta, User $moderatore): Venue|Organizer
    {
        if ($richiesta->kind() === ApplicationKind::Organizer) {
            return $this->organizzatore($richiesta, $moderatore);
        }

        return DB::transaction(function () use ($richiesta, $moderatore): Venue {
            /* Una richiesta gia' collegata a un locale non ne crea un secondo:
               due schede per lo stesso posto sono un guaio che si scopre mesi
               dopo, quando gli eventi sono divisi fra le due. */
            $citta = City::query()->orderBy('id')->firstOrFail();

            /*
             * Una rivendicazione si approva una volta sola. Due persone
             * possono rivendicare lo stesso locale nello stesso pomeriggio:
             * la prima approvata vince, e il controllo sta qui, sotto lock,
             * perche' la pagina da cui si approva l'ha letto un minuto prima.
             * Le altre rivendicazioni pendenti dello stesso locale si chiudono
             * da sole, con il motivo scritto.
             */
            if ($richiesta->kind() === ApplicationKind::Claim) {
                $locale = $richiesta->venue_id === null ? null : Venue::query()->lockForUpdate()->find($richiesta->venue_id);
                if (! $locale instanceof Venue) {
                    throw ValidationException::withMessages(['venue_id' => __('venue_applications.claim_missing_venue')]);
                }
                if ($locale->owners()->exists()) {
                    throw ValidationException::withMessages(['venue_id' => __('venue_applications.claim_already_managed')]);
                }
                VenueApplication::query()->where('venue_id', $locale->getKey())->where('kind', ApplicationKind::Claim)
                    ->whereKeyNot($richiesta->getKey())->where('status', ApplicationStatus::Pending)
                    ->update(['status' => ApplicationStatus::Rejected, 'reviewed_by' => $moderatore->getKey(), 'reviewed_at' => now(), 'notes' => __('venue_applications.claim_superseded')]);
            }

            /*
             * L'indirizzo scritto nella richiesta diventa un punto vero, se
             * si riesce a tradurlo. Prima si scriveva sempre il centro della
             * citta': un locale a due chilometri compariva in centro, e la
             * ricerca «vicino a me» rispondeva sul posto sbagliato.
             *
             * Quando la traduzione non riesce — indirizzo scritto male,
             * servizio irraggiungibile — resta il centro. Un punto da
             * correggere e' meglio di un'approvazione che si blocca, ed e'
             * anche il motivo per cui il locale nasce in bozza.
             */
            $punto = $this->geocoder->coordinate(
                (string) ($richiesta->address ?? ''),
                $citta->name,
            );

            $locale = $richiesta->venue ?? Venue::query()->create([
                'city_id' => $citta->getKey(),
                /*
                 * **Il locale nasce col centro della citta' addosso.**
                 *
                 * Comune, provincia e coordinate non ammettono vuoto — un
                 * locale senza un punto sulla mappa non e' un locale — ma la
                 * richiesta porta solo un indirizzo scritto a mano. Si parte
                 * dal capoluogo, che e' esattamente cio' che offre il pulsante
                 * «Usa il centro della citta'» nella scheda, e si corregge
                 * insieme all'indirizzo prima di pubblicare. E' anche il
                 * motivo per cui il locale resta in bozza.
                 *
                 * Senza queste righe l'approvazione moriva con un errore di
                 * database: l'ha trovato un test, sarebbe successo alla prima
                 * richiesta vera. Le coordinate sono `center_lat`/`center_lng`
                 * e non `lat`/`lng`: la prima stesura usava i secondi, che su
                 * `City` non esistono, e i test passavano lo stesso perche'
                 * `null ?? 0` da' zero — mandando ogni locale approvato al
                 * largo dell'Africa. L'ha visto l'analisi statica, non una
                 * prova.
                 */
                'municipality' => $citta->name,
                'province_code' => $citta->province_code,
                'lat' => $punto['lat'] ?? $citta->center_lat,
                'lng' => $punto['lng'] ?? $citta->center_lng,
                'name' => $richiesta->venue_name,
                'slug' => $this->slug($richiesta->venue_name),
                'type' => $this->tipo($richiesta),
                'address' => (string) ($richiesta->address ?? ''),
                'email' => $richiesta->contact_email,
                'phone' => $richiesta->contact_phone,
                'socials' => $richiesta->socials,
                'status' => VenueStatus::Draft,
            ]);

            $richiesta->forceFill([
                'venue_id' => $locale->getKey(),
                'status' => ApplicationStatus::Approved,
                'reviewed_by' => $moderatore->getKey(),
                'reviewed_at' => now(),
            ])->save();

            /*
             * L'invito arriva a chi ha scritto la richiesta, col suo nome:
             * e' l'unica porta per `/gestione`, e senza di essa avremmo
             * creato un locale che il suo referente non puo' toccare.
             */
            $this->invito->execute(
                $locale,
                (string) $richiesta->contact_email,
                (string) $richiesta->contact_name,
                VenueRole::Owner,
            );

            return $locale;
        });
    }

    /**
     * Un organizzatore senza sede: un'associazione, un collettivo, una
     * rassegna. Nasce attivo, perché non ha una scheda pubblica da completare
     * come un locale: ciò che pubblica passa comunque dalla moderazione degli
     * eventi. Chi ha scritto la richiesta ne diventa il referente e riceve le
     * chiavi di `/organizza`.
     */
    private function organizzatore(VenueApplication $richiesta, User $moderatore): Organizer
    {
        return DB::transaction(function () use ($richiesta, $moderatore): Organizer {
            $citta = City::query()->orderBy('id')->firstOrFail();
            $email = mb_strtolower(trim((string) $richiesta->contact_email));
            $utente = User::query()->where('email', $email)->first();
            $nuovo = ! $utente instanceof User;
            if ($nuovo) {
                $utente = User::query()->create([
                    'name' => EditorContent::clean('name', (string) $richiesta->contact_name) ?: $email,
                    'email' => $email,
                    'password' => Str::password(32),
                ]);
            }

            $organizzatore = $richiesta->organizer ?? Organizer::query()->create([
                'city_id' => $citta->getKey(),
                'owner_id' => $utente->getKey(),
                'name' => $richiesta->venue_name,
                'description' => $richiesta->message,
                'website' => is_array($richiesta->socials) ? ($richiesta->socials['website'] ?? null) : null,
                'email' => $email,
                'is_active' => true,
            ]);
            $organizzatore->users()->syncWithoutDetaching([$utente->getKey()]);

            $richiesta->forceFill([
                'organizer_id' => $organizzatore->getKey(),
                'status' => ApplicationStatus::Approved,
                'reviewed_by' => $moderatore->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $utente->notify(new OrganizerAccessGranted($organizzatore, $nuovo));

            return $organizzatore;
        });
    }

    /**
     * Uno slug che non collida con quelli esistenti.
     *
     * Due locali possono chiamarsi davvero allo stesso modo — «Bar Centrale»
     * in due comuni diversi — e la colonna e' unica: senza suffisso la
     * seconda approvazione fallirebbe con un errore di database davanti a chi
     * sta solo accettando una richiesta.
     */
    private function slug(string $nome): string
    {
        $base = Str::slug($nome) !== '' ? Str::slug($nome) : 'locale';
        $slug = $base;
        $n = 2;

        while (Venue::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Il tipo di locale.
     *
     * `VenueApplication` lo dichiara gia' come `VenueType` nei cast, quindi
     * qui arriva sempre convertito: il controllo che c'era prima non poteva
     * fallire, e il ramo di riserva sotto era codice morto.
     */
    private function tipo(VenueApplication $richiesta): VenueType
    {
        return $richiesta->type;
    }
}
