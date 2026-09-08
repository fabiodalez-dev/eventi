<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Pages;

use App\Filament\Admin\Pages\SponsorshipAnalytics;
use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Widgets\SponsorshipHealthWidget;
use App\Filament\Admin\Widgets\SponsorshipTrendWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;

class ListSponsorships extends ListRecords
{
    protected static string $resource = SponsorshipResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('analytics')->label('Statistiche e registro clic')->url(SponsorshipAnalytics::getUrl())->color('gray'),
            Action::make('grants')->label(__('promotions.title'))->url(SponsorshipGrantResource::getUrl())->color('gray'),
            /*
             * L'esportazione dell'elenco, coi filtri applicati.
             *
             * Esporta **quello che si sta guardando**: se la scheda «In
             * attesa» è aperta e c'è una ricerca in corso, il file contiene
             * quelle righe. Un'esportazione che ignora i filtri produce un
             * foglio da millequattrocento righe a chi ne voleva dodici, e chi
             * lo riceve non ha modo di sapere che non è quello che aveva
             * chiesto.
             */
            ExportAction::make()
                ->label(__('admin.actions.export'))
                ->color('gray'),

            CreateAction::make(),
        ];
    }

    /**
     * I due riquadri stanno **sopra** l'elenco, non in una pagina a parte.
     *
     * Chi apre le campagne lo fa per una di due ragioni: aggiungerne una, o
     * capire come stanno andando. La seconda e' la piu' frequente e non ha mai
     * avuto un posto: c'erano due colonne di numeri cumulativi in fondo alla
     * tabella, che dicono un totale e non un andamento. Metterli altrove
     * avrebbe voluto dire che quasi nessuno li guardava.
     *
     * @return array<mixed>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SponsorshipHealthWidget::class,
            SponsorshipTrendWidget::class,
        ];
    }
}
