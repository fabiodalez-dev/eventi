<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Seo\SitemapBuilder;
use App\Support\CurrentCity;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * `sitemap.xml` a indice e `robots.txt` (§12.2).
 *
 * Il `robots.txt` è una **rotta** e non un file statico: la riga `Sitemap:`
 * vuole un indirizzo assoluto, e un file scritto a mano lo congelerebbe al
 * dominio di quando è stato scritto — sviluppo, collaudo e produzione ne hanno
 * tre diversi, e sbagliarlo significa dichiarare a un motore una mappa che sta
 * altrove. Il file `public/robots.txt` è stato tolto proprio per questo: se
 * esistesse, il server lo servirebbe prima ancora di arrivare a PHP.
 */
final class SeoController extends Controller
{
    public function __construct(private readonly SitemapBuilder $sitemap) {}

    public function index(CurrentCity $currentCity): Response
    {
        $city = $currentCity->get();

        abort_if($city === null, 404);

        return response($this->sitemap->index($city)->render(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    public function section(CurrentCity $currentCity, string $section, int $page = 1): Response
    {
        $city = $currentCity->get();

        abort_if($city === null, 404);

        $sitemap = $this->sitemap->section($city, $section, $page);

        abort_if($sitemap === null, 404);

        return response($sitemap->render(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    /**
     * `robots.txt`. Tutto è aperto tranne ciò che non ha senso in un indice:
     * i due pannelli, l'impersonificazione, il riquadro da incorporare, l'API
     * e la ricerca libera — che genera infinite pagine tutte uguali a sé
     * stesse (D25, punto 4).
     */
    public function robots(): Response
    {
        /** @var list<string> $disallow */
        $disallow = config()->array('seo.robots.disallow');

        $lines = ['User-agent: *'];

        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ($disallow === []) {
            $lines[] = 'Disallow:';
        }

        if (Route::has('sitemap.index')) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.route('sitemap.index');
        }

        return response(implode(PHP_EOL, $lines).PHP_EOL, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
