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

final class InboxController extends Controller
{
    public function index(Request $request): View
    {
        return view('community.inbox', ['notifications' => $request->user()->notifications()->paginate(30),
            'meta' => new PageMeta(__('community.inbox'), __('community.inbox'), indexable: false)]);
    }

    public function readAll(ReadNoticesRequest $request): RedirectResponse
    {
        app(UnifiedNotifications::class)->readAll($request->user(), $request->filled('through') ? $request->integer('through') : null);

        return back();
    }
}
