<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CatalogReview;
use Codebyray\ReviewRateable\Traits\ReviewRateable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCatalogReviews
{
    use ReviewRateable;

    /**
     * La tabella polimorfica non ha più la chiave esterna verso `venues`: le recensioni
     * (e i loro voti, in cascata) vanno tolte quando il locale o l'organizzatore sparisce
     * davvero, non quando finisce nel cestino.
     */
    public static function bootHasCatalogReviews(): void
    {
        static::deleted(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }
            CatalogReview::query()->where('reviewable_type', $model->getMorphClass())->where('reviewable_id', $model->getKey())
                ->each(static fn (CatalogReview $review) => $review->delete());
        });
    }

    /** @return MorphMany<CatalogReview, $this> */
    public function reviews(): MorphMany
    {
        return $this->morphMany(CatalogReview::class, 'reviewable');
    }
}
