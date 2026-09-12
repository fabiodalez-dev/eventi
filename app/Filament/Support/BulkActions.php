<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;

/** Per-record authorization supplements Filament's resource-level deleteAny check. */
final class BulkActions
{
    /**
     * Il gruppo predefinito: cancellazione, autorizzata riga per riga.
     *
     * Le tabelle lo usano al posto di comporre a mano
     * `BulkActionGroup::make([DeleteBulkAction::make()])`, così la regola sta
     * in un posto solo e la decima risorsa non può dimenticarsela.
     */
    public static function make(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::delete(),
        ]);
    }

    public static function delete(): DeleteBulkAction
    {
        return DeleteBulkAction::make()->authorizeIndividualRecords('delete');
    }
}
