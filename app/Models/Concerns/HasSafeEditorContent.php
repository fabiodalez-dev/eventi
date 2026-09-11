<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\EditorContent;

trait HasSafeEditorContent
{
    public static function bootHasSafeEditorContent(): void
    {
        static::saving(function (self $model): void {
            foreach (EditorContent::FIELDS as $field) {
                if ($model->isDirty($field)) {
                    $model->setAttribute($field, EditorContent::clean($field, $model->getAttribute($field)));
                }
            }
        });
    }
}
