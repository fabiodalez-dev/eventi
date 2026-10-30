<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Shared\EventAnalyticsNavigation;
use App\Filament\Support\PublicationActions;
use App\Filament\Venue\Pages\Social;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\EventActions;
use App\Filament\Venue\Support\EventPublication;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * La scheda di un evento già inserito.
 *
 * Le date non stanno qui ma nella sezione dedicata sotto al modulo: è la
 * stessa scelta del pannello di redazione (D24, punto 5), e per la stessa
 * ragione — due moduli che scrivono le stesse righe nella stessa pagina si
 * sovrascrivono a vicenda.
 */
class EditEvent extends EditRecord
{
    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public function getSubNavigation(): array
    {
        return EventAnalyticsNavigation::items((int) $this->getRecord()->getKey(), false, true);
    }

    protected static string $resource = EventResource::class;

    /** I campi che, cambiati su un evento pubblicato, chiedono una nuova revisione. */
    private const CONTENT_FIELDS = [
        'title', 'description', 'ticket_url', 'booking_url', 'external_links', 'facts', 'content_details',
        'seo', 'organizer_name', 'organizer_url', 'custom_location',
    ];

    private bool $sentToReview = false;

    private bool $posterUploaded = false;

    protected function beforeValidate(): void
    {
        $this->posterUploaded = $this->posterChanged() || $this->tiersChanged();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Solo colonne offerte dal modulo: i campi amministrativi e il tenant
        // non diventano scrivibili aggiungendoli a una richiesta Livewire.
        if (blank($data['custom_location']['address'] ?? null)) {
            $data['custom_location'] = null;
        } else {
            foreach (['lat', 'lng'] as $coordinate) {
                $data['custom_location'][$coordinate] = filled($data['custom_location'][$coordinate] ?? null) ? (float) $data['custom_location'][$coordinate] : null;
            }
        }

        $filtered = Arr::only($data, [
            'title', 'description', 'category_id', 'price_type', 'price_min', 'price_max',
            'ticket_url', 'booking_url', 'external_links', 'facts', 'content_details',
            'seo', 'organizer_name', 'organizer_url', 'custom_location',
        ]);

        /*
         * Un locale che non pubblica da sé passa dalla redazione anche quando
         * riscrive un evento già online: altrimenti la revisione varrebbe solo
         * per la prima versione, e testo e collegamenti cambiati dopo
         * andrebbero in pagina senza che nessuno li abbia letti. Prezzo e
         * categoria restano liberi: sono correzioni, non contenuto nuovo.
         */
        $record = $this->getRecord();
        if ($record instanceof Event && $record->status === EventStatus::Published && Gate::denies('publish', $record)
            && ($this->contentChanged($record, $filtered) || $this->posterUploaded)) {
            $filtered['status'] = EventStatus::Pending;
            $this->sentToReview = true;
        }

        return $filtered;
    }

    protected function afterSave(): void
    {
        if ($this->sentToReview) {
            $this->sentToReview = false;
            EventPublication::notify(EventStatus::Pending);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contentChanged(Event $record, array $data): bool
    {
        foreach (array_intersect(self::CONTENT_FIELDS, array_keys($data)) as $field) {
            $current = $record->getAttribute($field);
            /*
             * Si confronta solo quello che il modulo del locale mostra: le
             * chiavi che non ha (l'indicizzazione, riservata alla redazione e
             * rimessa dal modello a ogni salvataggio, o ciò che ha scritto un
             * import) non sono un cambiamento di chi salva. Le liste no: lì
             * una voce in meno è contenuto tolto.
             */
            if (is_array($current) && is_array($data[$field]) && ! array_is_list($data[$field])) {
                $current = array_intersect_key($current, $data[$field]);
            }
            if (json_encode(self::normalize($current)) !== json_encode(self::normalize($data[$field]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una locandina nuova è contenuto nuovo quanto un testo riscritto, e va in
     * scheda, nei feed e nella grafica social. Il campo non passa da `$data`
     * e salva il file già mentre il modulo si convalida: si guarda quindi lo
     * stato grezzo prima della convalida, dove i media già salvati sono i loro
     * uuid e un caricamento è un file temporaneo. Togliere la locandina non
     * porta niente di nuovo in pagina.
     */
    private function posterChanged(): bool
    {
        $state = $this->data['poster_media'] ?? [];

        return is_array($state) && array_filter($state, fn (mixed $file): bool => ! is_string($file)) !== [];
    }

    /**
     * Il listino dei biglietti ha nome, collegamento e nota che vanno in
     * scheda come il testo: una voce nuova o riscritta è contenuto nuovo.
     * Prezzo e disponibilità no, come prezzo e categoria dell'evento; togliere
     * una voce non porta niente in pagina. Il ripetitore salva la relazione
     * per conto suo, quindi si guarda lo stato grezzo come per la locandina.
     */
    private function tiersChanged(): bool
    {
        $record = $this->getRecord();
        $state = $this->data['ticketTiers'] ?? [];
        if (! $record instanceof Event || ! is_array($state)) {
            return false;
        }

        $key = fn (array $tier): string => (string) json_encode(array_map(
            fn (mixed $value): string => trim((string) (is_scalar($value) ? $value : '')),
            [$tier['name'] ?? '', $tier['url'] ?? '', $tier['note'] ?? ''],
        ));
        $current = $record->ticketTiers()->get(['name', 'url', 'note'])->map(fn ($tier): string => $key($tier->only(['name', 'url', 'note'])))->all();

        foreach ($state as $tier) {
            if (is_array($tier) && ! in_array($key($tier), $current, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Il testo come lo legge il pubblico, più i collegamenti: l'editor riscrive
     * a modo suo una descrizione salvata come testo semplice (paragrafi,
     * spazi), e quello non è un cambiamento. Un indirizzo cambiato dentro un
     * link sì, anche se la parola resta la stessa.
     */
    private static function text(mixed $value): string
    {
        $html = (string) (is_scalar($value) ? $value : '');
        preg_match_all('/\b(?:href|src)\s*=\s*["\']([^"\']*)["\']/i', $html, $links);

        return (string) preg_replace('/\s+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)).'|'.implode(' ', $links[1]);
    }

    /** Riduce un valore al suo contenuto, senza vuoti e con le chiavi in ordine: lo stesso contenuto, scritto due volte, è uguale. */
    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }
        if (! is_array($value)) {
            // Un paragrafo vuoto dell'editor non è un testo, e 45.4 vale "45.4".
            $text = self::text($value);

            return $text === '|' ? null : $text;
        }
        $list = array_is_list($value);
        $value = array_filter(array_map(self::normalize(...), $value), fn (mixed $item): bool => $item !== null);
        if ($list) {
            $value = array_values($value);
        } else {
            ksort($value);
        }

        return $value === [] ? null : $value;
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            PublicationActions::schedule(),
            PublicationActions::cancel(),
            Action::make('pagePreview')->label(__('promotions.preview'))->url(fn () => route('events.preview', $this->getRecord()))->openUrlInNewTab()->color('gray'),
            Action::make('social')->label(__('social.preview'))->url(fn () => Social::getUrl(['event' => $this->getRecord()->getKey()])),
            EventActions::viewOnSite(),
            EventActions::publish(),
            EventActions::repeat(),
            EventActions::duplicate(),
            DeleteAction::make(),
        ];
    }
}
