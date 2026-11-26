<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Support\Api\ApiDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Le richieste di iscrizione dei locali (§7.3, §9.2) come le legge la
 * redazione: la coda del pannello (`VenueApplicationResource`) e
 * `E/applications` dell'app.
 *
 * Le richieste che aspettano ancora la conferma dell'email non ci sono mai,
 * né nell'elenco né nei conti: le toglie lo scope globale del modello.
 */
final class EditorialApplications
{
    public const TABS = ['pending', 'approved', 'rejected', 'all'];

    public const DEFAULT_TAB = 'pending';

    public const SORTS = ['created_at', 'venue_name'];

    /**
     * La query della coda con, in una sola lettura, il segnale «possibile
     * doppione» (`possible_duplicate`).
     *
     * @param  Builder<VenueApplication>  $query
     * @return Builder<VenueApplication>
     */
    public static function withDuplicateFlag(Builder $query): Builder
    {
        return $query
            ->select('venue_applications.*')
            ->selectRaw('('.self::duplicateSql().') as possible_duplicate');
    }

    /**
     * Un doppione probabile, deciso dal database:
     *
     * - una rivendicazione è doppia se un'altra rivendicazione in coda chiede
     *   lo stesso locale;
     * - un locale nuovo, se esiste già un locale con lo stesso nome (che non
     *   sia quello nato da questa richiesta) o se un'altra richiesta in coda
     *   porta lo stesso nome;
     * - un'organizzazione, se esiste già un organizzatore con lo stesso nome o
     *   un'altra richiesta in coda lo porta.
     *
     * È un avviso, non un blocco: due «Bar Centrale» in due comuni esistono
     * davvero. Chi decide apre la richiesta e trova i nomi con i collegamenti.
     */
    public static function duplicateSql(): string
    {
        $claim = ApplicationKind::Claim->value;
        $organizer = ApplicationKind::Organizer->value;
        $pending = ApplicationStatus::Pending->value;

        $altre = "EXISTS (SELECT 1 FROM venue_applications AS altre WHERE altre.id <> venue_applications.id AND altre.status = '{$pending}' AND altre.email_confirmed_at IS NOT NULL";

        return "CASE WHEN venue_applications.kind = '{$claim}' THEN {$altre} AND altre.kind = '{$claim}' AND altre.venue_id = venue_applications.venue_id)"
            ." WHEN venue_applications.kind = '{$organizer}' THEN (EXISTS (SELECT 1 FROM organizers WHERE LOWER(organizers.name) = LOWER(venue_applications.venue_name) AND (venue_applications.organizer_id IS NULL OR organizers.id <> venue_applications.organizer_id))"
            ." OR {$altre} AND altre.kind = '{$organizer}' AND LOWER(altre.venue_name) = LOWER(venue_applications.venue_name)))"
            .' ELSE (EXISTS (SELECT 1 FROM venues WHERE venues.deleted_at IS NULL AND LOWER(venues.name) = LOWER(venue_applications.venue_name) AND (venue_applications.venue_id IS NULL OR venues.id <> venue_applications.venue_id))'
            ." OR {$altre} AND (altre.kind IS NULL OR altre.kind <> '{$claim}') AND LOWER(altre.venue_name) = LOWER(venue_applications.venue_name)))"
            .' END';
    }

    /**
     * I doppioni probabili di una richiesta, per la pagina di lettura: gli
     * stessi criteri di `duplicateSql()`. Il pannello e l'app ne fanno ognuno
     * il proprio collegamento (`kind` + `id`); un organizzatore porta anche
     * `active`, perché la sua pagina pubblica esiste solo se è attivo.
     *
     * @return list<array{kind: string, id: int, label: string, active: bool}>
     */
    public static function duplicates(VenueApplication $record, string $timezone): array
    {
        $kind = $record->kind();
        $name = mb_strtolower(trim($record->venue_name));
        $found = [];

        $others = VenueApplication::query()->whereKeyNot($record->getKey())->pending()
            ->when(
                $kind === ApplicationKind::Claim,
                fn (Builder $query) => $query->where('kind', ApplicationKind::Claim)->where('venue_id', $record->venue_id),
                fn (Builder $query) => $query->whereRaw('LOWER(venue_name) = ?', [$name])
                    ->where(fn (Builder $kinds) => $kind === ApplicationKind::Organizer
                        ? $kinds->where('kind', ApplicationKind::Organizer)
                        : $kinds->whereNull('kind')->orWhere('kind', ApplicationKind::Venue)),
            )
            ->latest('id')->limit(5)->get();

        foreach ($others as $other) {
            $found[] = [
                'kind' => 'application',
                'id' => (int) $other->getKey(),
                'label' => (string) __('venue_applications.admin.duplicate_application', ['name' => $other->venue_name, 'contact' => $other->contact_name, 'date' => ($ricevuta = $other->getAttribute('created_at')) instanceof CarbonInterface ? $ricevuta->copy()->timezone($timezone)->format('d/m/Y') : '—']),
                'active' => true,
            ];
        }

        if ($kind === ApplicationKind::Venue) {
            $venues = Venue::query()->whereRaw('LOWER(name) = ?', [$name])
                ->when($record->venue_id !== null, fn (Builder $query) => $query->whereKeyNot($record->venue_id))
                ->limit(5)->get();
            foreach ($venues as $venue) {
                $found[] = ['kind' => 'venue', 'id' => (int) $venue->getKey(), 'label' => (string) __('venue_applications.admin.duplicate_venue', ['name' => $venue->name, 'address' => $venue->address ?: '—']), 'active' => true];
            }
        }

        if ($kind === ApplicationKind::Organizer) {
            $organizers = Organizer::query()->whereRaw('LOWER(name) = ?', [$name])
                ->when($record->organizer_id !== null, fn (Builder $query) => $query->whereKeyNot($record->organizer_id))
                ->limit(5)->get();
            foreach ($organizers as $organizer) {
                $found[] = ['kind' => 'organizer', 'id' => (int) $organizer->getKey(), 'label' => (string) __('venue_applications.admin.duplicate_organizer', ['name' => $organizer->name]), 'active' => (bool) $organizer->is_active];
            }
        }

        return $found;
    }

    /** Il sito pubblico del locale o dell'organizzazione scritto nella richiesta. */
    public static function website(VenueApplication $record): ?string
    {
        $website = $record->venueDatum('website') ?? (is_array($record->socials) ? ($record->socials['website'] ?? null) : null);

        return is_string($website) && $website !== '' ? $website : null;
    }

    /**
     * La coda dell'app: schede per stato (predefinita «in attesa», come il
     * pannello), filtri `kind` e `type`, ricerca su nome e contatto, ordine
     * per data di arrivo (la più recente prima).
     *
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, VenueApplication>
     */
    public function paginate(string $tab, array $filters, string $search, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = self::withDuplicateFlag(VenueApplication::query())->with(['reviewer', 'user']);

        if ($tab !== 'all') {
            $query->where('venue_applications.status', $tab);
        }
        $kind = ApplicationKind::tryFrom($filters['kind'] ?? '');
        if ($kind !== null) {
            $query->where('venue_applications.kind', $kind);
        }
        $type = VenueType::tryFrom($filters['type'] ?? '');
        if ($type !== null) {
            $query->where('venue_applications.type', $type);
        }
        $search = trim($search);
        if ($search !== '') {
            // `%` e `_` sono lettere da cercare, non jolly.
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $where): Builder => $where->where('venue_applications.venue_name', 'like', $like)
                ->orWhere('venue_applications.contact_name', 'like', $like)
                ->orWhere('venue_applications.contact_email', 'like', $like));
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy('venue_applications.'.(in_array($column, self::SORTS, true) ? $column : 'created_at'), in_array($column, self::SORTS, true) ? $direction : 'desc')
            ->orderByDesc('venue_applications.id');

        return $query->paginate($perPage, page: $page);
    }

    /**
     * Il conto di ogni stato per le schede, come `ListVenueApplications::getTabs`.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $counts = VenueApplication::query()->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tabs = [];
        foreach ([ApplicationStatus::Pending, ApplicationStatus::Approved, ApplicationStatus::Rejected] as $status) {
            $tabs[$status->value] = (int) ($counts[$status->value] ?? 0);
        }
        $tabs['all'] = array_sum($tabs);

        return $tabs;
    }

    /** @return array<string, mixed> */
    public function row(VenueApplication $application, User $user, string $timezone): array
    {
        $pending = $application->status === ApplicationStatus::Pending;

        return [
            'id' => (int) $application->getKey(),
            'venue_name' => (string) $application->venue_name,
            'kind' => ['value' => $application->kind()->value, 'label' => $application->kind()->label()],
            'type' => ['value' => $application->type->value, 'label' => $application->type->label()],
            'status' => ['value' => $application->status->value, 'label' => $application->status->label()],
            'contact_name' => (string) $application->contact_name,
            'contact_email' => (string) $application->contact_email,
            'possible_duplicate' => $pending && (bool) $application->getAttribute('possible_duplicate'),
            'reviewer' => $application->reviewer?->name,
            'created_at' => ApiDate::instant($application->getAttribute('created_at'), $timezone),
            'abilities' => $this->abilities($application, $user),
        ];
    }

    /**
     * Le azioni della riga e della pagina, con le stesse condizioni del
     * pannello: «Approva» finché non è approvata, «Rifiuta» solo in attesa.
     *
     * @return array<string, bool>
     */
    public function abilities(VenueApplication $application, User $user): array
    {
        $gate = Gate::forUser($user);
        $review = $gate->allows('update', $application);

        return [
            'approve' => $gate->allows('approve', $application) && $application->status !== ApplicationStatus::Approved,
            'reject' => $review && $application->status === ApplicationStatus::Pending,
            'notes' => $review,
            'destroy' => $gate->allows('delete', $application),
        ];
    }

    /**
     * La pagina di una richiesta: esito, locale o organizzazione, contatti
     * pubblici, immagine, contatto, note. Le stesse sezioni del pannello, con
     * le stesse condizioni di visibilità.
     *
     * @return array<string, mixed>
     */
    public function detail(VenueApplication $application, User $user, string $timezone): array
    {
        $application->loadMissing(['reviewer', 'user', 'venue', 'organizer']);
        $kind = $application->kind();
        $datum = static fn (string $key): ?string => ($value = $application->venueDatum($key)) === null ? null : (string) $value;
        $outcome = match ($application->status) {
            ApplicationStatus::Pending => __('venue_applications.admin.outcome_pending'),
            ApplicationStatus::Approved => __('venue_applications.admin.outcome_approved'),
            ApplicationStatus::Rejected => __('venue_applications.admin.outcome_rejected'),
        };
        $linked = $kind === ApplicationKind::Organizer ? $application->organizer : $application->venue;
        $duplicates = $application->status === ApplicationStatus::Pending ? self::duplicates($application, $timezone) : [];

        return [
            ...$this->row($application, $user, $timezone),
            'outcome_hint' => (string) $outcome,
            'queued_at' => ApiDate::instant($application->email_confirmed_at, $timezone),
            'reviewed_at' => ApiDate::instant($application->reviewed_at, $timezone),
            'reviewed_by' => $application->reviewed_at === null ? null : ($application->reviewer->name ?? (string) __('venue_applications.admin.someone')),
            'linked' => $linked === null ? null : [
                'kind' => $kind === ApplicationKind::Organizer ? 'organizer' : 'venue',
                'id' => (int) $linked->getKey(),
                'name' => (string) $linked->name,
                'url' => $linked instanceof Organizer
                    ? ($linked->is_active ? route('organizers.show', $linked) : null)
                    : null,
            ],
            'duplicates' => $duplicates,
            'address' => $kind === ApplicationKind::Organizer ? null : $application->address,
            'postal_code' => $datum('postal_code'),
            'municipality' => $datum('municipality'),
            'capacity' => $datum('capacity'),
            'description' => $datum('description'),
            'message' => $application->message,
            'message_label' => (string) ($kind === ApplicationKind::Claim ? __('venue_applications.admin.fields.proof') : __('admin.fields.message')),
            'public_contacts' => $kind === ApplicationKind::Claim ? null : [
                'website' => self::website($application),
                'public_email' => $datum('public_email'),
                'public_phone' => $datum('public_phone'),
                'instagram' => $datum('instagram'),
                'facebook' => $datum('facebook'),
            ],
            'has_image' => filled($application->image_path),
            'contact_role' => $application->contact_role,
            'contact_phone' => $application->contact_phone,
            'account' => $application->user === null ? null : [
                'id' => (int) $application->user->getKey(),
                'name' => (string) $application->user->name,
                'email' => (string) $application->user->email,
                'verified' => $application->user->hasVerifiedEmail(),
            ],
            'notes' => $application->notes,
            'notes_max' => 5000,
        ];
    }
}
