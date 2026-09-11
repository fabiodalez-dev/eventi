<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Description;
use Filament\Forms\Components\RichEditor;

final class DescriptionEditor
{
    public static function make(string $name = 'description'): RichEditor
    {
        return RichEditor::make($name)
            ->fileAttachments(false)
            ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'], ['undo', 'redo']])
            ->formatStateUsing(fn (?string $state): string => (string) Description::render($state))
            ->dehydrateStateUsing(fn (?string $state): string => Description::sanitize($state ?? ''))
            ->maxLength(50000);
    }
}
