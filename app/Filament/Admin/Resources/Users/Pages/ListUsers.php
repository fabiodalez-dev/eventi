<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
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
            'admins' => Tab::make(__('users.admins'))->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', [UserRole::Admin->value, UserRole::SuperAdmin->value]))),
            'staff' => Tab::make(__('users.staff'))->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', UserRole::Moderator->value))),
            'owners' => Tab::make(__('users.owners'))->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('venue_user.role', VenueRole::Owner->value))),
            'editors' => Tab::make(__('users.editors'))->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('venue_user.role', VenueRole::Editor->value))),
            'users' => Tab::make(__('users.users'))->modifyQueryUsing(fn (Builder $query): Builder => $query->whereDoesntHave('venues')->whereDoesntHave('roles', fn (Builder $roles): Builder => $roles->where('name', '!=', UserRole::User->value))),
            'unlinked' => Tab::make(__('users.unlinked'))->modifyQueryUsing(fn (Builder $query): Builder => $query->whereDoesntHave('venues')->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', [UserRole::VenueOwner->value, UserRole::VenueEditor->value]))),
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
            ExportAction::make()
                ->label(__('admin.actions.export'))
                ->color('gray'),

            CreateAction::make(),
        ];
    }
}
