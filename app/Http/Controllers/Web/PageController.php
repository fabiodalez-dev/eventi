<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Contracts\View\View;

/**
 * Le pagine redazionali di §11.1 (`/pagine/{slug}`).
 *
 * Una pagina non pubblicata è un **404**, non un 403 e non una pagina vuota:
 * una bozza di informativa privacy non deve essere leggibile da chi ne indovina
 * l'indirizzo, e non deve nemmeno rivelare di esistere.
 */
final class PageController extends Controller
{
    public function show(string $slug): View
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        return view('pages.show', [
            'page' => $page,
            'meta' => new PageMeta(
                title: $page->metaTitle(),
                heading: $page->title,
                description: $page->metaDescription(),
                canonical: route('pages.show', ['slug' => $page->slug]),
            ),
        ]);
    }
}
