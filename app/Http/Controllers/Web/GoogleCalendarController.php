<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\CalendarWizardRequest;
use App\Http\Requests\Web\GoogleCalendarCallbackRequest;
use App\Models\Category;
use App\Models\GoogleCalendarConnection;
use App\Models\Venue;
use App\Services\Calendar\GoogleCalendarAuthorization;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleCalendarSync;
use App\Support\CurrentCity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class GoogleCalendarController extends Controller
{
    public function mobile(CalendarWizardRequest $request, int $user): RedirectResponse
    {
        abort_unless($user === $request->user()->id, 403, __('google_calendar.wrong_account'));

        return redirect()->route('google-calendar.index', $request->safe()->except('step'));
    }

    public function index(CalendarWizardRequest $request, GoogleCalendarClient $client): Response
    {
        $connection = GoogleCalendarConnection::where('user_id', $request->user()->id)->first();
        $selection = $request->safe()->except('step');
        $selection = $selection === [] ? ($connection->selection ?? []) : $selection;

        return response()->view('account.google-calendar', [
            'configured' => $client->configured(),
            'connection' => $connection,
            'selection' => $selection,
            'categories' => Category::active()->whereIn('slug', $selection['categories'] ?? [])->pluck('name'),
            'venue' => Venue::where('slug', $selection['venue'] ?? null)->value('name'),
            'meta' => new PageMeta(title: __('google_calendar.title'), heading: __('google_calendar.title'), indexable: false),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function connect(CalendarWizardRequest $request, GoogleCalendarClient $client): RedirectResponse
    {
        abort_unless($client->configured(), 503, __('google_calendar.unconfigured'));
        abort_unless(app(CurrentCity::class)->get() !== null, 503);
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('google_calendar_oauth', [
            'state' => hash('sha256', $state), 'verifier' => $verifier, 'user_id' => $request->user()->id,
            'city_id' => app(CurrentCity::class)->get()->id, 'expires' => now()->addMinutes(10)->timestamp,
            'selection' => $request->safe()->except('step'),
        ]);

        return redirect()->away($client->authorizationUrl($state, $verifier));
    }

    public function callback(GoogleCalendarCallbackRequest $request, GoogleCalendarAuthorization $authorization): RedirectResponse
    {
        $pending = $request->session()->pull('google_calendar_oauth');
        abort_unless(is_array($pending) && $pending['user_id'] === $request->user()->id
            && $pending['expires'] >= now()->timestamp && hash_equals($pending['state'], hash('sha256', $request->string('state')->toString())), 403);
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('google-calendar.index')->with('google_calendar_message', __('google_calendar.denied'));
        }
        try {
            $authorization->complete($request->user()->id, $request->string('code')->toString(), $pending);
        } catch (\Throwable) {
            return redirect()->route('google-calendar.index')->with('google_calendar_message', __('google_calendar.failed'));
        }

        return redirect()->route('google-calendar.index')->with('google_calendar_message', __('google_calendar.queued'));
    }

    public function update(CalendarWizardRequest $request, GoogleCalendarAuthorization $authorization): RedirectResponse
    {
        $authorization->update($request->user()->id, $request->safe()->except('step'));

        return redirect()->route('google-calendar.index')->with('google_calendar_message', __('google_calendar.queued'));
    }

    public function disconnect(Request $request, GoogleCalendarSync $sync): RedirectResponse
    {
        try {
            $sync->disconnect($request->user()->id);
        } catch (\Throwable) {
            return back()->with('google_calendar_message', __('google_calendar.disconnect_retry'));
        }

        return redirect()->route('google-calendar.index')->with('google_calendar_message', __('google_calendar.disconnected'));
    }
}
