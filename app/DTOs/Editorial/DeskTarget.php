<?php

declare(strict_types=1);

namespace App\DTOs\Editorial;

use Illuminate\Support\Arr;

/**
 * Dove porta un riquadro del riepilogo della redazione: l'elenco di un'area
 * con un filtro acceso o una scheda scelta.
 *
 * È la stessa destinazione per il pannello e per l'app. Il pannello ne fa
 * l'indirizzo della risorsa (`EventResource::getUrl('index', ['filters' =>
 * ['status' => ['value' => 'pending']]])`, vedi `DeskLinks`); l'app la legge
 * come `area` più i parametri della convenzione degli elenchi
 * (`filter[status]=pending`, `filter[open]=1`, `tab=pending`) e, finché
 * l'area non è nativa, apre `web_path` nel browser.
 *
 * Un filtro di Filament è di due tipi: a scelta (`['value' => 'pending']`)
 * o acceso/spento (`['isActive' => true]`). Qui il primo è una stringa e il
 * secondo è `true`.
 */
final readonly class DeskTarget
{
    /** Le pagine del pannello di ogni area, relative al sito. */
    public const PATHS = [
        'events' => 'admin/events',
        'venues' => 'admin/venues',
        'applications' => 'admin/venue-applications',
        'reports' => 'admin/reports',
        'import_sources' => 'admin/import-sources',
        'users' => 'admin/users',
    ];

    /**
     * @param  array<string, string|true>  $filters
     */
    public function __construct(
        public string $area,
        public array $filters = [],
        public ?string $tab = null,
    ) {}

    /** L'elenco dell'area con un solo filtro acceso. */
    public static function filtered(string $area, string $filter, string|true $value): self
    {
        return new self($area, [$filter => $value]);
    }

    /** L'elenco dell'area su una scheda. */
    public static function tab(string $area, string $tab): self
    {
        return new self($area, [], $tab);
    }

    /**
     * I parametri dell'indirizzo del pannello, nella forma delle tabelle di
     * Filament.
     *
     * @return array<string, mixed>
     */
    public function panelParameters(): array
    {
        $parameters = [];
        if ($this->filters !== []) {
            $parameters['filters'] = array_map(
                static fn (string|bool $value): array => $value === true ? ['isActive' => true] : ['value' => $value],
                $this->filters,
            );
        }
        if ($this->tab !== null) {
            $parameters['tab'] = $this->tab;
        }

        return $parameters;
    }

    /** La pagina del pannello già filtrata, relativa al sito: `admin/events?filters[status][value]=pending`. */
    public function webPath(): string
    {
        $query = Arr::query($this->panelParameters());

        return self::PATHS[$this->area].($query === '' ? '' : '?'.$query);
    }

    /**
     * `filters` è sempre un oggetto JSON, anche vuoto (`{}` e non `[]`).
     *
     * @return array{area: string, tab: string|null, filters: array<string, string>|\stdClass, web_path: string}
     */
    public function toArray(): array
    {
        // Per l'app i sì/no sono `1`, come nella convenzione degli elenchi.
        $filters = array_map(static fn (string|bool $value): string => $value === true ? '1' : (string) $value, $this->filters);

        return [
            'area' => $this->area,
            'tab' => $this->tab,
            'filters' => $filters === [] ? new \stdClass : $filters,
            'web_path' => $this->webPath(),
        ];
    }
}
