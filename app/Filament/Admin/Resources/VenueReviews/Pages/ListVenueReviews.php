<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueReviews\Pages;

use App\Filament\Admin\Resources\VenueReviews\VenueReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListVenueReviews extends ListRecords
{
    protected static string $resource = VenueReviewResource::class;
}
