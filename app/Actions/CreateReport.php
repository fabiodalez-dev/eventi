<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra una segnalazione dal pubblico: è il "segnala un errore" della
 * scheda evento e della scheda locale (§11.5, §14.6).
 *
 * Non serve un account: chi si accorge che un orario è sbagliato spesso non è
 * iscritto a nulla. L'indirizzo email è facoltativo e serve solo a poter
 * rispondere.
 */
final class CreateReport
{
    public function handle(
        Model $reportable,
        ReportReason $reason,
        ?string $note = null,
        ?string $email = null,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): Report {
        $report = new Report([
            'reason' => $reason,
            'note' => $note,
            'reporter_email' => $email,
            'reporter_user_id' => $userId,
            'status' => ReportStatus::Pending,
            'ip_address' => $ipAddress,
        ]);

        $report->reportable()->associate($reportable);
        $report->save();

        return $report;
    }
}
