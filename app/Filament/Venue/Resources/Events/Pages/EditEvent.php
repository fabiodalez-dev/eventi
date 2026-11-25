<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Shared\EventAnalyticsNavigation;
use App\Filament\Support\PublicationActions;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\CurrentVenue;
use App\Filament\Venue\Support\EventActions;
use App\Filament\Venue\Support\EventPublication;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\VenueEventEditor;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\EditRecord;

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

    private bool $sentToReview = false;

    private bool $posterUploaded = false;

    protected function beforeValidate(): void
    {
        $this->posterUploaded = $this->posterChanged() || $this->tiersChanged();
    }

    /**
     * Le colonne del modulo e il ritorno in revisione: la regola sta in
     * `VenueEventEditor`, la stessa che usa l'API di gestione. Un locale che
     * non pubblica da sé passa dalla redazione anche quando riscrive un evento
     * già online; prezzo e categoria restano liberi.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $user = auth()->user();
        if (! $record instanceof Event || ! $user instanceof User) {
            return [];
        }

        // Il luogo si normalizza sempre, anche quando il modulo lo manda vuoto.
        $data['custom_location'] ??= null;
        [$filtered, $this->sentToReview] = app(VenueEventEditor::class)->prepareUpdate($record, CurrentVenue::get(), $user, $data, $this->posterUploaded);

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
     * Il listino dei biglietti: una voce nuova o riscritta è contenuto nuovo
     * (regola di `VenueEventEditor::tiersChanged`). Il ripetitore salva la
     * relazione per conto suo, quindi si guarda lo stato grezzo come per la
     * locandina.
     */
    private function tiersChanged(): bool
    {
        $record = $this->getRecord();
        $state = $this->data['ticketTiers'] ?? [];

        return $record instanceof Event && is_array($state) && app(VenueEventEditor::class)->tiersChanged($record, $state);
    }

    /**
     * In vista le due azioni che si usano di più su un evento esistente —
     * Duplica e Ripeti, perché quasi tutti i locali fanno serate fisse (§10.3)
     * — e Pubblica quando serve. Le altre pagine dell'evento (anteprima,
     * grafiche social, sponsorizzazione, biglietteria, sito) stanno nel menu
     * «Altre azioni», insieme a programmazione, annullamento ed eliminazione.
     *
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        $links = collect(EventResource::eventLinks($this->eventRecord(), withEdit: false))
            ->reject(fn (array $link): bool => in_array($link['key'], ['statistics', 'site'], true))
            ->map(fn (array $link): Action => Action::make('link_'.$link['key'])
                ->label($link['label'])
                ->icon($link['icon'])
                ->url($link['url']))
            ->values()
            ->all();

        return [
            EventActions::publish(),
            EventActions::duplicate(),
            EventActions::repeat(),
            ActionGroup::make([
                Action::make('pagePreview')->label(__('promotions.preview'))->icon('heroicon-o-eye')->url(fn () => route('events.preview', $this->getRecord()))->openUrlInNewTab(),
                ...$links,
                EventActions::viewOnSite(),
                PublicationActions::schedule(),
                PublicationActions::cancel(),
                DeleteAction::make(),
            ])->label(__('manage.event_list.more_actions'))->tooltip(__('manage.event_list.more_actions'))->button()->color('gray')->icon('heroicon-o-ellipsis-horizontal'),
        ];
    }

    private function eventRecord(): Event
    {
        /** @var Event $record */
        $record = $this->getRecord();

        return $record;
    }
}
