<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\CatalogReview;
use App\Services\Management\ManagedContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una recensione ricevuta dal soggetto: scrivere o cambiare la risposta
 * (`PUT …/reviews/{review}/reply`), toglierla (`DELETE …/reviews/{review}/reply`),
 * segnalarla alla redazione (`POST …/reviews/{review}/report`).
 *
 * La recensione si cerca solo fra quelle pubblicate del soggetto (404
 * altrimenti), come la pagina «Recensioni ricevute». Poi decide la policy:
 * `CatalogReviewPolicy::reply` e `removeReply` per la risposta; per la
 * segnalazione la stessa condizione della scheda pubblica
 * (`ReportCatalogReviewRequest`), cioè poter partecipare alla community.
 */
final class ReceivedReviewRequest extends FormRequest
{
    private ?CatalogReview $review = null;

    public function authorize(): bool
    {
        $user = $this->user();
        // Prima la recensione: una che non è del soggetto risponde 404, mai 403.
        $review = $this->review();

        return match ($this->action()) {
            'reply' => $user?->can('reply', $review) === true,
            'remove' => $user?->can('removeReply', $review) === true,
            default => $user?->canParticipateInCommunity() === true,
        };
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->action()) {
            // Gli stessi limiti del campo «Risposta pubblica» del pannello.
            'reply' => ['reply' => ['required', 'string', 'min:2', 'max:2000']],
            // Gli stessi della segnalazione dalla scheda pubblica.
            'report' => ['body' => ['required', 'string', 'min:10', 'max:3000']],
            default => [],
        };
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reply' => __('reviews.reply.field'), 'body' => __('reviews.report_reason')];
    }

    /** `reply`, `remove` o `report`, dal nome della rotta. */
    public function action(): string
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_ends_with($name, '.reply.update') => 'reply',
            str_ends_with($name, '.reply.destroy') => 'remove',
            default => 'report',
        };
    }

    public function review(): CatalogReview
    {
        return $this->review ??= ManagedContent::current()->reviews()->whereKey((int) $this->route('review'))->firstOrFail();
    }
}
