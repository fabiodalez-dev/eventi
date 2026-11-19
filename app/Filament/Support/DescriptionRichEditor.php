<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Description;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * L'editor delle descrizioni, che apre un testo semplice a paragrafi.
 *
 * Le descrizioni importate o create da script sono testo con gli a capo. Il
 * cast di Filament le passa all'editor TipTap come se fossero HTML, prima di
 * `formatStateUsing()`: gli a capo diventano spazi o spariscono, e al primo
 * salvataggio i paragrafi si fondono in uno. Questo cast arriva prima e
 * trasforma il testo semplice in paragrafi (`Description::fromPlainText()`);
 * l'HTML e lo stato dell'editor passano com'erano.
 */
final class DescriptionRichEditor extends RichEditor
{
    /** @return array<StateCast> */
    public function getDefaultStateCasts(): array
    {
        return [
            new class implements StateCast
            {
                public function get(mixed $state): mixed
                {
                    return $state;
                }

                public function set(mixed $state): mixed
                {
                    // L'HTML passa dalla stessa sanificazione di `Description::render()`.
                    return is_string($state) && trim($state) !== '' ? Description::fromPlainText($state) : $state;
                }
            },
            ...parent::getDefaultStateCasts(),
        ];
    }
}
