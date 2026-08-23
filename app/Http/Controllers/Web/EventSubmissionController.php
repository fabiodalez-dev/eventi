<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CreateEventSubmission;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreEventSubmissionRequest;
use App\Services\Search\FilterFacets;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * `/proponi-evento` (§11.1).
 *
 * Chi propone non pubblica: apre una pratica. La conferma lo dice chiaramente,
 * perché una proposta che sembra pubblicata e non compare da nessuna parte fa
 * perdere fiducia più di un rifiuto motivato.
 */
final class EventSubmissionController extends Controller
{
    use InteractsWithCity;

    public function create(FilterFacets $facets): View
    {
        $city = $this->city();

        return view('submissions.create', [
            'city' => $city,
            'venues' => $facets->venues($city),
            'meta' => new PageMeta(
                title: __('forms.submission.title'),
                heading: __('forms.submission.title'),
                description: __('forms.submission.lead', ['city' => $city->name]),
                canonical: route('submissions.create'),
            ),
        ]);
    }

    public function store(StoreEventSubmissionRequest $request, CreateEventSubmission $action): RedirectResponse
    {
        $data = $request->validated();

        $action->handle($this->city(), [
            'title' => (string) $data['title'],
            'raw_text' => isset($data['raw_text']) ? (string) $data['raw_text'] : null,
            'venue_hint' => isset($data['venue_hint']) ? (string) $data['venue_hint'] : null,
            'venue_id' => isset($data['venue_id']) ? (int) $data['venue_id'] : null,
            'starts_at_hint' => isset($data['starts_at_hint']) ? (string) $data['starts_at_hint'] : null,
            'contact_name' => isset($data['contact_name']) ? (string) $data['contact_name'] : null,
            'contact_email' => (string) $data['contact_email'],
        ], $request->ip());

        return to_route('submissions.create')->with('status', __('forms.submission.received'));
    }
}
