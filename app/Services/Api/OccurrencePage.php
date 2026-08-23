<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\EventOccurrence;
use App\Support\Api\ApiContext;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Una pagina di occorrenze e il contesto con cui va scritta: due cose che
 * viaggiano sempre insieme e che separate si dimenticano — è così che
 * `is_saved` sparirebbe da metà delle risposte.
 */
final readonly class OccurrencePage
{
    /**
     * @param  CursorPaginator<int, EventOccurrence>  $paginator
     */
    public function __construct(
        public CursorPaginator $paginator,
        public ApiContext $context,
    ) {}
}
