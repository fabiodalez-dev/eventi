<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\CatalogReviews\Pages;

use App\Filament\Venue\Resources\CatalogReviews\CatalogReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListCatalogReviews extends ListRecords
{
    protected static string $resource = CatalogReviewResource::class;
}
