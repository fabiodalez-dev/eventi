<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tag;
use App\Support\Redirect\RegistroRedirect;

/**
 * Come per le categorie: un tag rinominato lascia dietro di sé l'indirizzo
 * della propria lista, che di solito è quello condiviso più spesso.
 */
final class TagObserver
{
    public function updated(Tag $tag): void
    {
        if (! $tag->wasChanged('slug')) {
            return;
        }

        app(RegistroRedirect::class)->registra(
            null,
            '/eventi/tag/'.$tag->getOriginal('slug'),
            '/eventi/tag/'.$tag->slug,
        );
    }
}
