<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Models\Event;
use App\Models\Follow;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\Api\ApiDate;

/**
 * Un «segui» (§15.7).
 *
 * Porta con sé nome e slug del soggetto seguito: senza, un client dovrebbe
 * fare una chiamata per ciascuna riga solo per scrivere un elenco. `type` è
 * l'alias breve della morph map, lo stesso che sta nel database e lo stesso
 * che `POST /v1/me/follows` accetta — un solo vocabolario in ingresso e in
 * uscita.
 */
final class FollowResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Follow $follow, string $timezone): array
    {
        $subject = $follow->relationLoaded('followable') ? $follow->followable : null;

        return [
            'type' => (string) $follow->followable_type,
            'id' => (int) $follow->followable_id,
            'name' => self::name($subject),
            'slug' => self::slug($subject),
            'notify' => (bool) $follow->notify,
            'followed_at' => ApiDate::attribute($follow, 'created_at', $timezone),
        ];
    }

    private static function name(?object $subject): ?string
    {
        return match (true) {
            $subject instanceof Venue, $subject instanceof Organizer, $subject instanceof Tag, $subject instanceof Category => $subject->name,
            $subject instanceof Event => $subject->title,
            default => null,
        };
    }

    private static function slug(?object $subject): ?string
    {
        return match (true) {
            $subject instanceof Venue, $subject instanceof Organizer, $subject instanceof Tag, $subject instanceof Category, $subject instanceof Event => $subject->slug,
            default => null,
        };
    }
}
