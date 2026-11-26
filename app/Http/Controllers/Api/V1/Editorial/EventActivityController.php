<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Events\EditorialListRequest;
use App\Models\Event;
use App\Services\Editorial\ActivityHistory;
use App\Services\Editorial\EditorialEvents;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * La cronologia delle modifiche di un evento e «Ripristina i valori di
 * prima» (R-EV-25), come la sezione «Modifiche» della modifica dell'evento
 * nel pannello: la vede e la usa chi può modificare l'evento
 * (`EventPolicy::update`). Le regole stanno in `ActivityHistory`.
 */
final class EventActivityController extends Controller
{
    /** Le righe, dalla più recente: data, tipo, autore, modifiche, campi ripristinabili. */
    public function index(EditorialListRequest $request, int $event): JsonResponse
    {
        $record = EventController::find($event);
        Gate::authorize('update', $record);
        $page = $record->activitiesAsSubject()->with('causer')->latest('created_at')->latest('id')
            ->paginate($request->perPage(), page: $request->page());

        return response()->json([
            'data' => collect($page->items())->map(fn (Activity $activity): array => $this->row($activity, $record))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Rimette i valori di prima dei campi toccati da una riga «Modificato».
     * Il ripristino è una modifica come le altre e lascia la sua riga.
     */
    public function restore(int $event, int $activity, EditorialEvents $events): JsonResponse
    {
        $record = EventController::find($event);
        Gate::authorize('update', $record);
        $row = $record->activitiesAsSubject()->whereKey($activity)->first();
        if (! $row instanceof Activity) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        if (! ActivityHistory::restorable($row, $record) || ! ActivityHistory::restore($record, $row)) {
            throw new ApiException(ApiErrorCode::Conflict, __('admin.activity.restore_empty'));
        }

        return response()->json([
            'data' => $events->detail($record->refresh(), EditorialDesk::require()->user),
            'message' => __('admin.activity.restored'),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Activity $activity, Event $event): array
    {
        $restorable = ActivityHistory::restorable($activity, $event);
        $fields = array_keys(ActivityHistory::previous($activity));

        return [
            'id' => (int) $activity->getKey(),
            'created_at' => $activity->created_at?->utc()->toIso8601String(),
            'event' => ['value' => (string) $activity->event, 'label' => ActivityHistory::eventLabel($activity->event)],
            'causer' => ActivityHistory::causerLabel($activity),
            'changes' => ActivityHistory::describe($activity),
            'restorable_fields' => $restorable ? array_map(fn (string $key): string => self::fieldLabel($key), $fields) : [],
            /* Il permesso `update` l'ha già chiesto l'elenco: qui resta la condizione della riga. */
            'abilities' => ['restore' => $restorable],
        ];
    }

    private static function fieldLabel(string $key): string
    {
        $label = __('admin.fields.'.$key);

        return $label === 'admin.fields.'.$key ? $key : $label;
    }
}
