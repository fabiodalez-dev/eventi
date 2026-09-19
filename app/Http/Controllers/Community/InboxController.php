<?php

declare(strict_types=1);

namespace App\Http\Controllers\Community;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carpool\ReadNoticesRequest;
use App\Services\Carpool\UnifiedNotifications;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InboxController extends Controller
{
    public function index(Request $request): View
    {
        return view('community.inbox', ['notifications' => $request->user()->notifications()->paginate(30),
            'meta' => new PageMeta(__('community.inbox'), __('community.inbox'), indexable: false)]);
    }

    /** Il frammento della tendina sotto la campanella: gli ultimi avvisi, non una pagina. */
    public function latest(Request $request): Response
    {
        return response()->view('community.inbox-latest', ['notifications' => $request->user()->notifications()->limit(6)->get()])
            ->header('Cache-Control', 'no-store, private')
            // Il limite fino a cui «segna tutti come letti» può arrivare: un avviso giunto dopo l'apertura resta nuovo.
            ->header('X-Inbox-Watermark', (string) app(UnifiedNotifications::class)->watermark($request->user()));
    }

    public function readAll(ReadNoticesRequest $request): RedirectResponse
    {
        app(UnifiedNotifications::class)->readAll($request->user(), $request->filled('through') ? $request->integer('through') : null);

        return back();
    }
}
