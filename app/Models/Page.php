<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEditorialContent;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Una pagina di contenuto redazionale: privacy, cookie, termini, chi siamo,
 * contatti (§11.1 e §16).
 *
 * Lo slug si genera dal titolo la prima volta e **poi non si muove più**:
 * l'indirizzo di un'informativa privacy finisce nei registri dei trattamenti,
 * nelle email di conferma e nei documenti che qualcun altro ha stampato.
 * Cambiare il titolo non deve rompere quei collegamenti.
 */
class Page extends Model
{
    use HasEditorialContent;

    /** @use HasFactory<PageFactory> */
    use HasFactory;

    use HasSlug;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'is_published',
        'seo_title',
        'seo_description',
        'sort_order',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Il corpo, da Markdown a HTML.
     *
     * `html_input: strip` è la riga che sostituisce un intero sanificatore:
     * qualunque marcatura grezza presente nel testo — un `<script>`, un
     * `<iframe>`, un attributo `onclick` — viene **eliminata** invece di essere
     * filtrata, e non c'è whitelist da mantenere aggiornata (§16). `unsafe
     * links` disattivato scarta `javascript:` negli indirizzi, che è l'altro
     * modo per far eseguire qualcosa da un documento che sembra solo testo.
     */
    public function renderedBody(): HtmlString
    {
        return new HtmlString(Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    /**
     * Il titolo per la scheda del browser: quello dedicato se c'è, altrimenti
     * quello della pagina. Mai una stringa vuota, che diventerebbe un titolo
     * fatto del solo nome del prodotto.
     */
    public function metaTitle(): string
    {
        return filled($this->seo_title) ? (string) $this->seo_title : $this->title;
    }

    public function metaDescription(): ?string
    {
        if (filled($this->seo_description)) {
            return (string) $this->seo_description;
        }

        return filled($this->excerpt) ? (string) $this->excerpt : null;
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
