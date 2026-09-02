<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Pages;

use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Widgets\SponsorshipHealthWidget;
use App\Filament\Admin\Widgets\SponsorshipTrendWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSponsorships extends ListRecords
{
    protected static string $resource = SponsorshipResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
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
