<?php

declare(strict_types=1);

use App\Services\Media\ImageSanitizer;

/*
 * Il ridimensionamento dell'originale.
 *
 * §12.1 dichiara la pipeline come «upload → validazione → strip EXIF →
 * **resize** → varianti», e lo stesso commento sta in testa a `config/media`.
 * Quel passo non era mai stato scritto: il ridimensionamento avveniva solo
 * dentro le conversioni, e l'originale restava com'era arrivato.
 *
 * Non si vedeva perche' le locandine venivano dal seeder e pesano 300 KB. Un
 * locale che carica dal telefono manda 4000x3000 e cinque megabyte, e su una
 * quota da dieci giga — gia' esaurita una volta — cento locandine cosi' sono
 * mezzo giga di file che nessuno serve mai: la variante piu' grande e' 1600px.
 */

function scriviImmagine(int $larghezza, int $altezza): string
{
    $percorso = sys_get_temp_dir().'/originale-'.bin2hex(random_bytes(6)).'.jpg';

    $im = new Imagick;
    $im->newImage($larghezza, $altezza, new ImagickPixel('white'));
    $im->setImageFormat('jpeg');
    $im->writeImage($percorso);
    $im->clear();

    return $percorso;
}

function dimensioniDi(string $percorso): array
{
    $im = new Imagick($percorso);
    $misure = [$im->getImageWidth(), $im->getImageHeight()];
    $im->clear();

    return $misure;
}

it('riporta nel limite una foto da telefono', function (): void {
    $percorso = scriviImmagine(4000, 3000);

    app(ImageSanitizer::class)->sanitize($percorso);

    [$larghezza, $altezza] = dimensioniDi($percorso);

    expect($larghezza)->toBe(2400)
        /* Le proporzioni restano: 4000x3000 e' 4:3, e 2400x1800 lo e' ancora. */
        ->and($altezza)->toBe(1800);

    unlink($percorso);
});

it('misura il lato lungo, non la larghezza', function (): void {
    /* Una locandina verticale — il caso normale — ha il lato lungo in
       altezza: limitare sempre la larghezza la lascerebbe passare intera. */
    $percorso = scriviImmagine(3000, 4000);

    app(ImageSanitizer::class)->sanitize($percorso);

    [$larghezza, $altezza] = dimensioniDi($percorso);

    expect($altezza)->toBe(2400)
        ->and($larghezza)->toBe(1800);

    unlink($percorso);
});

it('non ingrandisce mai un originale piccolo', function (): void {
    /* Portare una locandina da 900px a 2400 aggiungerebbe peso e nessun
       dettaglio: i pixel che non c'erano non compaiono. */
    $percorso = scriviImmagine(900, 900);

    app(ImageSanitizer::class)->sanitize($percorso);

    expect(dimensioniDi($percorso))->toBe([900, 900]);

    unlink($percorso);
});

it('lascia intatta un immagine esattamente al limite', function (): void {
    $percorso = scriviImmagine(2400, 1600);

    app(ImageSanitizer::class)->sanitize($percorso);

    expect(dimensioniDi($percorso))->toBe([2400, 1600]);

    unlink($percorso);
});

it('fa risparmiare spazio davvero, non solo pixel', function (): void {
    /* Il motivo per cui esiste: la quota dell'account, che si e' gia' esaurita
       una volta e ha messo giu' il sito. */
    $percorso = scriviImmagine(5000, 4000);
    $prima = filesize($percorso);

    app(ImageSanitizer::class)->sanitize($percorso);

    clearstatcache();

    expect(filesize($percorso))->toBeLessThan($prima);

    unlink($percorso);
});

it('resta sopra la variante piu grande, per non dover richiedere le immagini ai locali', function (): void {
    /*
     * 2400 e non 1600: se un giorno servisse una variante piu' grande,
     * l'originale deve poterla ancora produrre. Richiedere di nuovo le
     * locandine a chi le ha caricate mesi prima non e' una cosa che si puo'
     * fare.
     */
    $piuGrande = max(config()->array('media.variants'));

    expect(config()->integer('media.max_original_width'))->toBeGreaterThan($piuGrande);
});
