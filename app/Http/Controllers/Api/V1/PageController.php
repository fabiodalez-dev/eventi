<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Le pagine legali, consultabili dentro l'applicazione.
 *
 * Non è una comodità: Apple e Google pretendono che un'applicazione mostri la
 * propria informativa sulla privacy, e quella che rimanda al browser per
 * leggerla viene penalizzata in revisione. È il genere di requisito che si
 * scopre al primo invio allo store, quando l'applicazione è già scritta.
 *
 * Il corpo esce in due forme — il testo di partenza e il testo reso — così il
 * client sceglie: chi sa comporre il markup usa il primo, chi vuole solo
 * mostrarlo prende il secondo senza dover interpretare niente.
 */
final class PageController extends Controller
{
    public function index(): JsonResponse
    {
        $pages = Page::query()
            ->published()
            ->ordered()
            ->get()
            ->map(static fn (Page $page): array => self::summary($page))
            ->all();

        return ApiResponse::collection($pages);
    }

    public function show(string $slug): JsonResponse
    {
        $page = Page::query()->published()->where('slug', $slug)->first();

        if (! $page instanceof Page) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return ApiResponse::item([
            ...self::summary($page),
            'body' => (string) $page->body,
            'body_html' => $page->renderedBody()->toHtml(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Page $page): array
    {
        return [
            'slug' => (string) $page->slug,
            'title' => (string) $page->title,
            /* Stesso helper delle altre risorse: legge l'attributo invece di
               toccare la proprieta, e formatta come tutto il resto dell'API. */
            'updated_at' => ApiDate::attribute($page, 'updated_at', config()->string('app.timezone')),
        ];
    }
}
