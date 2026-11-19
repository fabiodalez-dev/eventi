<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Account\ClaimMailbox;
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
use App\Notifications\VenueApplicationApproved;
use App\Services\Geo\AddressGeocoder;
use App\Support\EditorContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        /* Una richiesta che aspetta ancora la conferma dell'email non è in
           coda: nessuno l'ha vista, e chi l'ha scritta può ancora ritirarla. */
        if ($richiesta->isAwaitingEmail()) {
            throw ValidationException::withMessages(['status' => __('venue_applications.awaiting_email')]);
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
                /* Conta anche un referente invitato e non ancora entrato: la
                   rivendicazione approvata prima ha già consegnato le chiavi,
                   e una seconda non deve consegnarle di nuovo a un altro. */
                if ($locale->hasOwnerOrInvitedOwner()) {
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
                trim((string) ($richiesta->address ?? '').' '.($richiesta->venueDatum('postal_code') ?? '')),
                (string) ($richiesta->venueDatum('municipality') ?? $citta->name),
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
                'province_code' => $citta->province_code,
                'lat' => $punto['lat'] ?? $citta->center_lat,
                'lng' => $punto['lng'] ?? $citta->center_lng,
                'name' => $richiesta->venue_name,
                'slug' => $this->slug($richiesta->venue_name),
                'type' => $this->tipo($richiesta),
                'address' => (string) ($richiesta->address ?? ''),
                ...$this->datiPubblici($richiesta, $citta),
                'status' => VenueStatus::Draft,
            ]);

            if ($locale->wasRecentlyCreated) {
                $this->immagine($richiesta, $locale);
            }

            $richiesta->forceFill([
                'venue_id' => $locale->getKey(),
                'status' => ApplicationStatus::Approved,
                'reviewed_by' => $moderatore->getKey(),
                'reviewed_at' => now(),
            ])->save();

            /*
             * L'invito arriva a chi ha scritto la richiesta, col suo nome:
             * e' l'unica porta per `/gestione`, e senza di essa avremmo
             * creato un locale che il suo referente non puo' toccare. Entra
             * subito solo se la richiesta l'ha mandata proprio lui, da
             * collegato e con quella casella confermata: il modulo e' pubblico
             * e l'email di contatto e' un campo libero, quindi chiunque
             * potrebbe scriverci l'indirizzo di un altro e farlo agganciare
             * senza consenso. Negli altri casi parte l'invito da accettare
             * (D65).
             */
            $mittente = $richiesta->user;
            $richiestaDaLui = $mittente instanceof User && $mittente->hasVerifiedEmail()
                && mb_strtolower(trim($mittente->email)) === mb_strtolower(trim((string) $richiesta->contact_email));
            $this->invito->execute(
                $locale,
                (string) $richiesta->contact_email,
                (string) $richiesta->contact_name,
                VenueRole::Owner,
                requested: $richiestaDaLui,
                notify: ! $richiestaDaLui,
            );

            /* Chi entra subito riceve «la tua richiesta è stata accolta» con
               il pannello a portata di clic, invece dell'invito generico. */
            if ($richiestaDaLui) {
                $mittente->notify(new VenueApplicationApproved($locale, $richiesta->kind()));
            }

            return $locale;
        });
    }

    /**
     * I contatti **pubblici** del locale vengono dai dati del locale scritti
     * nella richiesta, mai dai contatti di chi scrive: email e telefono del
     * referente sono suoi, non del locale, e finivano sulla scheda pubblica.
     * Il sito, scritto nella richiesta, diventa il sito del locale (prima
     * restava fra i social e si perdeva).
     *
     * @return array<string, mixed>
     */
    private function datiPubblici(VenueApplication $richiesta, City $citta): array
    {
        $website = $richiesta->venueDatum('website') ?? (is_array($richiesta->socials) ? ($richiesta->socials['website'] ?? null) : null);
        $social = array_filter([
            'instagram' => $richiesta->venueDatum('instagram'),
            'facebook' => $richiesta->venueDatum('facebook'),
        ], static fn (mixed $url): bool => is_string($url) && $url !== '');
        $capienza = $richiesta->venueDatum('capacity');

        return [
            'municipality' => $richiesta->venueDatum('municipality') ?? $citta->name,
            'postal_code' => $richiesta->venueDatum('postal_code'),
            'email' => $richiesta->venueDatum('public_email'),
            'phone' => $richiesta->venueDatum('public_phone'),
            'website' => is_string($website) && $website !== '' ? $website : null,
            'socials' => $social === [] ? null : $social,
            'capacity' => is_numeric($capienza) ? (int) $capienza : null,
            'description' => $richiesta->venueDatum('description'),
        ];
    }

    /**
     * L'immagine della richiesta, se c'è, diventa la copertina della scheda in
     * bozza. Sta sul disco privato e lì resta (la copia va nella libreria dei
     * media): un'immagine che non si riesce a leggere non ferma
     * l'approvazione, la copertina si carica dal pannello.
     */
    private function immagine(VenueApplication $richiesta, Venue $locale): void
    {
        $percorso = $richiesta->image_path;

        if (! is_string($percorso) || $percorso === '' || ! Storage::disk('local')->exists($percorso)) {
            return;
        }

        rescue(static fn () => $locale->addMediaFromDisk($percorso, 'local')->preservingOriginal()->toMediaCollection('cover'), report: true);
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

            /*
             * Come per i locali (D65): l'email di contatto e' un campo libero
             * del modulo pubblico, quindi un account esistente entra in
             * `/organizza` solo se la richiesta l'ha mandata lui, da
             * collegato e con quella casella confermata. Altrimenti chiunque
             * potrebbe scrivere l'indirizzo di un altro e farlo diventare
             * referente di un organizzatore inventato.
             */
            $mittente = $richiesta->user;
            $richiestaDaLui = $mittente instanceof User && $mittente->is($utente) && $mittente->hasVerifiedEmail();
            // Un account con la casella non dimostrata non è un altro da proteggere: le credenziali
            // si azzerano più sotto e decide chi legge la casella. Una seconda approvazione della
            // stessa richiesta non cambia referente.
            if (! $nuovo && $utente->hasVerifiedEmail() && ! $richiestaDaLui && $richiesta->organizer === null) {
                throw ValidationException::withMessages(['contact_email' => __('venue_applications.organizer_needs_requester')]);
            }

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

            // Come per l'invito in un locale: un account con la casella non
            // dimostrata perde le credenziali e riceve il collegamento per
            // sceglierne una, invece di trovarsi chi l'ha registrato dentro
            // `/organizza`.
            $sceltaPassword = $nuovo || app(ClaimMailbox::class)->revocaPerAccessoConcesso($utente, $moderatore);
            $utente->notify(new OrganizerAccessGranted($organizzatore, $sceltaPassword));

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
