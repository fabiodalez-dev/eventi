<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Support\ContentVersion;
use App\Support\Redirect\RegistroRedirect;

/**
 * Una categoria rinominata cambia indirizzo alla propria lista.
 *
 * `city_id` è nullo perché le categorie non appartengono a una città: la
 * stessa riga vale per `/eventi/categoria/x` e per la sua forma prefissata.
 */
final class CategoryObserver
{
    public function saved(Category $category): void
    {
        ContentVersion::bumpTaxonomies();
    }

    public function deleted(Category $category): void
    {
        ContentVersion::bumpTaxonomies();
    }

    public function updated(Category $category): void
    {
        if (! $category->wasChanged('slug')) {
            return;
        }

        app(RegistroRedirect::class)->registra(
            null,
            '/eventi/categoria/'.$category->getOriginal('slug'),
            '/eventi/categoria/'.$category->slug,
        );
    }
}
