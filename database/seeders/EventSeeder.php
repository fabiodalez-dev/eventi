<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\GenerateOccurrencesAction;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\LineupRole;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\TicketTierStatus;
use App\Enums\VenueType;
use App\Enums\VerificationStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Lineup;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Popola ~150 occorrenze future sui prossimi 30 giorni, con i casi limite del
 * motore temporale (§8) tutti rappresentati: in corso, inizia tra poco,
 * nightlife dopo mezzanotte, ricorrenze vere generate da
 * `GenerateOccurrencesAction`, stati di moderazione ed eccezione.
 *
 * `business_date` ed `effective_ends_at` non vengono mai scritti qui: nascono
 * da `EventOccurrenceObserver` al salvataggio, come per ogni altra parte
 * dell'applicazione (§5 delle convenzioni).
 */
class EventSeeder extends Seeder
{
    /**
     * Tipi di locale plausibili per ciascuna categoria. Categoria assente o
     * lista vuota → nessun vincolo, si pesca da tutti i locali approvati.
     *
     * @var array<string, list<VenueType>>
     */
    private const VENUE_TYPES_BY_CATEGORY = [
        'Musica dal vivo' => [VenueType::Club, VenueType::Circolo, VenueType::CentroSociale, VenueType::Bar, VenueType::Pub, VenueType::Teatro, VenueType::SpazioPubblico],
        'DJ set / Nightlife' => [VenueType::Club, VenueType::Bar, VenueType::Pub],
        'Teatro e danza' => [VenueType::Teatro],
        'Cinema' => [VenueType::Cinema, VenueType::Associazione],
        'Arte e mostre' => [VenueType::Galleria, VenueType::Libreria, VenueType::Associazione],
        'Libri e presentazioni' => [VenueType::Libreria, VenueType::Bar, VenueType::Associazione, VenueType::Circolo],
        'Politica e attivismo' => [VenueType::CentroSociale, VenueType::Circolo, VenueType::Associazione, VenueType::SpazioPubblico],
        'Sport' => [VenueType::SpazioPubblico, VenueType::Circolo],
        'Food e sagre' => [VenueType::Ristorante, VenueType::SpazioPubblico, VenueType::Circolo],
        'Mercatini' => [VenueType::SpazioPubblico, VenueType::Galleria],
        'Corsi e workshop' => [VenueType::Associazione, VenueType::Circolo, VenueType::Libreria, VenueType::Galleria],
        'Bambini e famiglie' => [VenueType::Libreria, VenueType::Associazione, VenueType::SpazioPubblico, VenueType::Circolo],
        'Comunità e assemblee' => [VenueType::CentroSociale, VenueType::Circolo, VenueType::Associazione, VenueType::SpazioPubblico],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const TEMPLATES = [
        'Musica dal vivo' => ['Concerto: %s', '%s in concerto', 'Note di %s', 'Live: %s sul palco', 'Serata acustica: %s'],
        'DJ set / Nightlife' => ['DJ set: %s', '%s Night', 'Serata %s', 'Afterhours: %s'],
        'Teatro e danza' => ['%s', 'In scena: %s', 'Danza contemporanea: %s'],
        'Cinema' => ['Proiezione: %s', 'Cineforum: %s', 'Rassegna: %s'],
        'Arte e mostre' => ['Mostra: %s', 'Esposizione: %s', 'Retrospettiva: %s'],
        'Libri e presentazioni' => ['Presentazione: "%s"', "Incontro con l'autore: %s", 'Reading collettivo: %s'],
        'Politica e attivismo' => ['Assemblea pubblica: %s', 'Presidio: %s', 'Incontro su %s'],
        'Sport' => ['Torneo di %s', 'Esibizione di %s', 'Camminata: %s'],
        'Food e sagre' => ['Sagra %s', 'Degustazione: %s', 'Street food: %s'],
        'Mercatini' => ['Mercatino %s', '%s Market'],
        'Corsi e workshop' => ['Workshop di %s', 'Laboratorio di %s', 'Corso base di %s'],
        'Bambini e famiglie' => ['%s per bambini', 'Laboratorio famiglie: %s'],
        'Comunità e assemblee' => ['Assemblea di quartiere: %s', 'Incontro del comitato: %s'],
        'Altro' => ['Serata speciale: %s', '%s fuori programma'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const SUBJECTS = [
        'Musica dal vivo' => ['Jazz Combo', 'Radici Folk', 'Blues Brothers Tribute', 'Cantautori Uniti', 'Trio Acustico', 'Orchestra da Camera', 'Coro Popolare', 'Chitarre e Voci', 'Ensemble Klezmer', 'Banda Larga'],
        'DJ set / Nightlife' => ['Deep House', 'Techno Session', 'Disco Revival', 'Vinyl Only', 'Minimal & Dub', 'Latin Groove', 'Elettronica Sperimentale'],
        'Teatro e danza' => ["L'importanza di chiamarsi Ernesto", 'Amleto rivisitato', 'Corpi in movimento', 'Il malato immaginario', 'Aspettando Godot', "Sei personaggi in cerca d'autore", 'Improvvisazione teatrale'],
        'Cinema' => ['il cinema del reale', 'nuovo cinema italiano', 'sguardi dal mondo', 'classici restaurati', 'opere prime'],
        'Arte e mostre' => ['Volti di provincia', 'Segni e colori', 'Fotografia di strada', 'Arte contemporanea veneta', 'Ceramica e materia'],
        'Libri e presentazioni' => ['Case di vetro', 'Il tempo sospeso', 'Poesia e memoria', 'Racconti di periferia', 'Storie di confine'],
        'Politica e attivismo' => ['diritto alla casa', 'clima e giustizia sociale', 'mobilità sostenibile', 'diritti dei migranti', 'lavoro e precarietà'],
        'Sport' => ['calcetto amatoriale', 'scacchi simultanea', 'pallavolo mista', 'corsa non competitiva', 'basket 3x3'],
        'Food e sagre' => ['del radicchio', 'dei vini del territorio', 'della polenta', 'dei prodotti a km zero', 'del baccalà'],
        'Mercatini' => ["dell'antiquariato", 'del riuso', 'del vintage', 'dei libri usati', 'delle pulci'],
        'Corsi e workshop' => ['ceramica raku', 'scrittura creativa', 'fotografia', 'cucina vegetale', 'falegnameria'],
        'Bambini e famiglie' => ['Letture animate', 'Burattini in piazza', 'Giochi in famiglia', 'Caccia al tesoro'],
        'Comunità e assemblee' => ['bilancio partecipativo', 'mobilità di zona', 'verde pubblico', 'sicurezza stradale'],
        'Altro' => ['sorpresa musicale', 'incontro conviviale', 'scambio di saperi'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const FLAVORS = [
        'Musica dal vivo' => ['Una serata dal vivo per riscoprire il suono degli strumenti acustici', 'Un concerto pensato per chi ama la musica suonata dal vivo', 'Un palco aperto a nuovi progetti musicali del territorio'],
        'DJ set / Nightlife' => ['Selezioni musicali no-stop fino a tarda notte', 'Una notte tra vinili, sintetizzatori e groove ininterrotto', "Il resident DJ apre la serata, chiude l'ospite della notte"],
        'Teatro e danza' => ['Uno spettacolo di prosa con un cast locale', 'Una messa in scena essenziale, pensata per una sala raccolta', 'Un lavoro coreografico che intreccia corpo e parola'],
        'Cinema' => ['Una proiezione seguita da un breve dibattito con il pubblico', 'Un film scelto dal collettivo di programmazione del cineclub', 'Una serata di cinema indipendente, ingresso con tessera'],
        'Arte e mostre' => ['Una mostra visitabile per tutta la durata degli orari di apertura', 'Opere di artisti del territorio esposte per alcune settimane', 'Un percorso espositivo curato in collaborazione con il locale'],
        'Libri e presentazioni' => ["L'autrice dialoga con il pubblico a seguire della lettura", 'Un incontro tra parole e musica, con letture ad alta voce', 'Presentazione seguita da un momento conviviale'],
        'Politica e attivismo' => ['Un momento di confronto pubblico aperto a tutta la cittadinanza', 'Un incontro organizzato dai comitati di quartiere', 'Uno spazio di discussione su un tema che riguarda la città'],
        'Sport' => ['Iscrizioni sul posto, tutti i livelli sono benvenuti', 'Un pomeriggio sportivo aperto a chiunque voglia partecipare', 'Attività gratuita, materiale fornito dagli organizzatori'],
        'Food e sagre' => ['Stand gastronomici con prodotti locali e musica dal vivo', 'Degustazioni guidate a cura dei produttori del territorio', 'Cucina tradizionale e mercatino agricolo per tutta la giornata'],
        'Mercatini' => ['Bancarelle di espositori del territorio per tutta la giornata', 'Un mercatino a cadenza periodica nel cuore del paese', 'Oggetti di seconda mano, vinili e piccolo antiquariato'],
        'Corsi e workshop' => ['Materiali inclusi nel prezzo, posti limitati', 'Un laboratorio pratico adatto anche a chi parte da zero', 'Un pomeriggio di formazione condotto da un artigiano locale'],
        'Bambini e famiglie' => ['Attività pensata per bambini dai 4 ai 10 anni, accompagnati da un adulto', 'Un pomeriggio di giochi e letture per tutta la famiglia', 'Laboratorio creativo con materiali di riciclo'],
        'Comunità e assemblee' => ['Un incontro aperto a residenti e associazioni di zona', 'Momento di confronto sulle priorità del quartiere', 'Assemblea pubblica con verbale condiviso a fine incontro'],
        'Altro' => ['Un appuntamento fuori dagli schemi abituali del locale', 'Una serata pensata per chi vuole scoprire qualcosa di diverso'],
    ];

    /** @var list<string> */
    private const CLOSINGS = [
        'Informazioni e aggiornamenti sui canali social del locale.',
        'Ingresso fino a esaurimento posti.',
        'Consigliata la prenotazione.',
        'Un appuntamento pensato per chi vive il quartiere.',
        'Evento adatto anche a chi partecipa per la prima volta.',
    ];

    private City $city;

    /** @var Collection<string, Category> */
    private Collection $categories;

    /** @var Collection<int, Tag> */
    private Collection $tags;

    /** @var Collection<int, Venue> */
    private Collection $venues;

    private User $admin;

    private CarbonImmutable $now;

    public function run(): void
    {
        $this->city = City::where('slug', 'padova')->firstOrFail();
        $this->categories = Category::all()->keyBy('name');
        $this->tags = Tag::query()->approved()->get();
        $this->venues = Venue::query()->approved()->inCity($this->city)->get();
        $this->admin = User::where('email', 'admin@incitta.test')->firstOrFail();
        $this->now = CarbonImmutable::now($this->city->timezone);

        $this->seedOngoingEvents();
        $this->seedStartingSoonEvents();
        $this->seedNightlifeAfterMidnight();
        $this->seedCancelledAndSoldOut();
        $this->seedCustomLocationEvents();
        $this->seedRecurringEvents();
        $this->seedPendingEvents();
        $this->seedDailyProgram();
    }

    /**
     * Due occorrenze già iniziate e non ancora finite, categoria con
     * `supports_ongoing = true`: la verifica di "in corso adesso" (§8.4).
     */
    private function seedOngoingEvents(): void
    {
        $music = $this->categories['Musica dal vivo'];
        $event = $this->makeEvent([
            'title' => 'Concerto: Trio Acustico',
            'subtitle' => 'Serata di musica acustica',
            'description' => 'Un trio acustico dal vivo davanti a un pubblico raccolto. Biglietto di ingresso: 8 euro; consumazioni al bancone.',
            'short_description' => 'Concerto acustico dal vivo. Ingresso: 8 euro.',
            'category' => $music,
            'venue' => $this->venueOfType([VenueType::Club, VenueType::Circolo]) ?? $this->venues->first(),
            'price_type' => PriceType::Ticket,
            'price_min' => 8,
            'price_max' => 8,
        ]);
        $this->makeOccurrence($event, $this->now->subMinutes(45));

        $workshop = $this->categories['Corsi e workshop'];
        $event = $this->makeEvent([
            'title' => 'Laboratorio di ceramica raku',
            'subtitle' => 'Sessione pomeridiana già avviata',
            'description' => "Il laboratorio è cominciato da poco: chi arriva ora può ancora unirsi al tavolo di lavoro con l'argilla.",
            'short_description' => 'Laboratorio di ceramica in corso, posti last minute disponibili.',
            'category' => $workshop,
            'venue' => $this->venueOfType([VenueType::Associazione, VenueType::Circolo]) ?? $this->venues->first(),
            'price_type' => PriceType::Donation,
            'price_notes' => 'Offerta libera per i materiali.',
        ]);
        $this->makeOccurrence($event, $this->now->subMinutes(30));
    }

    /**
     * Tre occorrenze che iniziano entro `starting_soon_minutes` (180) da adesso.
     */
    private function seedStartingSoonEvents(): void
    {
        $specs = [
            ['minutes' => 25, 'category' => 'Cinema', 'title' => 'Proiezione: opere prime', 'price' => PriceType::Free],
            ['minutes' => 95, 'category' => 'Politica e attivismo', 'title' => 'Assemblea pubblica: mobilità sostenibile', 'price' => PriceType::Membership],
            ['minutes' => 165, 'category' => 'Teatro e danza', 'title' => 'In scena: Aspettando Godot', 'price' => PriceType::Ticket],
        ];

        foreach ($specs as $spec) {
            $category = $this->categories[$spec['category']];
            $venue = $this->venueForCategory($category);

            $event = $this->makeEvent([
                'title' => $spec['title'],
                'description' => 'Uno degli appuntamenti in programma nelle prossime ore, aperto al pubblico.',
                'short_description' => 'In programma a breve.',
                'category' => $category,
                'venue' => $venue,
                'price_type' => $spec['price'],
                'price_min' => $spec['price'] === PriceType::Ticket ? 10 : null,
                'price_max' => $spec['price'] === PriceType::Ticket ? 10 : null,
            ]);
            $this->makeOccurrence($event, $this->now->addMinutes($spec['minutes']));
        }
    }

    /**
     * Evento nightlife che inizia dopo mezzanotte: dimostra il cutoff
     * notturno di `business_date` (§8.2), che si applica solo perché
     * `is_nightlife = true`.
     */
    private function seedNightlifeAfterMidnight(): void
    {
        $category = $this->categories['DJ set / Nightlife'];
        $venue = $this->venueForCategory($category);

        $event = $this->makeEvent([
            'title' => 'DJ set: Techno Session',
            'subtitle' => 'Dopo mezzanotte, resta nella serata di prima',
            'description' => 'Il set principale comincia dopo la mezzanotte: per chi era già in pista, la serata è iniziata molto prima.',
            'short_description' => "Techno set after mezzanotte all'Hall.",
            'category' => $category,
            'venue' => $venue,
            'price_type' => PriceType::Ticket,
            'price_min' => 12,
            'price_max' => 15,
        ]);
        $this->makeOccurrence($event, $this->now->addDays(4)->setTime(1, 30));

        // Una seconda, su un altro locale e un'altra notte, per non dipendere
        // da un solo caso limite.
        $event = $this->makeEvent([
            'title' => 'Notte Disco Revival',
            'description' => 'Selezioni disco e funk fino alle prime luci, ingresso con prevendita online.',
            'short_description' => 'Disco set fino a notte fonda.',
            'category' => $category,
            'venue' => $this->venueForCategory($category),
            'price_type' => PriceType::Ticket,
            'price_min' => 10,
            'price_max' => 10,
        ]);
        $this->makeOccurrence($event, $this->now->addDays(9)->setTime(0, 45));
    }

    /**
     * Un'occorrenza annullata e una esaurita, entrambe future.
     */
    private function seedCancelledAndSoldOut(): void
    {
        $category = $this->categories['Musica dal vivo'];

        $event = $this->makeEvent([
            'title' => 'Concerto: Orchestra da Camera',
            'description' => 'Il concerto è stato annullato per indisponibilità sopravvenuta degli artisti.',
            'short_description' => 'Evento annullato.',
            'category' => $category,
            'venue' => $this->venueForCategory($category),
            'price_type' => PriceType::Ticket,
            'price_min' => 15,
            'price_max' => 15,
        ]);
        $this->makeOccurrence($event, $this->now->addDays(6)->setTime(21, 0), [
            'status' => OccurrenceStatus::Cancelled,
            'status_note' => 'Annullato per indisponibilità degli artisti.',
        ]);

        $event = $this->makeEvent([
            'title' => 'Concerto: Blues Brothers Tribute',
            'description' => 'I biglietti sono esauriti: rimane la lista d\'attesa in cassa la sera stessa.',
            'short_description' => 'Sold out.',
            'category' => $category,
            'venue' => $this->venueForCategory($category),
            'price_type' => PriceType::Ticket,
            'price_min' => 18,
            'price_max' => 22,
            'facts' => [
                ['label' => 'Apertura porte', 'value' => '20:30'],
                ['label' => 'Durata', 'value' => 'Circa 2 ore'],
                ['label' => 'Età minima', 'value' => '16 anni'],
            ],
        ]);
        $this->makeOccurrence($event, $this->now->addDays(11)->setTime(21, 30), [
            'status' => OccurrenceStatus::SoldOut,
            'capacity' => 300,
            'capacity_left' => 0,
            'highlight' => 'Lista d\'attesa',
        ]);
        $this->attachTiers($event, [
            ['name' => 'Posto unico in piedi', 'price' => 18, 'status' => TicketTierStatus::SoldOut],
            ['name' => 'Posto a sedere in galleria', 'price' => 22, 'status' => TicketTierStatus::SoldOut],
        ]);

        /*
         * Il caso che `OccurrenceStatus::SoldOut` non sa raccontare: il
         * settore in piedi è finito, quello a sedere no. La serata resta in
         * vendita, ed è la ragione per cui le fasce esistono.
         */
        $event = $this->makeEvent([
            'title' => 'Concerto: Cantautori Uniti',
            'subtitle' => 'Parterre esaurito, resta la galleria',
            'description' => 'Il posto in piedi sotto il palco è finito in prevendita. Restano i posti a sedere in galleria e qualche poltrona di prima fila.',
            'short_description' => 'Parterre esaurito, galleria disponibile.',
            'category' => $category,
            'venue' => $this->venueOfType([VenueType::Teatro]) ?? $this->venueForCategory($category),
            'price_type' => PriceType::Ticket,
            'facts' => [
                ['label' => 'Apertura porte', 'value' => '19:45'],
                ['label' => 'Durata', 'value' => '1 ora e 40 minuti'],
                ['label' => 'Età minima', 'value' => 'Nessuna'],
                ['label' => 'Guardaroba', 'value' => 'Compreso nel biglietto'],
            ],
        ]);
        $this->makeOccurrence($event, $this->now->addDays(13)->setTime(21, 0), [
            'capacity' => 800,
            'capacity_left' => 96,
            'highlight' => 'Ultimi posti',
        ]);
        $this->attachTiers($event, [
            ['name' => 'Parterre in piedi', 'price' => 25, 'status' => TicketTierStatus::SoldOut],
            ['name' => 'Galleria numerata', 'price' => 32, 'status' => TicketTierStatus::Available, 'note' => 'Visuale completa sul palco'],
            ['name' => 'Poltronissima', 'price' => 48, 'status' => TicketTierStatus::Available],
            ['name' => 'Ridotto under 26', 'price' => 18, 'status' => TicketTierStatus::NotYetOnSale, 'note' => 'In vendita da una settimana prima'],
        ]);
    }

    /**
     * Eventi senza locale registrato: sagre e assemblee in piazza (§7.6,
     * `custom_location`).
     */
    private function seedCustomLocationEvents(): void
    {
        $places = [
            ['name' => 'Prato della Valle', 'address' => 'Prato della Valle, Padova', 'lat' => 45.3988, 'lng' => 11.8759],
            ['name' => 'Piazza dei Signori', 'address' => 'Piazza dei Signori, Padova', 'lat' => 45.4076, 'lng' => 11.8746],
            ['name' => 'Piazza Castello', 'address' => 'Piazza Castello, Cittadella', 'lat' => 45.6485, 'lng' => 11.7845],
        ];

        $specs = [
            ['category' => 'Food e sagre', 'title' => 'Sagra del radicchio', 'days' => 8, 'hour' => 18, 'price' => PriceType::Free, 'place' => 0],
            ['category' => 'Comunità e assemblee', 'title' => 'Assemblea di quartiere: verde pubblico', 'days' => 5, 'hour' => 18, 'price' => PriceType::Free, 'place' => 1],
            ['category' => 'Politica e attivismo', 'title' => 'Presidio: diritto alla casa', 'days' => 14, 'hour' => 10, 'price' => PriceType::Free, 'place' => 2],
        ];

        foreach ($specs as $spec) {
            $category = $this->categories[$spec['category']];
            $place = $places[$spec['place']];

            $event = $this->makeEvent([
                'title' => $spec['title'],
                'description' => 'Un appuntamento pubblico in piazza, senza locale ospitante: organizzazione a cura del comitato promotore.',
                'short_description' => "In programma in {$place['name']}.",
                'category' => $category,
                'venue' => null,
                'is_outdoor' => true,
                'organizer_name' => 'Comitato promotore',
                'custom_location' => [
                    'name' => $place['name'],
                    'address' => $place['address'],
                    'lat' => $place['lat'],
                    'lng' => $place['lng'],
                ],
                'price_type' => $spec['price'],
            ]);
            $this->makeOccurrence($event, $this->now->addDays($spec['days'])->setTime($spec['hour'], 0));
        }
    }

    /**
     * Due serie ricorrenti vere: la RRULE viene espansa da
     * `GenerateOccurrencesAction`, non a mano (§7.8, D20).
     */
    private function seedRecurringEvents(): void
    {
        $music = $this->categories['Musica dal vivo'];
        $venue = $this->venueOfType([VenueType::Club]) ?? $this->venueForCategory($music);

        $event = $this->makeEvent([
            'title' => 'Jam Session del Giovedì',
            'subtitle' => 'Appuntamento fisso settimanale',
            'description' => 'Ogni giovedì il palco è aperto ai musicisti che vogliono suonare insieme: si comincia con un nucleo fisso e si aggiungono gli ospiti della serata.',
            'short_description' => 'Jam session settimanale, ogni giovedì.',
            'category' => $music,
            'venue' => $venue,
            'price_type' => PriceType::Free,
        ]);

        $firstThursday = $this->now->next(CarbonImmutable::THURSDAY)->setTime(21, 30);
        $template = $this->makeOccurrence($event, $firstThursday, [
            'ends_at' => $firstThursday->addHours(2)->utc(),
        ]);

        $recurrence = EventRecurrence::create([
            'event_id' => $event->getKey(),
            'rrule' => 'FREQ=WEEKLY;BYDAY=TH',
            'until' => null,
            'exdates' => null,
            'generated_until' => null,
        ]);
        $template->recurrence_id = $recurrence->getKey();
        $template->save();

        app(GenerateOccurrencesAction::class)($recurrence, $this->now->addDays(30)->endOfDay());

        $this->attachLineup($template, [
            ['name' => 'Nucleo fisso Jam Session', 'role' => LineupRole::Live],
            ['name' => 'Ospiti della serata', 'role' => LineupRole::SpecialGuest],
        ]);

        $books = $this->categories['Libri e presentazioni'];
        $libreria = $this->venueOfType([VenueType::Libreria]) ?? $this->venueForCategory($books);

        $event = $this->makeEvent([
            'title' => 'Presentazioni del Martedì',
            'subtitle' => 'Rassegna settimanale di incontri con autori',
            'description' => "Ogni martedì la libreria ospita un incontro con un'autrice o un autore diverso, seguito da un momento di confronto con il pubblico.",
            'short_description' => 'Incontro con l\'autore, ogni martedì.',
            'category' => $books,
            'venue' => $libreria,
            'price_type' => PriceType::Free,
        ]);

        $firstTuesday = $this->now->next(CarbonImmutable::TUESDAY)->setTime(18, 30);
        $template = $this->makeOccurrence($event, $firstTuesday, [
            'ends_at' => $firstTuesday->addHour()->utc(),
        ]);

        $recurrence = EventRecurrence::create([
            'event_id' => $event->getKey(),
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
            'until' => null,
            'exdates' => null,
            'generated_until' => null,
        ]);
        $template->recurrence_id = $recurrence->getKey();
        $template->save();

        app(GenerateOccurrencesAction::class)($recurrence, $this->now->addDays(30)->endOfDay());
    }

    /**
     * Eventi in coda di moderazione: non compaiono in `EventOccurrenceQuery`
     * perché la query di base restringe agli eventi pubblicati (D18.1), ed è
     * proprio questo che li rende utili come demo della coda.
     */
    private function seedPendingEvents(): void
    {
        $specs = [
            ['category' => 'Musica dal vivo', 'title' => 'Concerto: Coro Popolare', 'days' => 3],
            ['category' => 'Cinema', 'title' => 'Rassegna: classici restaurati', 'days' => 7],
            ['category' => 'Corsi e workshop', 'title' => 'Workshop di falegnameria', 'days' => 10],
            ['category' => 'Arte e mostre', 'title' => 'Mostra: fotografia di strada', 'days' => 15],
            ['category' => 'Sport', 'title' => 'Torneo di basket 3x3', 'days' => 18],
        ];

        foreach ($specs as $spec) {
            $category = $this->categories[$spec['category']];
            $venue = $this->venueForCategory($category);

            $event = $this->makeEvent([
                'title' => $spec['title'],
                'description' => 'Proposta appena inviata dal locale, in attesa di verifica editoriale prima della pubblicazione.',
                'short_description' => 'In attesa di approvazione.',
                'category' => $category,
                'venue' => $venue,
                'status' => EventStatus::Pending,
                'published_at' => null,
                'verification_status' => VerificationStatus::Unverified,
                'price_type' => PriceType::Unknown,
            ]);
            $this->makeOccurrence($event, $this->now->addDays($spec['days'])->setTime(20, 0));
        }
    }

    /**
     * Riempie i prossimi 30 giorni con eventi pubblicati vari, garantendo
     * almeno 3 occorrenze per ciascuno dei prossimi 14 giorni.
     */
    private function seedDailyProgram(): void
    {
        $categoryNames = array_keys(self::TEMPLATES);

        for ($offset = 0; $offset < 30; $offset++) {
            $day = $this->now->addDays($offset);
            $count = random_int(3, 5);

            for ($i = 0; $i < $count; $i++) {
                $categoryName = $categoryNames[array_rand($categoryNames)];
                $category = $this->categories[$categoryName];
                $venue = $this->venueForCategory($category);
                $allDay = in_array($categoryName, ['Arte e mostre', 'Mercatini'], true) && random_int(1, 100) <= 60;

                [$title, $short, $description] = $this->composeContent($categoryName);
                $priceType = $this->priceTypeFor($categoryName, $venue);

                $event = $this->makeEvent([
                    'title' => $title,
                    'description' => $description,
                    'short_description' => $short,
                    'category' => $category,
                    'venue' => $venue,
                    'price_type' => $priceType,
                    'price_min' => $priceType === PriceType::Ticket ? random_int(5, 25) : null,
                    'price_max' => $priceType === PriceType::Ticket ? random_int(25, 40) : null,
                    'price_notes' => $priceType === PriceType::Membership ? 'Ingresso riservato ai soci tesserati.' : null,
                    'is_outdoor' => in_array($categoryName, ['Sport', 'Food e sagre', 'Mercatini'], true) && random_int(1, 100) <= 40,
                ]);

                $occurrence = $allDay
                    ? $this->makeOccurrence($event, $day->startOfDay(), ['is_all_day' => true])
                    : $this->makeOccurrence($event, $day->setTime(...$this->randomTimeFor($categoryName)));

                $this->attachRandomTags($event);
                $this->attachSampleFacts($event, $categoryName);

                /*
                 * Un evento a biglietto su tre porta il proprio listino: sono
                 * i dati su cui si vede che lo stato è **per fascia**, e senza
                 * qualcuno la sezione della scheda resterebbe sempre vuota e
                 * non si capirebbe se manca il disegno o mancano i dati.
                 */
                if ($priceType === PriceType::Ticket && random_int(1, 100) <= 35) {
                    $this->attachSampleTiers($event);
                }

                $this->attachSampleSeats($occurrence, $venue);

                if (in_array($categoryName, ['Musica dal vivo', 'DJ set / Nightlife'], true) && random_int(1, 100) <= 35) {
                    $this->attachLineup($occurrence, $this->randomLineupFor($categoryName));
                }
            }
        }
    }

    // ------------------------------------------------------------- fabbrica

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvent(array $overrides): Event
    {
        $category = $overrides['category'];
        unset($overrides['category']);

        $venue = array_key_exists('venue', $overrides) ? $overrides['venue'] : null;
        unset($overrides['venue']);

        $defaults = [
            'is_demo' => true,
            'city_id' => $this->city->getKey(),
            'created_by' => $this->admin->getKey(),
            'organizer_name' => null,
            'organizer_url' => null,
            'subtitle' => null,
            'poster' => null,
            'gallery' => null,
            'price_type' => PriceType::Unknown,
            'price_min' => null,
            'price_max' => null,
            'currency' => 'EUR',
            'price_notes' => null,
            'ticket_url' => null,
            'booking_required' => false,
            'booking_url' => null,
            'booking_phone' => null,
            'age_restriction' => null,
            'language' => 'it',
            'is_outdoor' => false,
            'custom_location' => null,
            'external_links' => null,
            'facts' => null,
            'source' => $venue instanceof Venue ? EventSource::Venue : EventSource::Manual,
            'source_ref' => null,
            'verification_status' => $venue instanceof Venue ? VerificationStatus::VenueConfirmed : VerificationStatus::Unverified,
            'status' => EventStatus::Published,
            'rejection_reason' => null,
            'is_featured' => false,
            'featured_until' => null,
            'editorial_score' => 0,
            'published_at' => now(),
            'seo' => null,
            'views_count' => 0,
            'saves_count' => 0,
        ];

        $attributes = array_merge($defaults, $overrides, [
            'category_id' => $category->getKey(),
            'venue_id' => $venue?->getKey(),
        ]);

        return Event::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOccurrence(Event $event, CarbonImmutable $startsAtLocal, array $overrides = []): EventOccurrence
    {
        $occurrence = new EventOccurrence(array_merge([
            'event_id' => $event->getKey(),
            'recurrence_id' => null,
            'starts_at' => $startsAtLocal->utc(),
            'ends_at' => null,
            'doors_at' => null,
            'is_all_day' => false,
            'status' => OccurrenceStatus::Scheduled,
            'status_note' => null,
            'price_override' => null,
            'capacity' => null,
            'capacity_left' => null,
            'highlight' => null,
            'is_exception' => false,
        ], $overrides));

        $occurrence->setRelation('event', $event);
        $occurrence->save();

        return $occurrence;
    }

    /**
     * @param  list<array{name: string, role: LineupRole}>  $acts
     */
    private function attachLineup(EventOccurrence $occurrence, array $acts): void
    {
        foreach ($acts as $index => $act) {
            Lineup::create([
                'occurrence_id' => $occurrence->getKey(),
                'name' => $act['name'],
                'role' => $act['role'],
                'starts_at' => null,
                'url' => null,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Il listino di un evento. `sort_order` segue l'ordine dell'elenco: è
     * l'ordine in cui il locale vuole che i settori si leggano, non quello
     * del prezzo.
     *
     * @param  list<array{name: string, price: float|int|null, status: TicketTierStatus, note?: string}>  $tiers
     */
    private function attachTiers(Event $event, array $tiers): void
    {
        foreach ($tiers as $index => $tier) {
            TicketTier::create([
                'event_id' => $event->getKey(),
                'occurrence_id' => null,
                'name' => $tier['name'],
                'price' => $tier['price'],
                'currency' => 'EUR',
                'status' => $tier['status'],
                'url' => null,
                'note' => $tier['note'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Un listino plausibile costruito sul prezzo già scritto sull'evento:
     * intero, ridotto, ultimo minuto. Il minimo e il massimo dell'evento
     * vengono poi ricalcolati da `TicketTierObserver`, che è il punto in cui
     * la card e il filtro leggono.
     */
    private function attachSampleTiers(Event $event): void
    {
        $base = (int) ($event->price_min ?? 10);

        $this->attachTiers($event, [
            ['name' => 'Intero', 'price' => $base, 'status' => TicketTierStatus::Available],
            ['name' => 'Ridotto under 26', 'price' => max(1, (int) round($base * 0.7)), 'status' => TicketTierStatus::Available],
            ['name' => 'Alla porta', 'price' => (int) round($base * 1.2), 'status' => random_int(1, 100) <= 30
                ? TicketTierStatus::SoldOut
                : TicketTierStatus::Available, 'note' => 'Solo se restano posti'],
        ]);
    }

    /**
     * La scheda tecnica: le tre cose che al bancone chiedono ogni sera.
     */
    private function attachSampleFacts(Event $event, string $categoryName): void
    {
        if (random_int(1, 100) > 40) {
            return;
        }

        $event->facts = [
            ['label' => 'Apertura porte', 'value' => 'Mezz\'ora prima'],
            ['label' => 'Durata', 'value' => $categoryName === 'Cinema' ? 'Circa 2 ore' : 'Circa 90 minuti'],
            ['label' => 'Età minima', 'value' => $categoryName === 'DJ set / Nightlife' ? '18 anni' : 'Nessuna'],
        ];

        $event->save();
    }

    /**
     * Posti rimasti su una capienza reale, più — ogni tanto — l'etichetta di
     * richiamo. Il totale è quello del locale: la data lo sovrascrive solo
     * quando è davvero diverso, e nei dati dimostrativi non lo è.
     */
    private function attachSampleSeats(EventOccurrence $occurrence, ?Venue $venue): void
    {
        $capacity = $venue?->capacity;

        if ($capacity === null || random_int(1, 100) > 45) {
            return;
        }

        $left = random_int((int) round($capacity * 0.03), (int) round($capacity * 0.6));

        $occurrence->capacity_left = $left;

        if ($left < $capacity * 0.12) {
            $occurrence->highlight = 'Ultimi posti';
        }

        $occurrence->save();
    }

    private function attachRandomTags(Event $event): void
    {
        if ($this->tags->isEmpty()) {
            return;
        }

        $count = random_int(1, 3);
        $ids = $this->tags->random(min($count, $this->tags->count()))->pluck('id');

        $event->tags()->attach($ids);
    }

    // ------------------------------------------------------------- contenuti

    /**
     * @return array{0: string, 1: string, 2: string} titolo, sommario, descrizione
     */
    private function composeContent(string $categoryName): array
    {
        $template = fake()->randomElement(self::TEMPLATES[$categoryName]);
        $subject = fake()->randomElement(self::SUBJECTS[$categoryName]);
        $title = sprintf($template, $subject);

        $flavor = fake()->randomElement(self::FLAVORS[$categoryName] ?? self::FLAVORS['Altro']);
        $closing = fake()->randomElement(self::CLOSINGS);

        $short = mb_substr($flavor, 0, 200);
        $description = sprintf('%s. %s.', $flavor, $closing);

        return [$title, $short, $description];
    }

    private function priceTypeFor(string $categoryName, ?Venue $venue): PriceType
    {
        if ($venue instanceof Venue
            && in_array($venue->type, [VenueType::Circolo, VenueType::CentroSociale, VenueType::Associazione], true)
            && random_int(1, 100) <= 30) {
            return PriceType::Membership;
        }

        return match ($categoryName) {
            'Politica e attivismo', 'Comunità e assemblee', 'Bambini e famiglie', 'Mercatini' => PriceType::Free,
            'Musica dal vivo', 'DJ set / Nightlife', 'Teatro e danza', 'Cinema' => fake()->randomElement([
                PriceType::Ticket, PriceType::Ticket, PriceType::Free, PriceType::Donation,
            ]),
            default => fake()->randomElement([PriceType::Free, PriceType::Donation, PriceType::Ticket]),
        };
    }

    /**
     * @return array{0: int, 1: int} ora e minuti locali plausibili per la categoria
     */
    private function randomTimeFor(string $categoryName): array
    {
        [$min, $max] = match ($categoryName) {
            'Musica dal vivo' => [19, 22],
            'DJ set / Nightlife' => [22, 23],
            'Teatro e danza' => [19, 21],
            'Cinema' => [16, 21],
            'Arte e mostre' => [11, 18],
            'Libri e presentazioni' => [17, 20],
            'Politica e attivismo' => [10, 19],
            'Sport' => [9, 17],
            'Food e sagre' => [11, 21],
            'Mercatini' => [8, 12],
            'Corsi e workshop' => [9, 18],
            'Bambini e famiglie' => [10, 16],
            'Comunità e assemblee' => [18, 20],
            default => [18, 21],
        };

        $minute = fake()->randomElement([0, 15, 30, 45]);

        return [random_int($min, $max), $minute];
    }

    /**
     * @return list<array{name: string, role: LineupRole}>
     */
    private function randomLineupFor(string $categoryName): array
    {
        if ($categoryName === 'DJ set / Nightlife') {
            $djs = ['DJ Marea', 'Selecta Nina', 'Vinyl Ghost', 'Notturno Collective', 'DJ Bassotto'];

            return [
                ['name' => fake()->randomElement($djs), 'role' => LineupRole::Dj],
            ];
        }

        $acts = ['Radici Folk Trio', 'Blu Cobalto', 'I Ragazzi del Naviglio', 'Chitarre Spente', 'Coro delle Mura', 'Banda Larga'];

        return [
            ['name' => fake()->randomElement($acts), 'role' => LineupRole::Live],
        ];
    }

    /**
     * @param  list<VenueType>  $types
     */
    private function venueOfType(array $types): ?Venue
    {
        $pool = $this->venues->filter(fn (Venue $venue): bool => in_array($venue->type, $types, true));

        return $pool->isEmpty() ? null : $pool->random();
    }

    private function venueForCategory(Category $category): ?Venue
    {
        $types = self::VENUE_TYPES_BY_CATEGORY[$category->name] ?? [];

        $pool = $types === []
            ? $this->venues
            : $this->venues->filter(fn (Venue $venue): bool => in_array($venue->type, $types, true));

        if ($pool->isEmpty()) {
            $pool = $this->venues;
        }

        return $pool->isEmpty() ? null : $pool->random();
    }
}
