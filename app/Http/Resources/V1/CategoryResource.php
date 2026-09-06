<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use App\Services\Seo\EditorialContent;

/**
 * Una categoria con **i tre campi che governano il tempo** (§7.4, §13.1):
 * `supports_ongoing`, `is_nightlife` e `default_duration_minutes`.
 *
 * Non sono decorazioni: `supports_ongoing` decide se una mostra possa
 * comparire fra gli eventi in corso, `is_nightlife` sposta la giornata evento
 * di un after alle 2:00 sulla serata precedente e la durata predefinita è ciò
 * che riempie `effective_ends_at` quando l'ora di fine manca. L'app li riceve
 * per poterli **spiegare**, non per ricalcolarli: le finestre restano del
 * server (§8).
 */
final class CategoryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Category $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'slug' => (string) $category->slug,
            'name' => (string) $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'sort_order' => (int) $category->sort_order,
            'content_details' => app(EditorialContent::class)->details($category),
            'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
            'supports_ongoing' => (bool) $category->supports_ongoing,
            'is_nightlife' => (bool) $category->is_nightlife,
            'default_duration_minutes' => $category->default_duration_minutes === null
                ? null
                : (int) $category->default_duration_minutes,
        ];
    }

    /**
     * La forma ridotta che accompagna ogni occorrenza: quel tanto che basta a
     * disegnare un'etichetta colorata.
     *
     * @return array<string, mixed>
     */
    public static function summary(Category $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'slug' => (string) $category->slug,
            'name' => (string) $category->name,
            'color' => $category->color,
        ];
    }
}
