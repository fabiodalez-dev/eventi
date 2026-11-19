<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Event;
use App\Support\Poster;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * La colonna della locandina negli elenchi dei pannelli.
 *
 * Ogni elenco di eventi (o di cose che appartengono a un evento: date,
 * sponsorizzazioni, prenotazioni) mostra la locandina, 48×64 come una
 * locandina vera e non un quadratino. La fonte è la stessa della scheda
 * pubblica (`Poster`): prima la conversione `thumb` della libreria media,
 * poi l'originale, poi la colonna `events.poster` dell'import, infine il
 * segnaposto — così una riga non resta mai con un buco.
 *
 * `PosterColumn::make('poster_media')` sull'evento stesso,
 * `PosterColumn::make('event.poster_media')` su un modello con la relazione
 * `event`. Chi la usa carica `media` (o `event.media`) nella query della
 * tabella: altrimenti è una query per riga.
 */
final class PosterColumn
{
    public const PLACEHOLDER = 'images/poster-placeholder.svg';

    public static function make(string $name = 'poster_media'): ImageColumn
    {
        $relation = str_contains($name, '.') ? Str::beforeLast($name, '.') : null;

        return ImageColumn::make($name)
            ->label(__('ui.panel.poster'))
            ->state(fn (Model $record): string => self::url($relation === null ? $record : data_get($record, $relation)))
            ->imageWidth(48)
            ->imageHeight(64)
            ->extraImgAttributes(['loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'class' => 'fi-poster-column object-cover'])
            ->grow(false);
    }

    /** L'indirizzo assoluto della miniatura, o del segnaposto. */
    public static function url(mixed $event): string
    {
        if (! $event instanceof Event) {
            return asset(self::PLACEHOLDER);
        }

        $media = $event->getFirstMedia('poster');

        if ($media !== null) {
            return $media->hasGeneratedConversion('thumb') ? $media->getFullUrl('thumb') : $media->getFullUrl();
        }

        $url = Poster::url($event);

        if ($url === null) {
            return asset(self::PLACEHOLDER);
        }

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }
}
