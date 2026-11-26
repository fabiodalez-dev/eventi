<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reports\Pages;

use App\Actions\Moderation\ResolveReport;
use App\Enums\ReportStatus;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    /**
     * Chi ha deciso e quando non sono campi del modulo: sarebbe una firma che
     * si può falsificare. Li scrive `ResolveReport`, la stessa azione che usa
     * l'app della redazione, ed è ciò che rende tracciabile un'azione di
     * moderazione (§14.6).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $moderator = auth()->user();
        abort_unless($moderator instanceof User && $record instanceof Report, 403);
        $status = $data['status'] instanceof ReportStatus ? $data['status'] : ReportStatus::from((string) $data['status']);
        $note = $data['resolution_note'] ?? null;

        return app(ResolveReport::class)->handle($record, $moderator, $status, is_string($note) ? $note : null);
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
