<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\City;
use App\Support\ContentVersion;

trait HasEditorialContent
{
    public static function bootHasEditorialContent(): void
    {
        static::saving(function ($model): void {
            $user = auth()->user();
            if ($user !== null && ! $user->hasAnyRole(['admin', 'super_admin'])) {
                $seo = $model->seo ?? [];
                $seo['indexing'] = ($model->getOriginal('seo') ?? [])['indexing'] ?? 'automatic';
                $model->seo = $seo;
                $model->is_demo = $model->getOriginal('is_demo') ?? false;
            }
        });
        static::saved(function ($model): void {
            if ($model->wasChanged(['content_details', 'seo', 'is_demo'])) {
                foreach (City::query()->pluck('id') as $id) {
                    ContentVersion::bump($id);
                }
            }
        });
    }

    public function initializeHasEditorialContent(): void
    {
        $this->mergeFillable(['content_details', 'seo', 'is_demo']);
        $this->mergeCasts(['content_details' => 'array', 'seo' => 'array', 'is_demo' => 'boolean']);
    }
}
