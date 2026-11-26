<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\PriceType;
use App\Models\Event;
use App\Support\Poster;
use App\Support\Seo\PageTitle;
use Illuminate\Pagination\LengthAwarePaginator;

final class SeoAudit
{
    /** Le righe per pagina del controllo, nel pannello e nell'app. */
    public const PER_PAGE = 20;

    /** Le due schede del controllo: `issues` (predefinita, solo gli eventi da sistemare) e `all`. */
    public const FILTERS = ['issues', 'all'];

    /**
     * Gli strumenti esterni della pagina, nell'ordine in cui la pagina li
     * mostra: si aprono fuori, la validazione vera la fanno loro.
     *
     * @return array<string, string>
     */
    public static function tools(): array
    {
        return [
            'sitemap' => route('sitemap.index'),
            'search_console' => 'https://search.google.com/search-console',
            'rich_results' => 'https://search.google.com/test/rich-results',
            'schema' => 'https://validator.schema.org/',
            'pagespeed' => 'https://pagespeed.web.dev/',
        ];
    }

    /**
     * Il controllo come lo legge la pagina «SEO e qualità del catalogo»: una
     * pagina della scheda scelta e i conteggi delle due schede. Un valore di
     * scheda che non c'è vale «Da sistemare», come nel pannello.
     *
     * @return array{rows: LengthAwarePaginator<int, array{title: string, url: string, issues: list<string>}>, counts: array{issues: int, all: int}, filter: string}
     */
    public function overview(string $filter, int $page, int $perPage = self::PER_PAGE): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'issues';
        $report = collect($this->report());
        $withIssues = $report->filter(fn (array $row): bool => $row['issues'] !== [])->values();
        $rows = $filter === 'all' ? $report : $withIssues;
        $page = max(1, $page);

        return [
            'rows' => new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page),
            'counts' => ['issues' => $withIssues->count(), 'all' => $report->count()],
            'filter' => $filter,
        ];
    }

    /** @return list<array{title: string, url: string, issues: list<string>}> */
    public function report(): array
    {
        return Event::query()->readable()->with(['venue', 'city', 'media', 'organizer'])->orderByDesc('updated_at')->limit(200)->get()
            ->map(function (Event $event): array {
                $issues = [];
                if (! app(EditorialContent::class)->indexable($event)) {
                    $issues[] = __('seo.audit.excluded');
                }
                if (blank($event->description) && blank($event->short_description)) {
                    $issues[] = __('seo.audit.description');
                }
                if (Poster::url($event) === null) {
                    $issues[] = __('seo.audit.image');
                }
                if ($event->venue === null && empty($event->custom_location)) {
                    $issues[] = __('seo.audit.location');
                }
                if ($event->price_type === PriceType::Unknown) {
                    $issues[] = __('seo.audit.price');
                }

                /*
                 * Il titolo viene accorciato da solo quando non sta nella
                 * misura di un risultato di ricerca, ma il taglio è cieco:
                 * tiene le prime parole. Se la parte che conta sta in fondo,
                 * un titolo corto scritto a mano fa meglio — e questo è il
                 * solo posto in cui un editore può accorgersene senza andare
                 * a contare i caratteri a una a una.
                 */
                if (str_contains(PageTitle::forEvent($event->title, $event->city->name), '…')) {
                    $issues[] = __('seo.audit.title_shortened');
                }

                if (app(StructuredData::class)->organizer($event) === []) {
                    $issues[] = 'Organizzatore non disponibile: indicare un organizzatore oppure il locale dell’evento.';
                }
                $text = strip_tags(($event->short_description ?? '').' '.($event->description ?? ''));
                if ($event->price_min > 0 && preg_match('/\b(ingresso libero|ingresso gratuito|gratis)\b/iu', $text)) {
                    $issues[] = 'Possibile incoerenza: il testo indica gratuità, ma il prezzo è maggiore di zero. Verificare prima di pubblicare.';
                }

                return ['title' => $event->title, 'url' => route('events.show', $event), 'issues' => $issues];
            })->all();
    }
}
