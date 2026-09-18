<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CatalogReview;
use Codebyray\ReviewRateable\Traits\ReviewRateable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCatalogReviews
{
    use ReviewRateable;

    /** @return MorphMany<CatalogReview, $this> */
    public function reviews(): MorphMany
    {
        return $this->morphMany(CatalogReview::class, 'reviewable');
    }
}
