{{--
    Una riga dell'indice.

    La `lastmod` si emette **solo se qualcuno l'ha impostata davvero**
    (`App\Support\Seo\SitemapSection`). Il tag del pacchetto nasce con
    `Carbon::now()` e una proprietà non annullabile: senza questa guardia ogni
    sezione dichiarerebbe di essere cambiata nell'istante della richiesta, e
    una `lastmod` che dice sempre «adesso» è un'informazione che il motore
    impara a ignorare.
--}}
<sitemap>
    <loc>{{ url($tag->url) }}</loc>
@if (($tag->lastModificationKnown ?? false) && ! empty($tag->lastModificationDate))
    <lastmod>{{ $tag->lastModificationDate->format(DateTime::ATOM) }}</lastmod>
@endif
</sitemap>
