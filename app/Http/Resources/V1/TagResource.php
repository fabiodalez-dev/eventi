<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Tag;
use App\Services\Seo\EditorialContent;

final class TagResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Tag $tag): array
    {
        return [
            'id' => (int) $tag->getKey(),
            'slug' => (string) $tag->slug,
            'name' => (string) $tag->name,
            'usage_count' => (int) $tag->usage_count,
            'content_details' => app(EditorialContent::class)->details($tag),
        ];
    }
}
