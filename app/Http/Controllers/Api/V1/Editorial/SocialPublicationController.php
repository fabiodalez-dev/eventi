<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\SocialPublicationStatus;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Social\EditorialSocialRequest;
use App\Http\Requests\Api\V1\Editorial\Social\SocialScheduleRequest;
use App\Models\City;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Services\Social\EditorialPublishing;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * La pubblicazione sui social della redazione nell'app (R-SO-03, R-SO-04):
 * «Pubblica sui canali collegati», «Programma», lo storico con
 * «Usa la nuova data e ora», «Annulla programmazione» e «Riprova dopo aver
 * controllato». Le regole sono in `EditorialPublishing`, la stessa classe
 * della pagina «Social» del pannello.
 *
 * Gli errori di `SocialPublisher::enqueue` (collegamento non verificato,
 * storie, didascalia troppo lunga, evento cambiato) sono messaggi per chi
 * pubblica: 422 sul campo, come sotto il pulsante della pagina. Un errore
 * del database invece non diventa un messaggio: va al gestore degli errori.
 */
final class SocialPublicationController extends Controller
{
    use ListsPlaces;

    /** Lo storico della pagina ne mostra trenta; l'elenco dell'app li sfoglia a pagine. */
    private const PER_PAGE = EditorialPublishing::HISTORY;

    public function __construct(private readonly EditorialPublishing $publishing) {}

    /** Lo storico delle pubblicazioni della città, dalla più recente; `filter[status]`. */
    public function index(EditorialSocialRequest $request): JsonResponse
    {
        $city = $request->city();
        $query = $this->publishing->history($city);
        $status = $this->listFilters($request, ['status'])['status'] ?? null;
        if ($status !== null && SocialPublicationStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }
        $perPage = min(config()->integer('api.limits.max', 50), max(1, $request->integer('per_page', self::PER_PAGE)));
        $page = $query->paginate($perPage, page: $this->listPage($request));

        return response()->json($this->listPayload(
            $page,
            $page->getCollection()->map(fn (SocialPublication $publication): array => EditorialPublishing::row($publication, $city->timezone))->values()->all(),
        ));
    }

    /** «Pubblica sui canali collegati»: le grafiche del pacchetto escono subito, sui canali attivi e verificati. */
    public function publish(EditorialSocialRequest $request, string $batch): JsonResponse
    {
        $found = $this->batch($request, $batch);
        $this->enqueue('publish', fn () => $this->publishing->publish($found));

        return response()->json([
            'data' => $this->rowsOf($found),
            'meta' => ['message' => __('social.queued')],
        ]);
    }

    /** «Programma»: `scheduled_at` nel fuso della città del pacchetto, nel futuro. Un pacchetto già nello storico no. */
    public function schedule(SocialScheduleRequest $request, string $batch): JsonResponse
    {
        $found = $this->batch($request, $batch);
        $when = $this->publishing->when((string) $request->validated('scheduled_at'), $this->timezoneOf($found));
        $this->enqueue('scheduled_at', fn () => $this->publishing->schedule($found, $when));

        return response()->json([
            'data' => $this->rowsOf($found),
            'meta' => ['message' => __('social.schedule.done')],
        ], 201);
    }

    /** «Usa la nuova data e ora» di una pubblicazione ancora programmata. */
    public function reschedule(SocialScheduleRequest $request, int $publication): JsonResponse
    {
        $changed = $this->publishing->reschedule($publication, (string) $request->validated('scheduled_at'));

        return $this->rowResponse($publication, $changed ? __('social.schedule.updated') : null, $changed);
    }

    /** «Annulla programmazione», se la pubblicazione non è già partita. */
    public function cancel(EditorialSocialRequest $request, int $publication): JsonResponse
    {
        SocialPublication::query()->findOrFail($publication);
        $changed = $this->publishing->cancel($publication);

        return $this->rowResponse($publication, __('social.schedule.cancelled'), $changed);
    }

    /** «Riprova dopo aver controllato»: solo una non riuscita o incerta, con il pacchetto ancora presente. */
    public function retry(EditorialSocialRequest $request, int $publication): JsonResponse
    {
        $found = $this->publishing->retryable($publication);
        $changed = false;
        $this->enqueue('publish', function () use ($found, &$changed): void {
            $changed = $this->publishing->retry($found);
        });

        return $this->rowResponse($publication, null, $changed);
    }

    /** Un pacchetto qualsiasi, come `openBatch` della pagina: decide `SocialBatchPolicy::view`. */
    private function batch(EditorialSocialRequest $request, string $id): SocialBatch
    {
        $batch = SocialBatch::query()->findOrFail($id);
        Gate::forUser($request->actor())->authorize('view', $batch);

        return $batch;
    }

    /**
     * Un messaggio di `SocialPublisher` diventa un errore sul campo, come
     * sotto il pulsante della pagina; un errore del database no.
     */
    private function enqueue(string $field, callable $action): void
    {
        try {
            $action();
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    private function timezoneOf(SocialBatch $batch): string
    {
        $city = City::query()->find($batch->city_id);

        return $city instanceof City ? $city->timezone : config()->string('app.timezone');
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(SocialBatch $batch): array
    {
        $timezone = $this->timezoneOf($batch);

        return $batch->publications()->latest()->get()
            ->map(fn (SocialPublication $publication): array => EditorialPublishing::row($publication, $timezone))->values()->all();
    }

    private function rowResponse(int $id, ?string $message, bool $changed): JsonResponse
    {
        $publication = SocialPublication::query()->findOrFail($id);
        $city = City::query()->find(SocialConnection::query()->whereKey($publication->social_connection_id)->value('city_id'));
        $timezone = $city instanceof City ? $city->timezone : config()->string('app.timezone');

        return response()->json([
            'data' => EditorialPublishing::row($publication, $timezone),
            'meta' => ['message' => $message, 'changed' => $changed],
        ]);
    }
}
