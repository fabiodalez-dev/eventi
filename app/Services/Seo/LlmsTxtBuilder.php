<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Category;
use App\Models\City;
use App\Models\Page;
use App\Queries\EventOccurrenceQuery;
use App\Support\ContentVersion;
use App\Support\EventUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * `llms.txt`: cos'è questo sito, detto a chi legge il web per rispondere a
 * una domanda invece che per compilare un indice.
 *
 * ## Perché non basta la sitemap
 *
 * La `sitemap.xml` dice *dove* sono le pagine e non dice *cosa* sono: è un
 * elenco di indirizzi con una data. Va benissimo a un motore che poi scarica
 * ogni pagina e la legge; serve a poco a un assistente che deve decidere in
 * una passata sola se questo sito sa rispondere a «cosa faccio stasera a
 * Padova». `llms.txt` è la stessa mappa scritta per essere letta: un titolo,
 * una frase che dice di cosa si tratta, e collegamenti con un'etichetta
 * leggibile accanto.
 *
 * ## Le stesse regole della sitemap, non altre
 *
 * Entra qui solo ciò che entrerebbe nella mappa: niente contenuti
 * dimostrativi, niente pagine escluse a mano, niente categorie senza eventi.
 * Un file che promettesse pagine che l'indice rifiuta sarebbe una seconda
 * verità sul sito, e la seconda verità è sempre quella sbagliata.
 *
 * ## Si compone a ogni richiesta e sta in cache
 *
 * Come la mappa, e per le stesse due ragioni: un file scritto stanotte non
 * conosce l'evento pubblicato stamattina, e su uno spazio condiviso un `cron`
 * in meno è un guasto in meno. La chiave porta il numero di versione della
 * città, quindi alla pubblicazione di un evento il file è già un altro.
 */
final class LlmsTxtBuilder
{
    public function build(City $city): string
    {
        $key = sprintf('llms:%d:%d', (int) $city->getKey(), ContentVersion::for($city));

        /** @var string $testo */
        $testo = Cache::remember(
            $key,
            now()->addMinutes(config()->integer('seo.llms.ttl_minutes')),
            fn (): string => $this->compose($city),
        );

        return $testo;
    }

    private function compose(City $city): string
    {
        $righe = [
            '# '.config()->string('app.name'),
            '',
            '> '.__('seo.llms.summary', ['city' => $city->name]),
        ];

        foreach ([
            'seo.llms.pages' => $this->pages($city),
            'seo.llms.categories' => $this->categories($city),
            'seo.llms.events' => $this->events($city),
        ] as $titolo => $voci) {
            if ($voci === []) {
                continue;
            }

            $righe[] = '';
            $righe[] = '## '.__($titolo);
            $righe[] = '';

            foreach ($voci as $voce) {
                $righe[] = $voce;
            }
        }

        return implode(PHP_EOL, $righe).PHP_EOL;
    }

    /**
     * Le pagine fisse e quelle editoriali, nell'ordine in cui uno le
     * userebbe. Si dichiarano solo le rotte registrate davvero: una voce che
     * porta a un 404 insegna che il sito non è affidabile.
     *
     * @return list<string>
     */
    private function pages(City $city): array
    {
        $voci = [];

        /*
         * Le etichette sono quelle della navigazione, non nuove: la voce che
         * un assistente legge qui deve essere la stessa che un visitatore
         * vede nel menu, o il file descriverebbe un sito diverso da quello
         * che esiste.
         */
        $routes = [
            'home' => 'ui.nav.home',
            'events.today' => 'ui.nav.today',
            'events.tomorrow' => 'ui.nav.tomorrow',
            'events.weekend' => 'ui.nav.weekend',
            'events.free' => 'ui.nav.free',
            'events.index' => 'ui.nav.events',
            'venues.index' => 'ui.nav.venues',
            'organizers.index' => 'organizers.title',
            'calendar.index' => 'ui.nav.calendar',
            'map.index' => 'ui.nav.map',
        ];

        foreach ($routes as $name => $label) {
            if (Route::has($name)) {
                $voci[] = $this->entry(__($label), route($name));
            }
        }

        foreach (Page::query()->published()->get() as $page) {
            if (app(EditorialContent::class)->indexable($page)) {
                $voci[] = $this->entry($page->title, route('pages.show', ['slug' => $page->slug]));
            }
        }

        return $voci;
    }

    /**
     * Le categorie con almeno un evento: le stesse che entrano nella mappa.
     *
     * @return list<string>
     */
    private function categories(City $city): array
    {
        $voci = [];

        foreach (Category::query()->active()->ordered()->get() as $category) {
            if (app(EditorialContent::class)->taxonomyIndexable($category, $city)) {
                $voci[] = $this->entry($category->name, route('events.category', $category));
            }
        }

        return $voci;
    }

    /**
     * Le prossime date, con data e luogo accanto al titolo: è la riga che
     * permette di rispondere senza aprire la scheda.
     *
     * L'elenco è limitato perché `llms.txt` è un sommario, non un archivio:
     * chi vuole tutto ha la mappa del sito, che è lì accanto.
     *
     * @return list<string>
     */
    private function events(City $city): array
    {
        $voci = [];
        $editorial = app(EditorialContent::class);

        $dates = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->get()
            ->take(config()->integer('seo.llms.events'));

        foreach ($dates as $date) {
            $event = $date->event;

            if ($event === null || ! $editorial->indexable($event)) {
                continue;
            }

            $quando = $date->starts_at->copy()->timezone($city->timezone)->format('d/m/Y H:i');
            $dove = $date->effectiveVenue()?->name;

            $voci[] = $this->entry(
                $event->title,
                EventUrl::occurrence($date),
                implode(', ', array_filter([$quando, $dove])),
            );
        }

        return $voci;
    }

    /**
     * Una voce nel formato che `llms.txt` si aspetta: `- [etichetta](url)`,
     * con una nota dopo i due punti quando c'è qualcosa da aggiungere.
     */
    private function entry(string $label, string $url, string $note = ''): string
    {
        $riga = sprintf('- [%s](%s)', $this->clean($label), $url);

        return $note === '' ? $riga : $riga.': '.$this->clean($note);
    }

    /**
     * Le parentesi quadre e tonde spezzerebbero il collegamento; gli a capo
     * spezzerebbero la riga. Un titolo è una riga sola.
     */
    private function clean(string $value): string
    {
        return trim(str_replace(['[', ']', '(', ')', "\r", "\n"], ['', '', '', '', ' ', ' '], strip_tags($value)));
    }
}
