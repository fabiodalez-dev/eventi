<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Ciò che una pagina pubblica dichiara di sé: titolo del browser, titolo
 * visibile, descrizione, indirizzo canonico, immagine di anteprima e
 * indicazione ai motori (§12.2).
 *
 * Titolo e `<h1>` sono due campi distinti perché non coincidono: il primo
 * ripete la città per essere leggibile in una scheda del browser, il secondo
 * sta in cima a una pagina che la città ce l'ha già scritta sopra.
 */
final readonly class PageMeta
{
    public function __construct(
        public string $title,
        public string $heading,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $image = null,
        public bool $indexable = true,
    ) {}

    public function withCanonical(?string $canonical): self
    {
        return new self($this->title, $this->heading, $this->description, $canonical, $this->image, $this->indexable);
    }

    public function withImage(?string $image): self
    {
        return new self($this->title, $this->heading, $this->description, $this->canonical, $image, $this->indexable);
    }

    public function withIndexable(bool $indexable): self
    {
        return new self($this->title, $this->heading, $this->description, $this->canonical, $this->image, $indexable);
    }

    /**
     * `noindex, follow`: la pagina non entra nell'indice ma i link che porta
     * restano percorribili. Vale per le combinazioni di filtri, che sono
     * infinite, e per le ricerche libere.
     */
    public function robots(): string
    {
        return $this->indexable ? 'index, follow' : 'noindex, follow';
    }
}
