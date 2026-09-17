<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ConsentCategory;
use App\Http\Controllers\Controller;
use App\Services\Analytics\EventShares;
use App\Support\Consent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EventShareController extends Controller
{
    public function open(Request $request, string $code, EventShares $shares): RedirectResponse
    {
        $link = $shares->resolve($code);
        if ($request->isMethod('GET') && $this->countable($request)) {
            $shares->record($link, false);
        }

        return redirect($shares->destination($link), 302)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    public function share(Request $request, string $code, EventShares $shares): Response
    {
        $link = $shares->resolve($code);
        if ($this->countable($request)) {
            $shares->record($link, true);
        }

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    private function countable(Request $request): bool
    {
        return app(Consent::class)->allows(ConsentCategory::Statistics)
            && ! preg_match('/bot|crawl|spider|preview|facebookexternalhit|facebot|whatsapp|telegram|slack|discord|linkedin|pinterest|skype|twitter|headless/i', $request->userAgent() ?? '')
            && ! preg_match('/prefetch|prerender/i', $request->header('Purpose', '').' '.$request->header('Sec-Purpose', '').' '.$request->header('X-Purpose', ''));
    }
}
