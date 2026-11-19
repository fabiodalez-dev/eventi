<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ConsentCategory;
use App\Http\Controllers\Controller;
use App\Services\Analytics\EventShares;
use App\Support\Consent;
use App\Support\IpBucket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

final class EventShareController extends Controller
{
    /**
     * Quante aperture dello stesso link conta, al giorno, uno stesso
     * indirizzo. Il tetto vive solo nella cache (l'indirizzo non si salva):
     * serve a non far gonfiare il contatore con una serie di GET. È largo
     * perché dietro lo stesso indirizzo IPv4 di un operatore mobile ci sono
     * molte persone.
     */
    private const DAILY_OPEN_CAP = 10;

    /**
     * L'apertura di un link breve si conta anche senza consenso (decisione
     * del 19/11/2026): è un solo contatore aggregato per link e per giorno
     * (`event_share_daily.clicks`), senza cookie — la rotta non avvia la
     * sessione —, senza identificativi e senza IP. Restano fuori anteprime,
     * bot riconosciuti e navigazioni anticipate del browser. Le altre misure
     * (le visite alla scheda, il pulsante «Condividi») restano legate al
     * consenso alle statistiche. Il tetto per indirizzo vale solo per il
     * conteggio: il rinvio alla scheda c'è sempre.
     */
    public function open(Request $request, string $code, EventShares $shares): RedirectResponse
    {
        $link = $shares->resolve($code);
        if ($request->isMethod('GET') && $this->genuineVisit($request) && $this->withinOpenCap($request, (string) $link->getKey())) {
            $shares->record($link, false);
        }

        return redirect($shares->destination($link), 302)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Il QR dello stesso codice breve: un'immagine, non una pagina. Non conta
     * nulla — conta l'apertura del link che ci sta dentro — e si scarica in
     * vettoriale perche' il posto dove finisce e' un volantino stampato.
     */
    public function qr(Request $request, string $code, EventShares $shares): Response
    {
        $link = $shares->resolve($code);
        $name = 'qr-'.$link->code.'.svg';

        return response($shares->qr($link), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => ($request->query('scarica') !== null ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex',
        ]);
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
        return app(Consent::class)->allows(ConsentCategory::Statistics) && $this->genuineVisit($request);
    }

    private function withinOpenCap(Request $request, string $linkId): bool
    {
        $key = 'share-open:'.IpBucket::key($request->ip()).':'.$linkId.':'.now()->toDateString();
        if (RateLimiter::tooManyAttempts($key, self::DAILY_OPEN_CAP)) {
            return false;
        }
        RateLimiter::hit($key, 86400);

        return true;
    }

    /**
     * Una persona che apre il link, non un'anteprima, un bot o un prefetch.
     * Un browser manda sempre lo User-Agent: senza, è uno script.
     */
    private function genuineVisit(Request $request): bool
    {
        return filled($request->userAgent())
            && ! preg_match('/bot|crawl|spider|preview|facebookexternalhit|facebot|whatsapp|telegram|slack|discord|linkedin|pinterest|skype|twitter|headless/i', $request->userAgent())
            && ! preg_match('/prefetch|prerender/i', $request->header('Purpose', '').' '.$request->header('Sec-Purpose', '').' '.$request->header('X-Purpose', ''));
    }
}
