package it.fabiodalez.incitta.ui.management

import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.RichTextEdits
import org.junit.Assert.*
import org.junit.Test

/*
 * L'editor unico del testo formattato (eventi, profilo, istruzioni per chi
 * prenota): l'HTML del sito diventa testo con i segni e torna lo stesso HTML,
 * con tutti i tag che il filtro del sito tiene (`Description::sanitize`).
 */
class RichTextTest {
    private val site = "<h2>Il programma</h2><p>Una <strong>serata</strong> di <em>swing</em> con <a href=\"https://example.org/band\">la band</a>.<br>Porte alle 20.</p>" +
        "<ul><li><p>Ingresso libero</p></li><li><p>Bar aperto</p></li></ul><ol><li><p>Prima parte</p></li><li><p>Seconda parte</p></li></ol><p>L&rsquo;ultima riga &amp; basta</p>"

    @Test fun theSiteHtmlBecomesMarkup() {
        assertEquals(
            "## Il programma\n\nUna **serata** di *swing* con [la band](https://example.org/band).\nPorte alle 20.\n\n- Ingresso libero\n- Bar aperto\n\n1. Prima parte\n2. Seconda parte\n\nL’ultima riga & basta",
            RichText.toMarkup(site),
        )
    }

    @Test fun markupGoesBackToTheSameHtml() {
        val html = RichText.toHtml(RichText.toMarkup(site))
        assertEquals(
            "<h2>Il programma</h2><p>Una <strong>serata</strong> di <em>swing</em> con <a href=\"https://example.org/band\">la band</a>.<br>Porte alle 20.</p>" +
                "<ul><li><p>Ingresso libero</p></li><li><p>Bar aperto</p></li></ul><ol><li><p>Prima parte</p></li><li><p>Seconda parte</p></li></ol><p>L’ultima riga &amp; basta</p>",
            html,
        )
        assertEquals(RichText.toMarkup(site), RichText.toMarkup(html))
    }

    @Test fun everyTagTheSiteKeepsSurvivesTheRoundTrip() {
        val html = "<h2>Il circolo</h2><p>Musica <strong>dal vivo</strong> e <em>incontri</em>, <u>sempre</u> <s>gratis</s>.<br>Seconda riga.</p>" +
            "<ul><li><p>Concerti</p></li><li><p>Letture</p></li></ul><ol><li><p>Arriva</p></li><li><p>Entra</p></li></ol>" +
            "<blockquote><p>Un posto per tutti</p></blockquote><h3>Dove</h3><p>Scrivi a <a href=\"mailto:info@circolo.test\">info</a> o visita <a href=\"https://circolo.test/a?b=1&amp;c=2\">il sito</a>.</p>"
        val marks = RichText.toMarkup(html)
        assertEquals(
            "## Il circolo\n\nMusica **dal vivo** e *incontri*, __sempre__ ~~gratis~~.\nSeconda riga.\n\n- Concerti\n- Letture\n\n1. Arriva\n2. Entra\n\n> Un posto per tutti\n\n### Dove\n\n" +
                "Scrivi a [info](mailto:info@circolo.test) o visita [il sito](https://circolo.test/a?b=1&c=2).",
            marks,
        )
        assertEquals(html, RichText.toHtml(marks))
        /* Andata e ritorno una seconda volta: niente si accumula. */
        assertEquals(marks, RichText.toMarkup(RichText.toHtml(marks)))
    }

    @Test fun plainTextAndEmptyValuesStayPlain() {
        assertEquals("", RichText.toMarkup(null))
        assertEquals("", RichText.toHtml("  \n "))
        assertEquals("", RichText.toHtml(null))
        assertEquals("Solo testo", RichText.toMarkup("Solo testo"))
        assertEquals("Tessera annuale in sede.\nCosta 5 €", RichText.toMarkup("Tessera annuale in sede.\nCosta 5 &euro;"))
        assertEquals("<p>Tessera annuale in sede.<br>Costa 5 €</p>", RichText.toHtml("Tessera annuale in sede.\nCosta 5 €"))
        assertEquals("<p>a &lt;b&gt; c</p>", RichText.toHtml("a <b> c"))
    }

    @Test fun specialCharactersAreEscapedNotFormatted() {
        val marks = RichText.toMarkup("<p>2 * 3 = 6 e [nota] con un_trattino &lt;b&gt;</p>")
        assertEquals("2 \\* 3 = 6 e \\[nota\\] con un\\_trattino <b>", marks)
        assertEquals("<p>2 * 3 = 6 e [nota] con un_trattino &lt;b&gt;</p>", RichText.toHtml(marks))
    }

    @Test fun aParagraphThatLooksLikeALineMarkStaysAParagraph() {
        val html = "<p>- non è un elenco</p><p>1. nemmeno questo</p><p>&gt; 18 anni</p><p># hashtag</p><p>• punto</p>"
        val marks = RichText.toMarkup(html)
        assertEquals("\\- non è un elenco\n\n1\\. nemmeno questo\n\n\\> 18 anni\n\n\\# hashtag\n\n\\• punto", marks)
        assertEquals("<p>- non è un elenco</p><p>1. nemmeno questo</p><p>&gt; 18 anni</p><p># hashtag</p><p>• punto</p>", RichText.toHtml(marks))
        assertEquals("- non è un elenco\n\n1. nemmeno questo", RichText.plain(marks.lines().take(3).joinToString("\n")))
    }

    @Test fun otherHeadingsAndEntitiesBecomeWhatTheSiteKeeps() {
        assertEquals("## Grande\n\n### Piccolo\n\n“citato” – fine", RichText.toMarkup("<h1>Grande</h1><h4>Piccolo</h4><p>&ldquo;citato&rdquo; &ndash; fine</p>"))
        assertEquals("<h2>Titolo</h2><p>testo</p>", RichText.toHtml("# Titolo\ntesto"))
        assertEquals("<ul><li><p>uno</p></li><li><p>due</p></li></ul>", RichText.toHtml("• uno\n- due"))
        assertEquals("<ol><li><p>uno</p></li></ol>", RichText.toHtml("1) uno"))
    }

    @Test fun unsafeLinksAndUnknownTagsAreDropped() {
        assertEquals("clicca qui", RichText.toMarkup("<p><a href=\"javascript:alert(1)\">clicca qui</a><script></script></p>"))
        val html = RichText.toHtml("[clicca](javascript:alert(1)) <img src=x>")
        assertFalse(html.contains("<a "))
        assertFalse(html.contains("<img"))
    }

    @Test fun unclosedMarksCloseAtTheEndOfTheLineAndNestingStaysValid() {
        assertEquals("<p><strong>aperto</strong></p>", RichText.toHtml("**aperto"))
        assertEquals("<p><strong><em>tutti e due</em></strong></p>", RichText.toHtml("***tutti e due***"))
        assertEquals("<p><strong>a<em>b</em></strong><em>c</em></p>", RichText.toHtml("**a*b**c*"))
    }

    @Test fun plainAndSummaryHaveNoMarks() {
        assertEquals("Una serata\n• voce", RichText.plain("Una **serata**\n- voce"))
        assertEquals("Titolo\n\nvedi il sito, 2 * 3", RichText.plain("## Titolo\n\nvedi [il sito](https://x.test), 2 \\* 3"))
        assertEquals("Il circolo Musica dal vivo info", RichText.summary("<h2>Il circolo</h2><p>Musica <strong>dal vivo</strong></p><p><a href=\"https://x.test\">info</a></p>"))
        assertEquals("Concerti Letture", RichText.summary("<ol><li>Concerti</li><li>Letture</li></ol>"))
    }

    @Test fun theToolbarWrapsAndPrefixes() {
        val bold = RichTextEdits.wrap(TextFieldValue("una serata", TextRange(4, 10)), "**")
        assertEquals("una **serata**", bold.text)
        val empty = RichTextEdits.wrap(TextFieldValue("x", TextRange(1)), "*")
        assertEquals("x**", empty.text)
        assertEquals(TextRange(2), empty.selection)
        assertEquals("- uno\n- due", RichTextEdits.prefixLines(TextFieldValue("uno\ndue", TextRange(0, 7)), "- ").text)
        assertEquals("1. uno\n2. due", RichTextEdits.numberLines(TextFieldValue("uno\ndue", TextRange(0, 7))).text)
        // Un segno di riga ne sostituisce un altro, e lo stesso segno non si ripete.
        assertEquals("## uno\n## due", RichTextEdits.prefixLines(TextFieldValue("- uno\n3. due", TextRange(0, 12)), "## ").text)
        assertEquals("- uno", RichTextEdits.prefixLines(TextFieldValue("- uno", TextRange(2)), "- ").text)
        assertEquals("1. uno\n2. due", RichTextEdits.numberLines(TextFieldValue("- uno\n- due", TextRange(0, 11))).text)
        // Solo la riga del cursore.
        assertEquals("uno\n> due", RichTextEdits.prefixLines(TextFieldValue("uno\ndue", TextRange(5)), "> ").text)
    }

    @Test fun theToolbarAddsLinks() {
        assertEquals("vedi [il sito](https://example.org)", RichTextEdits.link(TextFieldValue("vedi il sito", TextRange(5, 12)), "https://example.org").text)
        assertEquals("[https://example.org](https://example.org)", RichTextEdits.link(TextFieldValue(""), "https://example.org").text)
        val custom = RichTextEdits.link(TextFieldValue("x ", TextRange(2)), "https://example.org/a b", "nota [1]")
        assertEquals("x [nota \\[1\\]](https://example.org/a%20b)", custom.text)
        assertEquals("<p>x <a href=\"https://example.org/a%20b\">nota [1]</a></p>", RichText.toHtml(custom.text))
        assertTrue(RichTextEdits.validUrl("https://example.org/a"))
        assertTrue(RichTextEdits.validUrl("mailto:info@circolo.test"))
        assertFalse(RichTextEdits.validUrl("example"))
        assertFalse(RichTextEdits.validUrl("https://"))
        assertFalse(RichTextEdits.validUrl("javascript:alert(1)"))
    }
}
