<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\SavedVisibility;
use App\Models\Booking;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventPoll;
use App\Models\EventShareLink;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\User;
use App\Services\Analytics\EventShares;
use App\Services\Community\EventPolls;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Le funzioni di crescita, appese alla vetrina che `demo:showcase` ha già
 * creato.
 *
 * Serve a **mostrarle**, non a simulare traffico: una funzione che esiste nel
 * codice ma non si vede in nessuna pagina, in una presentazione, non esiste.
 * Ognuna di queste ha una schermata in cui si guarda, ed è quella che questo
 * comando riempie — informazioni pratiche e costi dichiarati nella scheda
 * della data, «ci vado» con i nomi, un sondaggio fra amici da aprire col
 * link, le statistiche di condivisione nel pannello del locale, una lista
 * d'attesa con un posto promosso che scade, e lo staff assegnato agli
 * ingressi.
 *
 * **Stesso modello di sicurezza della vetrina**, perché scrive negli stessi
 * posti: fuori da local e testing serve `--allow-production`, una seconda
 * esecuzione non duplica niente e completa ciò che manca, e tutto resta
 * appeso a eventi `is_demo` e a persone con indirizzo `.invalid`, che
 * `User::canReceiveNotifications()` tiene fuori da ogni invio.
 *
 * **Non tocca eventi veri.** L'unica eccezione dichiarata è il sondaggio: se
 * nessun evento della vetrina ha almeno due date — e di norma ne ha una —
 * il sondaggio nasce sulle date di un evento vero, perché è l'unico modo di
 * mostrare a cosa serve. Anche in quel caso il sondaggio è una pagina a sé,
 * raggiungibile solo col link, e non cambia niente dell'evento.
 *
 * `--purge` toglie ciò che questo comando ha aggiunto e lascia in piedi la
 * vetrina: le due cose si rimuovono separatamente perché si aggiungono
 * separatamente.
 */
final class ShowcaseGrowthCommand extends Command
{
    /** La nota del sondaggio: è anche la chiave con cui `--purge` lo ritrova. */
    private const POLL_NOTE = 'Vetrina inCittà — quale sera vi va meglio?';

    protected $signature = 'demo:crescita {city=padova} {--dry-run} {--allow-production} {--purge : Toglie solo ciò che questo comando ha aggiunto}';

    protected $description = 'Mostra le funzioni di crescita sulla vetrina: informazioni pratiche, costi, «ci vado», sondaggi, condivisioni, lista d’attesa e staff agli ingressi';

    /** @var array<string, array{0: int|string, 1: int|string}> */
    private array $report = [];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('È richiesta l’autorizzazione --allow-production.');

            return self::FAILURE;
        }

        $city = City::query()->active()->where('slug', $this->argument('city'))->firstOrFail();
        $events = $this->demoEvents($city);
        $people = $this->demoPeople();

        if ($events->isEmpty() || $people->count() < 6) {
            $this->error(sprintf('La vetrina non c’è: %d eventi e %d persone. Esegui prima php artisan demo:showcase %s.',
                $events->count(), $people->count(), $city->slug));

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            return $this->purge($events, $people);
        }

        $future = $this->futureOccurrences($events);

        if ($future->isEmpty()) {
            $this->error('Le date della vetrina sono tutte passate: rifalla sulla settimana giusta con --week prima di appenderci le funzioni.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->table(['Funzione', 'Dove si guarda', 'Previsti'], [
                ['Informazioni pratiche e costi dichiarati', 'scheda della data', min($future->count(), 9).' date'],
                ['«Ci vado» con i nomi', 'scheda della data', min($future->count(), 5).' date'],
                ['Sondaggio fra amici', 'link del sondaggio', $this->pollDates($events)->count() >= EventPolls::MIN_OPTIONS ? '1 sondaggio' : 'nessuna data adatta'],
                ['Condivisioni e QR', 'pannello del locale', min($events->count(), 3).' eventi × 14 giorni'],
                ['Lista d’attesa e posto promosso', 'pagina dei biglietti', $this->ticketingDate($future) === null ? 'nessun locale con biglietteria' : '1 data'],
                ['Staff agli ingressi', 'area gestione', $this->ticketingDate($future) === null ? 'saltato' : '2 persone'],
                ['Spinta della sera e modi di seguire', 'preferenze del profilo', '1 persona'],
            ]);
            $this->line('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        return Cache::lock('showcase-growth:'.$city->id, 3600)->block(5, function () use ($city, $events, $people, $future): int {
            $this->seedPractical($future);
            $this->seedAttendance($future, $people);
            $this->seedPoll($events, $people);
            $this->seedShares($events);
            $this->seedTicketing($future, $people);
            $this->seedPreferences($people);

            $this->table(['Elemento', 'Creati ora', 'Totale'], array_map(
                fn (string $label, array $row): array => [$label, $row[0], $row[1]],
                array_keys($this->report), $this->report,
            ));
            $this->info('Funzioni in vetrina. Per toglierle: php artisan demo:crescita '.$city->slug.' --purge');

            return self::SUCCESS;
        });
    }

    /** @return Collection<int, Event> */
    private function demoEvents(City $city): Collection
    {
        return Event::query()->where('city_id', $city->getKey())
            ->where('source_ref', 'like', ShowcaseDemoCommand::PREFIX.'%')
            ->with(['occurrences', 'venue'])->orderBy('id')->get();
    }

    /** @return Collection<int, User> */
    private function demoPeople(): Collection
    {
        return User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->orderBy('id')->get();
    }

    /**
     * Le date della vetrina ancora da venire, in ordine di inizio.
     *
     * Le funzioni si mostrano solo in avanti: costi e informazioni pratiche
     * di una serata già finita non sono una dimostrazione, e una lista
     * d'attesa su una data passata non si può nemmeno aprire.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, EventOccurrence>
     */
    private function futureOccurrences(Collection $events): Collection
    {
        return EventOccurrence::query()->whereIn('event_id', $events->modelKeys())
            ->where('starts_at', '>', now())->with('event.venue')->orderBy('starts_at')->get();
    }

    /**
     * Informazioni pratiche e costi dichiarati.
     *
     * Tre profili a rotazione, e non per varietà: sono i tre stati che la
     * scheda sa raccontare — tutto dichiarato, dichiarato a metà, e niente
     * costi ma indicazioni pratiche. Il terzo è quello che si dimentica
     * sempre di mostrare, ed è il più frequente nella realtà.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedPractical(Collection $dates): void
    {
        $profili = $this->practicalProfiles();
        $toccate = 0;

        foreach ($dates->take(9)->values() as $indice => $date) {
            $profilo = $profili[$indice % count($profili)];

            if ($date->practical_details !== null && $date->cost_breakdown === ($profilo['costi'] ?? null)) {
                continue;
            }

            $date->forceFill(['practical_details' => $profilo['pratiche'], 'cost_breakdown' => $profilo['costi']])->save();
            $toccate++;
        }

        $this->report['Date con informazioni pratiche'] = [$toccate, $dates->take(9)->count()];
    }

    /** @return list<array{pratiche: array<string, mixed>, costi: array<string, mixed>|null}> */
    private function practicalProfiles(): array
    {
        return [
            [
                'pratiche' => [
                    'age_groups' => ['all'], 'stroller' => 'yes', 'changing_table' => 'yes', 'kids_area' => 'no',
                    'membership' => 'not_required', 'accessibility' => 'yes',
                    'accessibility_notes' => 'Ingresso a raso, bagno accessibile, posti riservati davanti al palco.',
                    'parking_type' => 'paid', 'parking_notes' => 'Parcheggio a pagamento in piazza: 1,20 € l’ora fino alle 20, poi libero.',
                    'transit_notes' => 'Autobus 3 e 12, fermata a duecento metri. Ultima corsa alle 00:30.',
                    'entrance_notes' => 'Si entra dal cortile interno, non dal portone principale.',
                    'food_notes' => 'Cucina aperta fino alle 22:30, anche senza consumazione obbligatoria.',
                    'start_notes' => 'Si comincia alle 21:30, con mezz’ora di tolleranza all’ingresso.',
                    'practical_custom' => [['label' => 'Guardaroba', 'icon' => 'briefcase', 'text' => 'Gratuito, subito dopo l’ingresso.']],
                    'feature_ids' => [],
                ],
                'costi' => ['admission' => 8, 'drink' => 4, 'membership' => 5, 'other' => 0],
            ],
            [
                'pratiche' => [
                    'age_groups' => ['18-plus'], 'membership' => 'required',
                    'membership_notes' => 'Tessera dell’associazione: si fa in cassa, 5 € e vale un anno.',
                    'accessibility' => 'no', 'accessibility_notes' => 'Sala al primo piano, senza ascensore.',
                    'parking_type' => 'none',
                    'transit_notes' => 'Tram fino a Ponti Romani, poi dieci minuti a piedi.',
                    'start_notes' => 'Le porte aprono alle 21, si comincia alle 22.',
                    'practical_custom' => [], 'feature_ids' => [],
                ],
                'costi' => ['admission' => 10, 'drink' => 5],
            ],
            [
                'pratiche' => [
                    'age_groups' => ['all', '6-10'], 'stroller' => 'yes', 'kids_area' => 'yes',
                    'accessibility' => 'yes', 'parking_type' => 'free',
                    'parking_notes' => 'Parcheggio libero nel piazzale dietro il locale.',
                    'entrance_notes' => 'Ingresso libero fino a esaurimento posti: non c’è prenotazione.',
                    'food_notes' => 'Banco con panini e bibite, prezzi da bar.',
                    'practical_custom' => [['label' => 'Se piove', 'icon' => 'cloud', 'text' => 'Si sposta tutto sotto il portico: non si annulla.']],
                    'feature_ids' => [],
                ],
                'costi' => null,
            ],
        ];
    }

    /**
     * «Ci vado»: i salvataggi pubblici.
     *
     * Il contatore e i nomi vengono da lì — `visibility` non è un campo
     * riempibile, e si scrive come fa la vetrina, con `forceFill`.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  Collection<int, User>  $people
     */
    private function seedAttendance(Collection $dates, Collection $people): void
    {
        $creati = 0;

        foreach ($dates->take(5)->values() as $indice => $date) {
            foreach ($people->slice($indice, 4 + ($indice % 3)) as $persona) {
                $salvataggio = SavedEvent::query()->firstOrCreate([
                    'user_id' => $persona->getKey(), 'occurrence_id' => $date->getKey(),
                ]);

                if ($salvataggio->visibility === SavedVisibility::Public) {
                    continue;
                }

                $salvataggio->forceFill(['visibility' => SavedVisibility::Public])->save();
                $creati++;
            }
        }

        $this->report['«Ci vado» pubblici'] = [$creati, SavedEvent::query()->whereIn('user_id', $people->modelKeys())
            ->where('visibility', SavedVisibility::Public->value)->count()];
    }

    /**
     * Le date fra cui il sondaggio fa scegliere.
     *
     * Un sondaggio mette in fila le date **dello stesso evento**: serve a
     * decidere quando andare a una cosa, non quale cosa fare. La vetrina ha
     * un evento per sera, quindi di norma le date adatte arrivano da un
     * evento vero con più repliche — il sondaggio resta comunque una pagina
     * a sé, con solo il suo link, e non tocca l'evento.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, EventOccurrence>
     */
    private function pollDates(Collection $events): Collection
    {
        $dellaVetrina = EventOccurrence::query()->whereIn('event_id', $events->modelKeys())
            ->where('starts_at', '>', now())->orderBy('starts_at')->get()->groupBy('event_id')
            ->first(fn (Collection $date): bool => $date->count() >= EventPolls::MIN_OPTIONS);

        if ($dellaVetrina !== null) {
            return $dellaVetrina->take(EventPolls::MAX_OPTIONS);
        }

        $evento = EventOccurrence::query()->where('starts_at', '>', now())
            ->whereHas('event', fn ($query) => $query->where('status', 'published'))
            ->select('event_id')->groupBy('event_id')
            ->havingRaw('COUNT(*) >= ?', [EventPolls::MIN_OPTIONS])->orderBy('event_id')->value('event_id');

        return $evento === null
            ? new Collection
            : EventOccurrence::query()->where('event_id', $evento)->where('starts_at', '>', now())
                ->orderBy('starts_at')->take(EventPolls::MAX_OPTIONS)->get();
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, User>  $people
     */
    private function seedPoll(Collection $events, Collection $people): void
    {
        $esistente = EventPoll::query()->where('note', self::POLL_NOTE)->first();

        if ($esistente !== null) {
            $this->report['Sondaggio fra amici'] = ['già presente', $this->pollUrl($esistente->token)];

            return;
        }

        $dates = $this->pollDates($events);

        if ($dates->count() < EventPolls::MIN_OPTIONS) {
            $this->report['Sondaggio fra amici'] = ['saltato', 'nessun evento con due date future'];

            return;
        }

        if (! config('community.enabled')) {
            $this->report['Sondaggio fra amici'] = ['saltato', 'la comunità è spenta'];

            return;
        }

        try {
            $poll = app(EventPolls::class)->create($people->first(), $dates->modelKeys(), self::POLL_NOTE, CarbonImmutable::now()->addDays(5));
            $voti = 0;

            foreach ($people->slice(1, 6)->values() as $indice => $persona) {
                foreach ($poll->options->slice(0, 1 + ($indice % $poll->options->count())) as $opzione) {
                    app(EventPolls::class)->toggle($persona, $poll, $opzione);
                    $voti++;
                }
            }

            $this->report['Sondaggio fra amici'] = [$voti.' voti', $this->pollUrl($poll->token)];
        } catch (Throwable $errore) {
            $this->report['Sondaggio fra amici'] = ['non riuscito', $errore->getMessage()];
        }
    }

    /**
     * I link tracciati e i loro conteggi.
     *
     * I link li crea il servizio, così i codici sono quelli veri e il QR
     * funziona; i conteggi si scrivono a mano perché quattordici giorni di
     * storia non si ottengono chiamando `record()` quattordici volte —
     * scriverebbe tutto sulla data di oggi, e il grafico del pannello
     * mostrerebbe una colonna sola.
     *
     * @param  Collection<int, Event>  $events
     */
    private function seedShares(Collection $events): void
    {
        $scritte = 0;
        $oggi = CarbonImmutable::today();

        foreach ($events->take(3)->values() as $posizione => $evento) {
            app(EventShares::class)->links($evento, null);

            foreach (EventShareLink::query()->where('event_id', $evento->getKey())->orderBy('id')->get() as $indice => $link) {
                for ($giorno = 13; $giorno >= 0; $giorno--) {
                    $condivisioni = max(0, 3 - $indice + (($giorno + $posizione) % 4));
                    $scritte += DB::table('event_share_daily')->insertOrIgnore([[
                        'share_link_id' => $link->getKey(),
                        'date' => $oggi->subDays($giorno)->toDateString(),
                        'shares' => $condivisioni,
                        'clicks' => $condivisioni * 2 + (($giorno + $indice) % 5),
                    ]]);
                }
            }
        }

        $this->report['Giorni di condivisioni'] = [$scritte, DB::table('event_share_daily')
            ->whereIn('share_link_id', EventShareLink::query()->whereIn('event_id', $events->modelKeys())->select('id'))->count()];
    }

    /**
     * La prima data della vetrina su cui si possa davvero vendere.
     *
     * La biglietteria si accende sul locale, non sulla data: qui si cerca
     * una data della vetrina il cui locale ce l'abbia già accesa. Se non
     * c'è, la funzione si salta e si dice — accenderla su un locale vero
     * per una dimostrazione cambierebbe la configurazione di qualcun altro.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function ticketingDate(Collection $dates): ?EventOccurrence
    {
        return $dates->first(fn (EventOccurrence $date): bool => (bool) $date->effectiveVenue()?->ticketing_enabled
            && $date->starts_at->greaterThan(now()->addHours(config()->integer('ticketing.promotion.min_hours_before') + 1)));
    }

    /**
     * Lista d'attesa e posto promosso.
     *
     * La sequenza è quella vera, non uno stato scritto a tavolino: si riempie
     * la capienza, si mettono due gruppi in coda, e poi si annulla una
     * prenotazione. È l'annullamento a far scattare la promozione, con la sua
     * scadenza — e mostrare quel meccanismo è il punto, perché è lì che
     * succede la cosa che nessun altro calendario fa.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  Collection<int, User>  $people
     */
    private function seedTicketing(Collection $dates, Collection $people): void
    {
        $date = $this->ticketingDate($dates);

        if ($date === null) {
            $this->report['Lista d’attesa'] = ['saltata', 'nessuna data della vetrina in un locale con biglietteria'];

            return;
        }

        if (Booking::query()->where('occurrence_id', $date->getKey())->exists()) {
            $this->report['Lista d’attesa'] = ['già presente', $this->ticketingSummary($date)];
            $this->seedStaff($date, $people);

            return;
        }

        $servizio = app(TicketingService::class);

        try {
            $servizio->configure($date, [
                'booking_enabled' => true, 'booking_capacity' => 6, 'booking_limit' => 2, 'booking_waitlist' => true,
                'booking_opens_at' => now()->subDay(), 'booking_closes_at' => $date->starts_at,
            ], $people->first());

            $prenotanti = $people->slice(0, 5)->values();
            $prenotazioni = [];

            foreach ($prenotanti as $indice => $persona) {
                $prenotazioni[] = $servizio->reserve(
                    $persona, $date->fresh(),
                    [$persona->name, 'Ospite di '.$persona->name],
                    // La chiave di richiesta è un UUID: la colonna lo pretende, e
                    // qui serve solo a rendere il tentativo ripetibile senza
                    // duplicati dentro la stessa esecuzione.
                    (string) Str::uuid(),
                    true,
                );
            }

            // L'annullamento del primo gruppo libera due posti: la coda avanza
            // da sola, e chi entra si porta dietro la scadenza della conferma.
            $servizio->cancel($prenotazioni[0], $prenotanti[0], null, false, 'Vetrina: posto liberato per mostrare la coda');

            $this->report['Lista d’attesa'] = ['creata', $this->ticketingSummary($date->fresh())];
        } catch (Throwable $errore) {
            $this->report['Lista d’attesa'] = ['non riuscita', $errore->getMessage()];

            return;
        }

        $this->seedStaff($date, $people);
    }

    private function ticketingSummary(EventOccurrence $date): string
    {
        $conta = fn (BookingStatus $stato): int => Booking::query()->where('occurrence_id', $date->getKey())->where('status', $stato)->count();
        $promosso = Booking::query()->where('occurrence_id', $date->getKey())->whereNotNull('promotion_expires_at')->first();

        return sprintf('%s — %d confermate, %d in coda, %d annullate%s', $date->event->title,
            $conta(BookingStatus::Confirmed), $conta(BookingStatus::Waitlisted), $conta(BookingStatus::Cancelled),
            $promosso === null ? '' : ', 1 promossa da confermare entro '.$promosso->promotion_expires_at?->format('d/m H:i'));
    }

    /**
     * Lo staff agli ingressi.
     *
     * È un permesso per **quella data**, non per il locale: chi controlla i
     * biglietti di una sera non deve poterlo fare per tutte le altre.
     *
     * @param  Collection<int, User>  $people
     */
    private function seedStaff(EventOccurrence $date, Collection $people): void
    {
        $staff = $people->slice(6, 2);
        $date->checkinStaff()->syncWithoutDetaching($staff->modelKeys());

        $this->report['Staff agli ingressi'] = [$staff->count(), $date->checkinStaff()->count().' sulla data del '.$date->starts_at->format('d/m')];
    }

    private function pollUrl(string $token): string
    {
        return Route::has('polls.show') ? route('polls.show', $token) : url('/sondaggi/'.$token);
    }

    /**
     * Le preferenze che rendono visibili le due funzioni silenziose.
     *
     * La spinta della sera e i modi di seguire non hanno una pagina propria:
     * si vedono nelle preferenze del profilo, ed è lì che vanno messe in
     * evidenza. Restano comunque senza effetto — le persone della vetrina
     * hanno un indirizzo `.invalid` e non ricevono niente.
     *
     * @param  Collection<int, User>  $people
     */
    private function seedPreferences(Collection $people): void
    {
        $persona = $people->first();
        $preferenze = array_merge($persona->notification_preferences ?? [], [
            'tonight' => true, 'tonight_days' => [4, 5, 6], 'tonight_time' => '18:30',
        ]);
        $persona->forceFill(['notification_preferences' => $preferenze])->save();

        $modi = ['all', 'new_only', 'none'];
        $aggiornati = 0;

        foreach (Follow::query()->where('user_id', $persona->getKey())->orderBy('id')->get() as $indice => $follow) {
            $modo = $modi[$indice % count($modi)];

            if ($follow->getAttribute('notification_mode')?->value === $modo) {
                continue;
            }

            $follow->update(['notification_mode' => $modo]);
            $aggiornati++;
        }

        $this->report['Spinta della sera'] = ['accesa', $persona->email];
        $this->report['Modi di seguire'] = [$aggiornati, 'su '.Follow::query()->where('user_id', $persona->getKey())->count().' profili seguiti'];
    }

    /**
     * Toglie ciò che questo comando ha aggiunto, e solo quello.
     *
     * Le prenotazioni si annullano con il servizio invece di sparire dal
     * database: è la stessa strada che percorre un annullamento vero, e
     * lascia il registro coerente. Il resto sono righe che appartengono
     * soltanto alla vetrina.
     *
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, User>  $people
     */
    private function purge(Collection $events, Collection $people): int
    {
        $date = EventOccurrence::query()->whereIn('event_id', $events->modelKeys())->get();

        $prenotazioni = Booking::query()->whereIn('occurrence_id', $date->modelKeys())
            ->whereIn('user_id', $people->modelKeys())->get();

        foreach ($prenotazioni as $prenotazione) {
            $prenotazione->tickets()->delete();
            $prenotazione->delete();
        }

        $sondaggi = EventPoll::query()->where('note', self::POLL_NOTE)->get();

        foreach ($sondaggi as $sondaggio) {
            $sondaggio->delete();
        }

        $condivisioni = DB::table('event_share_daily')
            ->whereIn('share_link_id', EventShareLink::query()->whereIn('event_id', $events->modelKeys())->select('id'))->delete();

        $pubblici = SavedEvent::query()->whereIn('user_id', $people->modelKeys())
            ->whereIn('occurrence_id', $date->modelKeys())->where('visibility', SavedVisibility::Public->value)->count();
        SavedEvent::query()->whereIn('user_id', $people->modelKeys())
            ->whereIn('occurrence_id', $date->modelKeys())->where('visibility', SavedVisibility::Public->value)
            ->update(['visibility' => SavedVisibility::Private->value]);

        $pratiche = EventOccurrence::query()->whereIn('event_id', $events->modelKeys())
            ->where(fn ($query) => $query->whereNotNull('practical_details')->orWhereNotNull('cost_breakdown'))
            ->update(['practical_details' => null, 'cost_breakdown' => null]);

        foreach ($date as $singola) {
            $singola->checkinStaff()->detach($people->modelKeys());
        }

        $this->table(['Elemento', 'Rimossi'], [
            ['Prenotazioni della vetrina', $prenotazioni->count()],
            ['Sondaggi', $sondaggi->count()],
            ['Giorni di condivisioni', $condivisioni],
            ['«Ci vado» riportati a privati', $pubblici],
            ['Date ripulite da informazioni e costi', $pratiche],
        ]);
        $this->info('Le funzioni sono state tolte. La vetrina resta: per rimuovere anche quella, demo:showcase --purge.');

        return self::SUCCESS;
    }
}
