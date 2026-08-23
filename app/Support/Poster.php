<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * L'indirizzo della locandina di un evento.
 *
 * La locandina può arrivare da due strade: la libreria media (`spatie/
 * laravel-medialibrary`, che è quella definitiva) oppure la colonna
 * `events.poster`, che l'import e l'inserimento redazionale rapido riempiono
 * con un percorso o con un indirizzo esterno. Card, scheda evento, dati
 * strutturati e anteprime social devono guardare **lo stesso** posto,
 * altrimenti la pagina mostra un'immagine e Facebook ne mostra un'altra.
 */
final class Poster
{
    public static function url(Event $event): ?string
    {
        $fromLibrary = $event->getFirstMediaUrl('poster');

        if ($fromLibrary !== '') {
            return $fromLibrary;
        }

        if (blank($event->poster)) {
            return null;
        }

        $poster = (string) $event->poster;

        return Str::startsWith($poster, ['http://', 'https://', '/'])
            ? $poster
            : Storage::disk('public')->url($poster);
    }

    /**
     * Lo stesso indirizzo, sempre assoluto: i dati strutturati e le anteprime
     * social vengono letti da macchine che non hanno un contesto di dominio.
     */
    public static function absoluteUrl(Event $event): ?string
    {
        $poster = self::url($event);

        if ($poster === null) {
            return null;
        }

        return Str::startsWith($poster, ['http://', 'https://']) ? $poster : url($poster);
    }
}
