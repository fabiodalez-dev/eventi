<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * L'immagine che una pagina offre a chi la condivide: indirizzo e, quando si
 * conoscono, le sue misure.
 *
 * Le misure servono davvero. `og:image:width` e `og:image:height` permettono a
 * chi riceve il collegamento di riservare il rettangolo **prima** di aver
 * scaricato l'immagine: senza, l'anteprima nella conversazione appare prima
 * come una riga di testo e poi salta. È lo stesso problema di §11.11, un
 * gradino più in là.
 */
final readonly class SocialImage
{
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
