<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CookieDeclarations\Pages;

use App\Enums\ConsentCategory;
use App\Filament\Admin\Resources\CookieDeclarations\CookieDeclarationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Il registro dei cookie: una scheda per finalità.
 *
 * `ManageRecords` e non `ListRecords`: si crea e si modifica in una finestra,
 * senza cambiare pagina. Sono righe di quattro campi — aprire una scheda
 * intera per scriverne una sarebbe un giro inutile per un elenco che si
 * compila una volta e si ritocca due volte l'anno.
 */
class ManageCookieDeclarations extends ManageRecords
{
    protected static string $resource = CookieDeclarationResource::class;

    public function getTitle(): string
    {
        return __('cookies.title');
    }

    public function getSubheading(): ?string
    {
        return __('cookies.lead');
    }

    /** @return array<Tab> */
    public function getTabs(): array
    {
        $schede = [];

        foreach (ConsentCategory::cases() as $categoria) {
            $schede[$categoria->value] = Tab::make($categoria->label())
                ->badge(CookieDeclarationResource::getEloquentQuery()->where('category', $categoria)->count())
                /* La descrizione della finalità sta qui e non in una nota a
                   parte: chi aggiunge un cookie deve avere sotto gli occhi
                   cosa sta dichiarando, non doverlo cercare. */
                ->badgeTooltip($categoria->description())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('category', $categoria));
        }

        return $schede;
    }

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cookies.actions.add'))
                /* La finalità della scheda aperta arriva già compilata: si sta
                   guardando un elenco di annunci, il cookie che si aggiunge è
                   un cookie di annunci. Chiederlo di nuovo è una domanda con
                   una risposta ovvia, e le risposte ovvie si sbagliano. */
                ->mutateDataUsing(function (array $data): array {
                    $data['category'] ??= $this->activeTab;

                    return $data;
                }),
        ];
    }
}
