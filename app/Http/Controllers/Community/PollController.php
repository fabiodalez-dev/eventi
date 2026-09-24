<?php

declare(strict_types=1);

namespace App\Http\Controllers\Community;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Services\Community\EventPolls;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * I sondaggi fra amici.
 *
 * Tutto passa dal link: non esiste un elenco dei sondaggi altrui, e nemmeno
 * dei propri — chi propone ha il link, chi partecipa lo ha ricevuto. È la
 * ragione per cui il codice nell'indirizzo è lungo: è l'unica chiave.
 */
final class PollController extends Controller
{
    public function __construct(private readonly EventPolls $polls) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dates' => ['required', 'array', 'min:'.EventPolls::MIN_OPTIONS, 'max:'.EventPolls::MAX_OPTIONS],
            'dates.*' => ['integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:300'],
            'closes_at' => ['required', 'date'],
        ]);

        $poll = $this->polls->create($request->user(), array_map(intval(...), $data['dates']),
            $data['note'] ?? null, CarbonImmutable::parse($data['closes_at'])->endOfDay());

        return redirect()->route('polls.show', $poll->token);
    }

    public function show(Request $request, EventPoll $poll): View
    {
        $outcome = $this->polls->outcome($poll, $request->user());
        $title = __('polls.title', ['evento' => $poll->event->title]);

        return view('polls.show', ['poll' => $poll, 'outcome' => $outcome,
            'mine' => $poll->votes->where('user_id', $request->user()?->getKey())->pluck('event_poll_option_id')->all(),
            'meta' => new PageMeta($title, $title, indexable: false)]);
    }

    public function vote(Request $request, EventPoll $poll, EventPollOption $option): RedirectResponse
    {
        $this->polls->toggle($request->user(), $poll, $option);

        return back();
    }

    public function close(Request $request, EventPoll $poll): RedirectResponse
    {
        $this->polls->close($request->user(), $poll);

        return back()->with('status', __('polls.closed_status'));
    }

    public function leave(Request $request, EventPoll $poll): RedirectResponse
    {
        $this->polls->leave($request->user(), $poll);

        return back()->with('status', __('polls.left_status'));
    }
}
