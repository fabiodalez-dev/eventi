<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Due stati, non tre.
 *
 * `VenueReviewStatus` ne ha tre perché le recensioni si approvano prima di
 * pubblicarle. I commenti si pubblicano subito, quindi «in attesa» non esiste:
 * o si leggono, o qualcuno li ha tolti dalla vista.
 *
 * La cancellazione vera non è uno stato: è l'assenza della riga, e resta un
 * potere del solo amministratore.
 */
enum EventCommentStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Published => __('comments.status.published'),
            self::Hidden => __('comments.status.hidden'),
        };
    }
}
