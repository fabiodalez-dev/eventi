<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\Sponsorship\ToggleSponsorship;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Services\Editorial\Promotion\CampaignWizardDesk;
use App\Services\Editorial\Promotion\EditorialSponsorshipExport;
use App\Services\Editorial\Promotion\EditorialSponsorships;
use App\Services\Sponsorship\Editorial\CampaignBuilder;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Le campagne sponsorizzate della redazione (R-SP-01…06): elenco con filtri
 * ed esportazione, scheda con l'avanzamento, «Nuova sponsorizzazione» in
 * quattro passi, modifica, pausa e riattivazione, eliminazione (anche in
 * gruppo, con la password recente). Solo admin e super admin
 * (`SponsorshipPolicy`, permesso `sponsorships.manage`): il moderatore riceve
 * 403, come nel pannello dove la voce non c'è.
 */
final class SponsorshipController extends Controller
{
    use ListsPlaces;

    /** Le campagne di una cancellazione in gruppo. */
    public const BULK_MAX = 50;

    public function __construct(
        private readonly EditorialSponsorships $campaigns,
        private readonly CampaignWizardDesk $wizard,
    ) {}

    /**
     * L'elenco: `q` (titolo dell'evento o committente), `filter[placement]`,
     * `filter[status]`, `filter[pacing_state]`, `filter[running]=1`, `sort`
     * (`starts_at`, `ends_at`, `impressions`, `clicks`, con `-` davanti per
     * il decrescente; predefinito `-ends_at`).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', Sponsorship::class);
        $page = $this->campaigns->paginate($this->listSearch($request), $this->listFilters($request, EditorialSponsorships::FILTERS),
            $this->listSort($request), $this->listPerPage($request), $this->listPage($request));

        return response()->json([
            ...$this->listPayload($page, array_values(array_map(fn (Sponsorship $row): array => $this->campaigns->row($row, $user), $page->items()))),
            'choices' => EditorialSponsorships::choices(),
            'can_create' => Gate::forUser($user)->allows('create', Sponsorship::class),
        ]);
    }

    /** Il foglio Excel dell'elenco con gli stessi criteri (decisione 8). */
    public function export(Request $request, EditorialSponsorshipExport $export): BinaryFileResponse
    {
        Gate::forUser($this->user($request))->authorize('viewAny', Sponsorship::class);

        return $export->download($this->listSearch($request), $this->listFilters($request, EditorialSponsorships::FILTERS), $this->listSort($request));
    }

    public function show(Request $request, int $sponsorship): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->campaigns->detail($this->campaign($user, $sponsorship, 'view'), $user)]);
    }

    /** La modifica: solo le chiavi inviate, con le regole del modulo del pannello. */
    public function update(Request $request, int $sponsorship): JsonResponse
    {
        $user = $this->user($request);
        $saved = $this->campaigns->update($this->campaign($user, $sponsorship, 'update'), $request->all());

        return response()->json(['data' => $this->campaigns->detail($saved, $user)]);
    }

    /** «Sospendi» o «Attiva» (R-SP-05). */
    public function toggle(Request $request, int $sponsorship, ToggleSponsorship $toggle): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->campaign($user, $sponsorship, 'update');
        $active = $toggle->handle($record);

        return response()->json([
            'data' => $this->campaigns->row($record->refresh(), $user),
            'message' => $active ? __('sponsorships.admin.actions.activated') : __('sponsorships.admin.actions.paused'),
        ]);
    }

    /** Elimina (soft, come il pannello): azione pericolosa, password recente. */
    public function destroy(Request $request, int $sponsorship): JsonResponse
    {
        $this->campaign($this->user($request), $sponsorship, 'delete')->delete();

        return response()->json(['data' => ['deleted' => [$sponsorship]]]);
    }

    /**
     * Elimina in gruppo (`ids[]`, al massimo 50): ogni riga passa dalla
     * policy, come `BulkActions` del pannello; quelle non permesse restano.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', Sponsorship::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $deleted = DB::transaction(function () use ($data, $user): array {
            $done = [];
            foreach (Sponsorship::query()->whereKey($data['ids'])->get() as $row) {
                if (Gate::forUser($user)->allows('delete', $row)) {
                    $row->delete();
                    $done[] = (int) $row->getKey();
                }
            }

            return $done;
        });

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    /**
     * I committenti del primo passo: `type` (`venue` o `organizer`), `q`;
     * `all=1` per tutti i locali (il filtro di un committente esterno e le
     * abilitazioni), altrimenti solo approvati e attivi.
     */
    public function sponsors(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeCreate($user);
        $data = $request->validate([
            'type' => ['required', Rule::in([CampaignBuilder::SPONSOR_VENUE, CampaignBuilder::SPONSOR_ORGANIZER])],
            'q' => ['nullable', 'string', 'max:120'],
            'all' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->wizard->sponsors((string) $data['type'], (string) ($data['q'] ?? ''), (bool) ($data['all'] ?? false))]);
    }

    /**
     * Gli eventi sponsorizzabili: del committente (`sponsor_type`,
     * `venue_id`, `organizer_id`, `venue_filter`), oppure tutti
     * (`sponsor_type=any`, la modifica, con `keep` per l'evento attuale).
     */
    public function events(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', Sponsorship::class);
        $data = $request->validate([
            'sponsor_type' => ['required', Rule::in([CampaignBuilder::SPONSOR_VENUE, CampaignBuilder::SPONSOR_ORGANIZER, CampaignBuilder::SPONSOR_EXTERNAL, 'any'])],
            'venue_id' => ['nullable', 'integer'],
            'organizer_id' => ['nullable', 'integer'],
            'venue_filter' => ['nullable', 'integer'],
            'keep' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $result = $this->wizard->events($data, (string) ($data['q'] ?? ''), isset($data['keep']) ? (int) $data['keep'] : null);

        return response()->json(['data' => $result['data'], 'meta' => ['count' => $result['count'], 'ready' => $result['ready']]]);
    }

    /**
     * Lo stato iniziale di «Nuova sponsorizzazione», anche dall'arrivo da
     * «Sponsorizza questo evento» (`event`).
     */
    public function prefill(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeCreate($user);
        $id = $request->integer('event');
        $event = $id > 0 ? Event::query()->with(['venue', 'organizer'])->find($id) : null;

        return response()->json(['data' => [
            ...$this->wizard->prefill($event),
            'choices' => [
                ...EditorialSponsorships::choices(),
                'months' => array_map(fn (int $m): array => ['value' => (string) $m, 'label' => trans_choice('sponsorships.wizard.months', $m, ['count' => $m])], CampaignBuilder::MONTHS),
                'sponsor_types' => [
                    ['value' => CampaignBuilder::SPONSOR_VENUE, 'label' => __('sponsorships.wizard.sponsor_types.venue'), 'help' => __('sponsorships.wizard.sponsor_types.venue_lead')],
                    ['value' => CampaignBuilder::SPONSOR_ORGANIZER, 'label' => __('sponsorships.wizard.sponsor_types.organizer'), 'help' => __('sponsorships.wizard.sponsor_types.organizer_lead')],
                    ['value' => CampaignBuilder::SPONSOR_EXTERNAL, 'label' => __('sponsorships.wizard.sponsor_types.external'), 'help' => __('sponsorships.wizard.sponsor_types.external_lead')],
                ],
                'scopes' => [
                    ['value' => CampaignBuilder::SCOPE_EVENT, 'label' => __('sponsorships.wizard.scopes.event'), 'help' => __('sponsorships.wizard.scopes.event_lead')],
                    ['value' => CampaignBuilder::SCOPE_ALL, 'label' => __('sponsorships.wizard.scopes.all'), 'help' => __('sponsorships.wizard.scopes.all_lead')],
                ],
                'products' => [
                    ['value' => CampaignBuilder::PRODUCT_PERIOD, 'label' => __('sponsorships.wizard.products.period'), 'help' => __('sponsorships.wizard.products.period_lead')],
                    ['value' => CampaignBuilder::PRODUCT_VIEWS, 'label' => __('sponsorships.wizard.products.views'), 'help' => __('sponsorships.wizard.products.views_lead')],
                ],
            ],
        ]]);
    }

    /**
     * Quello che il pannello calcola mentre si compila (fuso, fine dai mesi,
     * prezzo dal listino, stima, chi occupa lo spazio, anteprima di «tutti
     * gli eventi»). Con `step` (1–4) controlla prima i passi fino a quello:
     * 422 con i campi da correggere, come il pannello quando si va avanti.
     */
    public function estimate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeCreate($user);
        $state = $request->all();
        $step = $request->integer('step');

        if ($step >= 1) {
            app(CampaignBuilder::class)->validate($state, min(4, $step));
        }

        return response()->json(['data' => $this->wizard->preview($state)]);
    }

    /** Salva il percorso: una campagna singola (201) o un'abilitazione automatica con le sue righe. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeCreate($user);
        $result = $this->wizard->create($request->all(), $user);
        $record = $result['record'];

        return response()->json(['data' => [
            'kind' => $record instanceof SponsorshipGrant ? 'grant' : 'sponsorship',
            'id' => (int) $record->getKey(),
            'message' => $result['message'],
        ]], 201);
    }

    private function authorizeCreate(User $user): void
    {
        Gate::forUser($user)->authorize('create', Sponsorship::class);
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function campaign(User $user, int $id, string $ability): Sponsorship
    {
        $campaign = Sponsorship::query()->find($id);
        if (! $campaign instanceof Sponsorship) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize($ability, $campaign);

        return $campaign;
    }
}
