<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Le varianti di un'immagine nella forma che serve a un `<picture>`: gli
 * indirizzi AVIF, quelli WebP, l'indirizzo di ripiego, le misure e il
 * segnaposto sfocato.
 *
 * Esiste perché la scelta di quale file servire **non è della vista**. La
 * vista sa quanto spazio ha (`sizes`), il browser sa che formati apre e che
 * schermo ha: mettendogli davanti l'elenco completo sceglie lui. Se fosse una
 * vista a decidere "qui ci va la card da 800", servirebbe la stessa immagine a
 * un telefono da 360 px e a uno schermo da 27 pollici.
 *
 * `width` e `height` sono i soli due valori senza i quali la pagina salta
 * mentre carica (§11.11): il browser deve poter riservare il rettangolo prima
 * di aver visto un solo byte dell'immagine.
 */
final readonly class ImageSet
{
    /**
     * @param  array<string, string>  $sources  tipo MIME → attributo `srcset`
     */
    public function __construct(
        public string $src,
        public array $sources = [],
        public ?string $blurhash = null,
        public ?string $placeholder = null,
        public ?int $width = null,
        public ?int $height = null,
        /*
         * Quanto spazio occuperà l'immagine nella pagina, nella sintassi
         * dell'attributo `sizes`. Lo sa solo chi la dispone, e senza questo
         * valore il browser assume la larghezza intera della finestra e
         * scarica la variante più grande anche dentro una card da 280 px.
         */
        public ?string $sizes = null,
        /*
         * A quali schermi serve **annunciare in anticipo** questa immagine,
         * nella sintassi dell'attributo `media`. Stessa ragione di `sizes`: lo
         * sa solo chi la dispone. Un preload è una promessa che l'immagine
         * serve subito, e su un impaginato a due colonne quella promessa è
         * vera solo finché le colonne stanno affiancate — sotto quella soglia
         * l'immagine scende sotto la piega e il preload le fa scavalcare la
         * fila davanti a ciò che si vede davvero.
         */
        public ?string $preloadMedia = null,
    ) {}

    /**
     * L'insieme di un media della libreria. Le conversioni che non sono
     * ancora state generate — la coda può essere indietro di qualche secondo —
     * semplicemente non compaiono, e il browser ricade sull'originale.
     */
    public static function fromMedia(Media $media): self
    {
        $sources = [];

        foreach (['image/avif' => true, 'image/webp' => false] as $mime => $avif) {
            $srcset = [];

            foreach (Variants::widths() as $variant => $width) {
                $name = $avif ? Variants::avif($variant) : $variant;

                if ($media->hasGeneratedConversion($name)) {
                    $srcset[] = $media->getFullUrl($name).' '.$width.'w';
                }
            }

            if ($srcset !== []) {
                $sources[$mime] = implode(', ', $srcset);
            }
        }

        return new self(
            src: $media->hasGeneratedConversion('full') ? $media->getFullUrl('full') : $media->getFullUrl(),
            sources: $sources,
            blurhash: self::property($media, 'blurhash'),
            placeholder: self::property($media, 'placeholder'),
            width: self::dimension($media, 'width'),
            height: self::dimension($media, 'height'),
        );
    }

    /**
     * L'insieme della prima immagine di una raccolta di un modello, o `null`
     * se quella raccolta è vuota.
     */
    public static function forCollection(HasMedia $model, string $collection): ?self
    {
        $media = $model->getMedia($collection)->first();

        return $media instanceof Media ? self::fromMedia($media) : null;
    }

    /**
     * Un'immagine che non passa dalla libreria media — l'indirizzo scritto a
     * mano nella colonna `events.poster`, o quello che arriva da un import.
     * Nessuna variante, nessun segnaposto: c'è solo il file che c'è.
     */
    public static function fromUrl(string $url, ?int $width = null, ?int $height = null): self
    {
        return new self(src: $url, width: $width, height: $height);
    }

    /**
     * La stessa immagine con l'indicazione dello spazio che occuperà: la
     * dichiara la pagina che la dispone, non chi l'ha caricata.
     */
    public function withSizes(string $sizes): self
    {
        return new self($this->src, $this->sources, $this->blurhash, $this->placeholder, $this->width, $this->height, $sizes, $this->preloadMedia);
    }

    /**
     * La stessa immagine, da annunciare in anticipo **solo** agli schermi che
     * la mostrano sopra la piega.
     */
    public function withPreloadMedia(string $media): self
    {
        return new self($this->src, $this->sources, $this->blurhash, $this->placeholder, $this->width, $this->height, $this->sizes, $media);
    }

    public function hasSources(): bool
    {
        return $this->sources !== [];
    }

    private static function property(Media $media, string $key): ?string
    {
        $value = $media->getCustomProperty($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function dimension(Media $media, string $key): ?int
    {
        $value = $media->getCustomProperty($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
