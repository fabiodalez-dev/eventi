<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Actions\Account\RemoveSavedOccurrence;
use App\Actions\Account\SaveOccurrences;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\MergeSavedRequest;
use App\Http\Requests\Api\V1\Me\SavedQueryRequest;
use App\Http\Requests\Api\V1\Me\StoreSavedRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le date salvate (§15.3 e §15.8).
 *
 * Tre indirizzi e una sola regola sotto: **si salva l'occorrenza**. La lista
 * passa dal motore come ogni altra lista del prodotto — `savedBy()` è un
 * filtro, `upcoming()` è la finestra — perché «da oggi in poi» deve avere una
 * definizione sola anche qui (§8.1).
 */
final class SavedController extends Controller
{
    use InteractsWithMe;

    public function __construct(private readonly OccurrenceFeed $feed) {}

    public function index(SavedQueryRequest $request): JsonResponse
    {
        $city = $this->city();
        $user = $this->user($request);

        $query = EventOccurrenceQuery::for($city)->savedBy($user);

        /*
         * Con l'archivio l'ordine si ribalta: una lista che comincia dalla
         * serata più lontana nel passato si scorre fino in fondo prima di
         * arrivare a ieri.
         */
        $request->onlyUpcoming() ? $query->upcoming() : $query->orderByNewestFirst();

        $paginator = $query->cursorPaginate($request->limit(), $request->cursor())->withQueryString();

        /** @var Collection<int, EventOccurrence> $items */
        $items = new Collection($paginator->items());

        $includes = $request->includes();
        $this->feed->hydrate($items, $includes);

        $context = ApiContext::forOccurrences($city, $includes, $user, $this->feed->ids($items));

        return ApiResponse::page(
            $paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $context),
        );
    }

    public function store(StoreSavedRequest $request, SaveOccurrences $save): JsonResponse
    {
        $city = $this->city();
        $user = $this->user($request);

        $saved = $save->many($user, $city, [(int) $request->validated('occurrence_id')]);

        /*
         * Una data che il motore non restituisce è passata, non pubblica o
         * inesistente. Sono tre casi diversi per chi scrive il server e uno
         * solo per chi chiama: quella data non si può mettere in agenda.
         */
        if ($saved->isEmpty()) {
            throw new ApiException(ApiErrorCode::NotFound, __('account.api.not_savable'));
        }

        return ApiResponse::item([
            'occurrence_id' => (int) $saved->value('occurrence_id'),
            'message' => __('account.api.saved'),
        ], status: 201);
    }

    /**
     * La migrazione dei salvataggi di chi era anonimo (§15.1). Duplicati ed
     * eventi già passati si ignorano in silenzio, e la risposta dice quante
     * date sono entrate davvero: è quel numero che permette al client di
     * svuotare il `localStorage` **solo dopo** la conferma del server.
     */
    public function merge(MergeSavedRequest $request, SaveOccurrences $save): JsonResponse
    {
        $city = $this->city();
        $user = $this->user($request);

        $ids = $request->occurrenceIds();
        $saved = $save->many($user, $city, $ids);

        /** @var list<int> $mergedIds */
        $mergedIds = $saved->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return ApiResponse::item([
            'merged' => count($mergedIds),
            'ignored' => count(array_unique($ids)) - count($mergedIds),
            'occurrence_ids' => $mergedIds,
            'message' => __('account.api.merged'),
        ]);
    }

    public function destroy(Request $request, int $occurrence, RemoveSavedOccurrence $remove): JsonResponse
    {
        if (! $remove($this->user($request), $occurrence)) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return ApiResponse::item(['message' => __('account.api.unsaved')]);
    }
}
