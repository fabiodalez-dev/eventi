<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Exceptions\SocialStudioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Social\EditorialSocialRequest;
use App\Http\Requests\Api\V1\Editorial\Social\SocialBatchStoreRequest;
use App\Http\Requests\Api\V1\Editorial\Social\SocialCatalogRequest;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Queries\EventOccurrenceQuery;
use App\Services\Social\SocialBatchFiles;
use App\Services\Social\SocialCatalog;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStudioDesk;
use App\Services\Social\SocialStudioPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lo studio delle grafiche social della redazione nell'app (R9: R-SO-01,
 * R-SO-02, R-DB-10, R-WB-03), come la pagina «Social» del pannello `/admin`
 * e il riquadro «Social di oggi» del riepilogo.
 *
 * Le regole sono quelle dello studio del locale (P10), già in
 * `SocialStudioDesk`, con le due differenze della pagina della redazione:
 * nessun locale (tutte le serate della città) e niente eventi
 * dimostrativi. Le immagini le disegna il server con `SocialStudio`, i file
 * li prepara `SocialBatchFiles`, come per il sito.
 */
final class SocialStudioController extends Controller
{
    public function __construct(
        private readonly SocialCatalog $catalog,
        private readonly SocialStudioDesk $desk,
        private readonly SocialBatchFiles $files,
    ) {}

    /** Le serate di un periodo a card: oggi, settimana, trenta giorni o dal/al, ricerca, «mostra altre». */
    public function catalog(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();
        $period = $request->string('period')->toString() ?: 'next30';
        [$from, $until] = SocialStudioDesk::periodBounds($city, $period, $request->string('from')->toString(), $request->string('until')->toString());
        [$from, $until] = SocialStudioDesk::clampRange($from, $until);
        $limit = min(max($request->integer('limit', SocialStudioDesk::PAGE_SIZE), 1), SocialStudioDesk::MAX_CARDS);
        $eventId = $request->studioEventId();
        $search = $request->search();
        $cards = $this->catalog->period($city, $from, $until, null, $eventId, false, $search, $limit);

        return response()->json([
            'data' => $cards->map(fn (EventOccurrence $date): array => SocialStudioPayload::card($date, $city->timezone))->values()->all(),
            'meta' => [
                'period' => $period,
                'from' => $from,
                'until' => $until,
                'today' => EventOccurrenceQuery::for($city)->currentBusinessDate(),
                'timezone' => $city->timezone,
                'total' => $this->catalog->total($city, $from, $until, null, $eventId, false, $search),
                'limit' => $limit,
                'page_size' => SocialStudioDesk::PAGE_SIZE,
                'max_cards' => SocialStudioDesk::MAX_CARDS,
                'city' => self::city($city),
                'event' => self::eventSummary($request),
            ],
        ]);
    }

    /** Il piccolo calendario del mese con il numero di serate di ogni giorno. */
    public function calendar(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();
        $today = EventOccurrenceQuery::for($city)->currentBusinessDate();
        $selected = $request->string('date')->toString() ?: $today;
        $month = $request->string('month')->toString() ?: substr($selected, 0, 7);
        $days = $this->desk->calendar($city, $month, $selected, null, $request->studioEventId(), false, $request->search());
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
     * Le serate che si possono preparare in un giorno. Senza `date` è il
     * giorno da cui la pagina si apre: oggi, con `event` la sua prossima
     * data, e se lì non c'è niente la prima serata in programma.
     */
    public function day(SocialCatalogRequest $request): JsonResponse
    {
        $city = $request->city();
        $event = $request->studioEvent();
        $date = $request->string('date')->toString() ?: $this->desk->initialDate($city, null, $event, false);
        $dates = $this->catalog->events($city, $date, null, $event?->getKey(), false);

        return response()->json([
            'data' => $dates->map(fn (EventOccurrence $occurrence): array => SocialStudioPayload::card($occurrence, $city->timezone))->values()->all(),
            'meta' => [
                'date' => $date,
                'label' => ucfirst(CarbonImmutable::parse($date)->locale('it')->translatedFormat('l j F')),
                'today' => EventOccurrenceQuery::for($city)->currentBusinessDate(),
                'timezone' => $city->timezone,
                'max_selection' => SocialStudioDesk::MAX_SELECTION,
                'title_max' => SocialStudioDesk::TITLE_MAX,
                'city' => self::city($city),
                'event' => self::eventSummary($request),
            ],
        ]);
    }

    /** «Social di oggi» del riepilogo (R-DB-10): le serate di oggi della città, con l'anteprima di ciascuna. */
    public function today(EditorialSocialRequest $request): JsonResponse
    {
        $city = $request->city();
        $date = EventOccurrenceQuery::for($city)->currentBusinessDate();

        return response()->json([
            'data' => $this->desk->today($city)->map(fn (EventOccurrence $occurrence): array => SocialStudioPayload::card($occurrence, $city->timezone))->values()->all(),
            'meta' => [
                'date' => $date,
                'label' => ucfirst(CarbonImmutable::parse($date)->locale('it')->translatedFormat('l j F')),
                'timezone' => $city->timezone,
                'city' => self::city($city),
            ],
        ]);
    }

    /**
     * «Scarica le grafiche di oggi»: il pacchetto verticale con tutte le
     * serate di oggi e lo stile predefinito. Il riquadro del pannello porta
     * subito allo ZIP; l'app riceve il pacchetto e lo ZIP lo scarica da
     * `batches/{batch}/zip`.
     */
    public function prepareToday(EditorialSocialRequest $request): JsonResponse
    {
        $city = $request->city();
        try {
            $batch = $this->desk->prepareToday($city, $request->actor());
        } catch (SocialStudioException $e) {
            throw ValidationException::withMessages(['generate' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->batchPayload($batch)], 201);
    }

    /** L'anteprima JPEG della grafica di una serata (`EventPolicy::update`, come `social.preview` del sito). */
    public function preview(EditorialSocialRequest $request, int $occurrence): Response
    {
        $date = EventOccurrence::query()->with('event')->findOrFail($occurrence);
        Gate::forUser($request->actor())->authorize('update', $date->event);
        try {
            $bytes = $this->files->preview($date);
        } catch (SocialStudioException $e) {
            throw ValidationException::withMessages(['preview' => $e->getMessage()]);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg']);
    }

    /** Gli ultimi dodici pacchetti della redazione nella città. */
    public function batches(EditorialSocialRequest $request): JsonResponse
    {
        $city = $request->city();

        return response()->json([
            'data' => $this->desk->recent($city, null)->map(fn (SocialBatch $batch): array => SocialStudioPayload::summary($batch))->values()->all(),
            'meta' => ['keep' => SocialStudioDesk::RECENT, 'timezone' => $city->timezone, 'city' => self::city($city)],
        ]);
    }

    /** «Prepara le grafiche selezionate»: il pacchetto nuovo, già pronto da guardare. */
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
                null,
                $request->studioEventId(),
                false,
            );
        } catch (SocialStudioException $e) {
            // Solo gli errori previsti (un titolo che non ci sta, lo spazio su disco) diventano un messaggio.
            throw ValidationException::withMessages(['generate' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->batchPayload($batch)], 201);
    }

    /**
     * Un pacchetto: le immagini, la didascalia e, come nella pagina della
     * redazione, il testo automatico che uscirebbe pubblicandolo (`captions`,
     * una per parte del carosello) e se è già nello storico.
     */
    public function show(EditorialSocialRequest $request, string $batch): JsonResponse
    {
        return response()->json(['data' => $this->batchPayload($this->batch($request, $batch))]);
    }

    /** Un'immagine del pacchetto, per «Salva nella galleria» e «Condividi». */
    public function image(EditorialSocialRequest $request, string $batch, int $index): BinaryFileResponse
    {
        $found = $this->batch($request, $batch);
        $path = $this->files->image($found, $index);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'attachment; filename="'.$this->files->imageName($found, $index).'"',
        ]);
    }

    /** Lo ZIP con immagini e didascalie, come «Scarica tutte le grafiche». */
    public function zip(EditorialSocialRequest $request, string $batch): BinaryFileResponse
    {
        $found = $this->batch($request, $batch);
        try {
            $path = $this->files->zip($found);
        } catch (RuntimeException) {
            abort(500);
        }
        abort_if($path === null, 404);

        return response()->download($path, $this->files->zipName($found), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Un pacchetto qualsiasi, come `openBatch` della pagina: decide `SocialBatchPolicy::view`. */
    private function batch(EditorialSocialRequest $request, string $id): SocialBatch
    {
        $batch = SocialBatch::query()->findOrFail($id);
        Gate::forUser($request->actor())->authorize('view', $batch);

        return $batch;
    }

    /** @return array<string, mixed> */
    private function batchPayload(SocialBatch $batch): array
    {
        $city = City::query()->find($batch->city_id);
        $timezone = $city instanceof City ? $city->timezone : config()->string('app.timezone');

        return [
            ...SocialStudioPayload::batch($batch, $timezone),
            'city' => $city instanceof City ? self::city($city) : null,
            'captions' => $batch->venue_id === null ? app(SocialPublisher::class)->captions($batch) : [],
            'in_history' => $batch->publications()->exists(),
        ];
    }

    /** @return array{id: int, slug: string, name: string} */
    private static function city(City $city): array
    {
        return ['id' => (int) $city->getKey(), 'slug' => (string) $city->slug, 'name' => (string) $city->name];
    }

    /** @return array{id: int, title: string}|null */
    private static function eventSummary(EditorialSocialRequest $request): ?array
    {
        $event = $request->studioEvent();

        return $event === null ? null : ['id' => (int) $event->getKey(), 'title' => $event->title];
    }
}
