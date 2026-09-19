<?php

declare(strict_types=1);

namespace App\Models;

use Codebyray\ReviewRateable\Models\Rating;

class CatalogRating extends Rating
{
    protected $table = 'ratings';
}
