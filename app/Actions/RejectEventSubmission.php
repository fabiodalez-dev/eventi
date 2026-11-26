<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SubmissionStatus;
use App\Models\EventSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * «Rifiuta proposta» (R-SB-04): la proposta dal pubblico resta com'era, con
 * lo stato, il motivo interno e chi l'ha esaminata. Il motivo non va a chi
 * l'ha proposta: è una nota della redazione.
 *
 * Scrive solo una proposta ancora in attesa e senza evento: se nel frattempo
 * qualcuno l'ha già pubblicata o rifiutata non succede niente, e il valore
 * di ritorno lo dice (il pannello torna comunque all'elenco).
 */
final class RejectEventSubmission
{
    /** Il tetto del motivo, lo stesso del campo del pannello. */
    public const NOTES_MAX = 2000;

    public function handle(EventSubmission $submission, User $reviewer, string $notes): bool
    {
        Gate::forUser($reviewer)->authorize('update', $submission);

        return EventSubmission::query()->whereKey($submission)->pending()->whereNull('event_id')->update([
            'status' => SubmissionStatus::Rejected, 'notes' => $notes,
            'reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(),
        ]) > 0;
    }
}
