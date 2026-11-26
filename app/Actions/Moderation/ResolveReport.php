<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * L'esito di una segnalazione (§14.6): stato e nota di risoluzione, firmati
 * con chi ha deciso e quando.
 *
 * Chi ha deciso e quando non arrivano dal modulo: sarebbe una firma che si
 * può falsificare. Li scrive questa azione, ed è ciò che rende tracciabile
 * un'azione di moderazione. Motivo, testo e contatto di chi ha segnalato non
 * si toccano: una segnalazione riscrivibile non è più una prova di quello che
 * è stato detto.
 *
 * La chiamano il pannello (`EditReport`) e l'app della redazione
 * (`PATCH /api/v1/editorial/reports/{report}`). Come `ModerateComment`, la
 * policy si chiede anche qui: il pulsante la chiede già, questa riga
 * protegge l'azione da qualunque punto venga chiamata.
 */
final class ResolveReport
{
    public function handle(Report $report, User $moderator, ReportStatus $status, ?string $resolutionNote): Report
    {
        Gate::forUser($moderator)->authorize('update', $report);

        $report->forceFill([
            'status' => $status,
            'resolution_note' => $resolutionNote,
            'reviewed_by' => $moderator->getKey(),
            'reviewed_at' => now(),
        ])->save();

        return $report;
    }
}
