<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\RemoveSavedOccurrence;
use App\Actions\Account\SaveOccurrences;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\Account\MergeSavedRequest;
use App\Http\Requests\Web\Account\StoreSavedRequest;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Calendar\OccurrenceCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Le date salvate sul sito (§15.1 e §15.3).
 *
 * Ogni azione risponde in due modi perché il cuore funziona in due modi: come
 * modulo, se il browser non esegue lo script, e come chiamata asincrona
 * altrimenti. La regola di §11.11 vale anche qui — **niente è necessario**: il
 * modulo funziona da solo, lo script gli toglie il ricaricamento di pagina.
 */
final class SavedController extends Controller
{
    use InteractsWithAccount;
    use InteractsWithCity;

    public function __construct(private readonly OccurrenceCalendar $calendar) {}

    public function index(Request $request): View
    {
        $city = $this->city();
        $user = $this->accountUser($request);
        $past = $request->boolean('passate');
        $calendarView = $request->string('vista')->toString() === 'calendario';

        $query = EventOccurrenceQuery::for($city)->savedBy($user);

        $query->ended($past);
        if ($past) {
            $query->orderByNewestFirst();
        }

        $occurrences = $query->paginate(config()->integer('account.feed_per_page'))->withQueryString();

        /** @var Collection<int, EventOccurrence> $items */
        $items = new Collection($occurrences->items());
        $items->load(['event.city', 'event.venue', 'event.category', 'event.media']);

        $month = CarbonImmutable::now($city->timezone)->startOfMonth();
        $requestedMonth = $request->string('mese')->toString();

        if (preg_match('/^\d{4}-\d{2}$/', $requestedMonth) === 1) {
            try {
                $month = CarbonImmutable::parse($requestedMonth.'-01', $city->timezone)->startOfMonth();
            } catch (\Throwable) {
                // Un mese non valido torna semplicemente al mese corrente.
            }
        }

        /** @var Collection<int, EventOccurrence> $calendarOccurrences */
        $calendarOccurrences = new Collection;

        if ($calendarView) {
            $calendarOccurrences = EventOccurrenceQuery::archiveFor($city)
                ->savedBy($user)
                ->ended(false)
                ->between($month, $month->endOfMonth())
                ->get();
            $calendarOccurrences->load(['event.city', 'event.venue', 'event.category', 'event.media']);
        }

        return view('account.saved', [
            'occurrences' => $occurrences,
            'past' => $past,
            'calendarView' => $calendarView,
            'calendarOccurrences' => $calendarOccurrences,
            'month' => $month,
            'calendar' => $this->calendar,
            'meta' => new PageMeta(
                title: __('account.saved.title'),
                heading: __('account.saved.title'),
                description: __('account.saved.lead'),
                indexable: false,
            ),
        ]);
    }

    public function store(StoreSavedRequest $request, SaveOccurrences $save): RedirectResponse|JsonResponse
    {
        $saved = $save->many($this->accountUser($request), $this->city(), $request->occurrenceIds());

        $message = $saved->isEmpty() ? __('account.save.not_savable') : __('account.save.stored');

        /** @var list<int> $ids */
        $ids = $saved->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return $this->respond($request, ['saved' => $ids, 'message' => $message], $message);
    }

    public function destroy(Request $request, int $occurrence, RemoveSavedOccurrence $remove): RedirectResponse|JsonResponse
    {
        $removed = $remove($this->accountUser($request), $occurrence);

        return $this->respond(
            $request,
            ['removed' => $removed, 'message' => __('account.save.removed')],
            __('account.save.removed'),
        );
    }

    /**
     * La migrazione dei salvataggi fatti da anonimo (§15.1). La chiede il
     * browser subito dopo l'accesso, e la risposta dice quante date sono
     * entrate: è quel numero che autorizza a svuotare il `localStorage`.
     */
    public function merge(MergeSavedRequest $request, SaveOccurrences $save): JsonResponse
    {
        $ids = $request->occurrenceIds();
        $saved = $save->many($this->accountUser($request), $this->city(), $ids);

        /** @var list<int> $merged */
        $merged = $saved->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return response()->json([
            'merged' => count($merged),
            'ignored' => count(array_unique($ids)) - count($merged),
            'occurrence_ids' => $merged,
            'message' => trans_choice('account.save.merged', count($merged), ['count' => count($merged)]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(Request $request, array $payload, string $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return back()->with('status', $status);
    }
}
