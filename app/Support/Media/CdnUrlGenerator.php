<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * L'ultimo anello della pipeline di §12.1: **CDN**.
 *
 * Ogni indirizzo di media e di conversione passa da qui — card, scheda,
 * anteprime social, API. Finché `media.cdn_url` è vuota gli indirizzi restano
 * quelli del disco e questa classe non fa nulla; il giorno in cui la riga
 * viene riempita, tutte le immagini escono dal dominio della rete di
 * distribuzione senza che una sola vista debba cambiare.
 *
 * Vale solo per i dischi locali: un disco remoto (S3 e simili) ha già un
 * indirizzo pubblico proprio e riscriverlo lo romperebbe.
 */
final class CdnUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        return $this->onCdn(parent::getUrl());
    }

    public function getBaseMediaDirectoryUrl(): string
    {
        return $this->onCdn(parent::getBaseMediaDirectoryUrl());
    }

    public function getResponsiveImagesDirectoryUrl(): string
    {
        return $this->onCdn(parent::getResponsiveImagesDirectoryUrl());
    }

    /**
     * Sostituisce l'origine dell'indirizzo, conservando percorso e query: la
     * versione (`?v=`) di `media-library.version_urls` è parte
     * dell'indirizzo e va con lui.
     */
    private function onCdn(string $url): string
    {
        $base = config('media.cdn_url');

        if (! is_string($base) || $base === '') {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return rtrim($base, '/')
            .$path
            .(is_string($query) && $query !== '' ? '?'.$query : '');
    }
}
