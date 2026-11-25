package it.fabiodalez.incitta.ui.management

import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.RichTextEdits
import org.junit.Assert.*
import org.junit.Test

/* L'editor minimo della descrizione: l'HTML del sito diventa testo con i segni e torna HTML senza perdere la formattazione. */
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

    @Test fun plainTextAndEmptyValuesStayPlain() {
        assertEquals("", RichText.toMarkup(null))
        assertEquals("", RichText.toHtml("  \n "))
        assertEquals("Solo testo", RichText.toMarkup("Solo testo"))
        assertEquals("<p>a &lt;b&gt; c</p>", RichText.toHtml("a <b> c"))
        assertEquals("Una serata\n• voce", RichText.plain("Una **serata**\n- voce"))
    }

    @Test fun theToolbarWrapsAndPrefixes() {
        val bold = RichTextEdits.wrap(TextFieldValue("una serata", TextRange(4, 10)), "**")
        assertEquals("una **serata**", bold.text)
        val empty = RichTextEdits.wrap(TextFieldValue("x", TextRange(1)), "*")
        assertEquals("x**", empty.text)
        assertEquals(TextRange(2), empty.selection)
        assertEquals("- uno\n- due", RichTextEdits.prefixLines(TextFieldValue("uno\ndue", TextRange(0, 7)), "- ").text)
        assertEquals("1. uno\n2. due", RichTextEdits.numberLines(TextFieldValue("uno\ndue", TextRange(0, 7))).text)
        assertEquals("vedi [il sito](https://example.org)", RichTextEdits.link(TextFieldValue("vedi il sito", TextRange(5, 12)), "https://example.org").text)
        assertTrue(RichTextEdits.validUrl("https://example.org/a"))
        assertFalse(RichTextEdits.validUrl("example"))
    }
}
