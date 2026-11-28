<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\SocialStudioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Social\SocialBatchRequest;
use App\Http\Requests\Api\V1\Management\Social\SocialBatchStoreRequest;
use App\Http\Requests\Api\V1\Management\Social\SocialCatalogRequest;
use App\Http\Requests\Api\V1\Management\Social\SocialPreviewRequest;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Queries\EventOccurrenceQuery;
use App\Services\Management\ManagedAnalytics;
use App\Services\Management\ManagedContent;
use App\Services\Social\SocialBatchFiles;
use App\Services\Social\SocialCatalog;
use App\Services\Social\SocialStudioDesk;
use App\Services\Social\SocialStudioPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Le grafiche social nell'app (P10, W-SO-01…09 e la grafica di W-DT-12).
 *
 * Decisione 3 del piano: la scelta delle serate e le opzioni sono native,
 * le immagini le disegna il server con lo stesso `SocialStudio` del
 * pannello, e l'app le salva nella galleria o le condivide. Le regole che
 * stavano nella pagina (periodi, giorno di partenza, calendario, un solo
 * giorno per pacchetto, titolo solo con una serata, ultimi dodici pacchetti)
 * sono in `SocialStudioDesk`, che usa anche il pannello; i file in
 * `SocialBatchFiles`, che usa anche il sito.
 */
final class SocialStudioController extends Controller
{
    public function __construct(
        private readonly SocialCatalog $catalog,
        private readonly SocialStudioDesk $desk,
        private readonly SocialBatchFiles $files,
    ) {}

    /**
     * Le serate di un periodo a card (W-SO-01): oggi, settimana, trenta
     * giorni o dal/al, la ricerca e «mostra altre» (`limit`, 24 per volta,
     * fino a 240). Anche quelle annullate o rinviate, segnate come non
     * preparabili, come nella pagina.
     */
    public function catalog(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();
        $period = $request->string('period')->toString() ?: 'next30';
        [$from, $until] = SocialStudioDesk::periodBounds($city, $period, $request->string('from')->toString(), $request->string('until')->toString());
        [$from, $until] = SocialStudioDesk::clampRange($from, $until);
        $limit = min(max($request->integer('limit', SocialStudioDesk::PAGE_SIZE), 1), SocialStudioDesk::MAX_CARDS);
        $venueId = (int) $request->venue()->getKey();
        $eventId = $request->studioEventId();
        $search = $request->search();
        $cards = $this->catalog->period($city, $from, $until, $venueId, $eventId, true, $search, $limit);

        return response()->json([
            'data' => $cards->map(fn (EventOccurrence $date): array => SocialStudioPayload::card($date, $city->timezone))->values()->all(),
            'meta' => [
                'period' => $period,
                'from' => $from,
                'until' => $until,
                'today' => EventOccurrenceQuery::for($city)->currentBusinessDate(),
                'timezone' => $city->timezone,
                'total' => $this->catalog->total($city, $from, $until, $venueId, $eventId, true, $search),
                'limit' => $limit,
                'page_size' => SocialStudioDesk::PAGE_SIZE,
                'max_cards' => SocialStudioDesk::MAX_CARDS,
                'event' => $this->eventSummary($request),
            ],
        ]);
    }

    /** Il piccolo calendario del mese con il numero di serate di ogni giorno (W-SO-02). */
    public function calendar(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();
        $today = EventOccurrenceQuery::for($city)->currentBusinessDate();
        $selected = $request->string('date')->toString() ?: $today;
        $month = $request->string('month')->toString() ?: substr($selected, 0, 7);
        $days = $this->desk->calendar($city, $month, $selected, (int) $request->venue()->getKey(), $request->studioEventId(), true, $request->search());
        $first = CarbonImmutable::parse($month.'-01');

        return response()->json([
            'data' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'day' => $day['day'],
                'count' => $day['count'],
                'in_month' => $day['inMonth'],
                'today' => $day['today'],
                'selected' => $day['selected'],
            ], $days),
            'meta' => [
                'month' => $month,
                'label' => ucfirst($first->locale('it')->translatedFormat('F Y')),
                'previous' => $first->subMonth()->format('Y-m'),
                'next' => $first->addMonth()->format('Y-m'),
                'today' => $today,
            ],
        ]);
    }

    /**
     * Le serate che si possono preparare in un giorno (W-SO-03): sono quelle
     * fra cui si sceglie, e «seleziona tutte» le prende tutte. Senza `date`
     * è il giorno da cui la pagina si apre (W-SO-08 con `event`: la prossima
     * data dell'evento).
     */
    public function day(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();
        $venueId = (int) $request->venue()->getKey();
        $event = $request->studioEvent();
        $date = $request->string('date')->toString() ?: $this->desk->initialDate($city, $venueId, $event, true);
        $dates = $this->catalog->events($city, $date, $venueId, $event?->getKey(), true);

        return response()->json([
            'data' => $dates->map(fn (EventOccurrence $occurrence): array => SocialStudioPayload::card($occurrence, $city->timezone))->values()->all(),
            'meta' => [
                'date' => $date,
                'label' => ucfirst(CarbonImmutable::parse($date)->locale('it')->translatedFormat('l j F')),
                'today' => EventOccurrenceQuery::for($city)->currentBusinessDate(),
                'timezone' => $city->timezone,
                'max_selection' => SocialStudioDesk::MAX_SELECTION,
                'title_max' => SocialStudioDesk::TITLE_MAX,
                'event' => $this->eventSummary($request),
            ],
        ]);
    }

    /** Gli eventi del locale fra cui scegliere per restringere lo studio (il foglio «Scegli un evento»). */
    public function events(SocialCatalogRequest $request, ManagedAnalytics $analytics): JsonResponse
    {
        $search = $request->string('q')->toString();

        return response()->json(['data' => $analytics->choices(ManagedContent::current()->events(), $search !== '' ? $search : null)]);
    }

    /** «Prepara le grafiche selezionate» (W-SO-05): il pacchetto nuovo, già pronto da guardare. */
    public function store(SocialBatchStoreRequest $request): JsonResponse
    {
        try {
            $batch = $this->desk->prepare(
                $request->city(),
                (string) $request->validated('date'),
                $request->occurrenceIds(),
                $request->socialFormat(),
                $request->options(),
                $request->actor(),
                (int) $request->venue()->getKey(),
                $request->studioEventId(),
                true,
            );
        } catch (SocialStudioException $e) {
            // Solo gli errori previsti diventano un messaggio: un titolo che non ci sta, lo spazio su disco
            // (422), il tetto giornaliero del locale (429, col messaggio anche nel campo, che l'app mostra
            // al posto di «attendi un minuto»). Il resto va al gestore degli errori, senza dettagli al client.
            if ($e->dailyLimit) {
                throw new ApiException(ApiErrorCode::RateLimited, $e->getMessage(), ['generate' => [$e->getMessage()]]);
            }
            throw ValidationException::withMessages(['generate' => $e->getMessage()]);
        }

        return response()->json(['data' => SocialStudioPayload::batch($batch, $request->city()->timezone)], 201);
    }

    /** Gli ultimi dodici pacchetti del locale (W-SO-07). */
    public function batches(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();

        return response()->json([
            'data' => $this->desk->recent($city, (int) $request->venue()->getKey())
                ->map(fn (SocialBatch $batch): array => SocialStudioPayload::summary($batch))->values()->all(),
            'meta' => ['keep' => SocialStudioDesk::RECENT, 'timezone' => $city->timezone],
        ]);
    }

    /** Un pacchetto: le immagini (con l'indirizzo firmato del sito, valido sette giorni) e la didascalia. */
    public function show(SocialBatchRequest $request): JsonResponse
    {
        return response()->json(['data' => SocialStudioPayload::batch($request->batch(), $request->city()->timezone)]);
    }

    /** Un'immagine del pacchetto, per «Salva nella galleria» e «Condividi» (W-SO-06). */
    public function image(SocialBatchRequest $request): BinaryFileResponse
    {
        $batch = $request->batch();
        $index = (int) $request->route('index');
        $path = $this->files->image($batch, $index);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'attachment; filename="'.$this->files->imageName($batch, $index).'"',
        ]);
    }

    /** Lo ZIP con immagini e didascalie (W-SO-06), come «Scarica tutte le grafiche». */
    public function zip(SocialBatchRequest $request): BinaryFileResponse
    {
        $batch = $request->batch();
        try {
            $path = $this->files->zip($batch);
        } catch (RuntimeException) {
            abort(500);
        }
        abort_if($path === null, 404);

        return response()->download($path, $this->files->zipName($batch), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** L'anteprima JPEG della grafica di una data (W-SO-09, W-DT-12). */
    public function preview(SocialPreviewRequest $request): Response
    {
        $date = $request->occurrence();
        try {
            $bytes = $this->files->preview($date);
        } catch (SocialStudioException $e) {
            throw ValidationException::withMessages(['preview' => $e->getMessage()]);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg']);
    }

    /** @return array{id: int, title: string}|null */
    private function eventSummary(SocialCatalogRequest $request): ?array
    {
        $event = $request->studioEvent();

        return $event === null ? null : ['id' => (int) $event->getKey(), 'title' => $event->title];
    }
}
