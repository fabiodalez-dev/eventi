<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CreateReport;
use App\DTOs\PageMeta;
use App\Enums\ReportReason;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreReportRequest;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * "Segnala un errore" delle schede pubbliche (§11.5, §14.6).
 *
 * Un orario sbagliato lo vede prima chi ci va che chi lo ha scritto: la
 * segnalazione è aperta a chiunque, senza account, e finisce nella coda della
 * moderazione con lo stato `pending`.
 */
final class ReportController extends Controller
{
    use InteractsWithCity;

    public function createForEvent(string $slug): View
    {
        return $this->form($this->event($slug));
    }

    public function storeForEvent(StoreReportRequest $request, CreateReport $action, string $slug): RedirectResponse
    {
        $event = $this->event($slug);

        $this->record($request, $action, $event);

        return to_route('events.show', $event)->with('status', __('forms.report.received'));
    }

    public function createForVenue(string $slug): View
    {
        return $this->form($this->venue($slug));
    }

    public function storeForVenue(StoreReportRequest $request, CreateReport $action, string $slug): RedirectResponse
    {
        $venue = $this->venue($slug);

        $this->record($request, $action, $venue);

        return to_route('venues.show', $venue)->with('status', __('forms.report.received'));
    }

    private function form(Event|Venue $subject): View
    {
        $title = $subject instanceof Event ? $subject->title : $subject->name;

        return view('reports.create', [
            'subject' => $subject,
            'subjectTitle' => $title,
            'action' => $subject instanceof Event
                ? route('events.report.store', ['slug' => $subject->slug])
                : route('venues.report.store', ['slug' => $subject->slug]),
            'back' => $subject instanceof Event
                ? route('events.show', $subject)
                : route('venues.show', $subject),
            'reasons' => ReportReason::options(),
            'meta' => new PageMeta(
                title: __('forms.report.title'),
                heading: __('forms.report.heading', ['subject' => $title]),
                description: __('forms.report.lead'),
                indexable: false,
            ),
        ]);
    }

    private function record(StoreReportRequest $request, CreateReport $action, Model $reportable): void
    {
        $userId = $request->user()?->getKey();

        $action->handle(
            reportable: $reportable,
            reason: $request->reason(),
            note: $request->validated('note') === null ? null : (string) $request->validated('note'),
            email: $request->validated('reporter_email') === null ? null : (string) $request->validated('reporter_email'),
            userId: is_int($userId) ? $userId : null,
            ipAddress: $request->ip(),
        );
    }

    private function event(string $slug): Event
    {
        $event = Event::query()
            ->inCity($this->city())
            ->readable()
            ->where('slug', $slug)
            ->first();

        abort_if($event === null, 404);

        return $event;
    }

    private function venue(string $slug): Venue
    {
        $venue = Venue::query()
            ->inCity($this->city())
            ->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended])
            ->where('slug', $slug)
            ->first();

        abort_if($venue === null, 404);

        return $venue;
    }
}
