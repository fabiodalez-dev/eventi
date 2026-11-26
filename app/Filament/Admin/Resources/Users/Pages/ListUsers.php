<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\Permission;
use App\Enums\RegistrationState;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Models\User;
use App\Services\Users\UserDirectory;
use App\Support\SecurityLog;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return __('users.description');
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('users.all')),
            /* Le iscrizioni da completare, con il loro numero: la scheda è il
               filtro rapido che apre anche il riquadro del riepilogo. */
            'pending' => Tab::make(__('users.registration.tab'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->badge(fn (): ?int => ($count = RegistrationState::scopeIncomplete(User::query())->count()) > 0 ? $count : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'pending')),
            'admins' => Tab::make(__('users.admins'))->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'admins')),
            'staff' => Tab::make(__('users.staff'))->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'staff')),
            'owners' => Tab::make(__('users.owners'))->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'owners')),
            'editors' => Tab::make(__('users.editors'))->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'editors')),
            'users' => Tab::make(__('users.users'))->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'users')),
            'unlinked' => Tab::make(__('users.unlinked'))->modifyQueryUsing(fn (Builder $query): Builder => UserDirectory::tab($query, 'unlinked')),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('venues')->label(__('users.venues'))->url(VenueResource::getUrl())->color('gray'),
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
            /*
             * L'elenco intero con le email è un'esportazione di dati
             * personali: la fa chi amministra le persone (`users.manage`),
             * mai il moderatore, e resta nel registro di sicurezza.
             */
            ExportAction::make()
                ->label(__('admin.actions.export'))
                ->color('gray')
                ->authorize(fn (): bool => auth()->user()?->can(Permission::ManageUsers->value) === true)
                ->before(function (): void {
                    $staff = auth()->user();
                    SecurityLog::scrivi('utenti_esportati', causa: $staff instanceof User ? $staff : null);
                }),

            CreateAction::make(),
        ];
    }
}
