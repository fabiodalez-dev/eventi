package it.fabiodalez.incitta.data.management

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/* L'editor minimo della descrizione: un testo scritto sul sito e salvato dall'app non perde la formattazione. */
class RichMarkupTest {
    @Test fun webHtmlBecomesMarksAndComesBackTheSame() {
        val html = "<h2>Il circolo</h2><p>Musica <strong>dal vivo</strong> e <em>incontri</em>, <u>sempre</u> <s>gratis</s>.<br>Seconda riga.</p>" +
            "<ul><li><p>Concerti</p></li><li><p>Letture</p></li></ul><ol><li><p>Arriva</p></li><li><p>Entra</p></li></ol>" +
            "<blockquote><p>Un posto per tutti</p></blockquote><p>Scrivi a <a href=\"mailto:info@circolo.test\">info</a> o visita <a href=\"https://circolo.test/a?b=1&amp;c=2\">il sito</a>.</p>"
        val marks = RichMarkup.fromHtml(html)
        assertEquals(
            "## Il circolo\n\nMusica **dal vivo** e *incontri*, __sempre__ ~~gratis~~.\nSeconda riga.\n\n- Concerti\n- Letture\n\n1. Arriva\n2. Entra\n\n> Un posto per tutti\n\n" +
                "Scrivi a [info](mailto:info@circolo.test) o visita [il sito](https://circolo.test/a?b=1&c=2).",
            marks,
        )
        assertEquals(
            "<h2>Il circolo</h2><p>Musica <strong>dal vivo</strong> e <em>incontri</em>, <u>sempre</u> <s>gratis</s>.<br>Seconda riga.</p>" +
                "<ul><li><p>Concerti</p></li><li><p>Letture</p></li></ul><ol><li><p>Arriva</p></li><li><p>Entra</p></li></ol>" +
                "<blockquote><p>Un posto per tutti</p></blockquote><p>Scrivi a <a href=\"mailto:info@circolo.test\">info</a> o visita <a href=\"https://circolo.test/a?b=1&amp;c=2\">il sito</a>.</p>",
            RichMarkup.toHtml(marks),
        )
        /* Andata e ritorno una seconda volta: niente si accumula. */
        assertEquals(marks, RichMarkup.fromHtml(RichMarkup.toHtml(marks)))
    }

    @Test fun plainTextOfOldVersionsStaysReadable() {
        assertEquals("Tessera annuale in sede.\nCosta 5 €", RichMarkup.fromHtml("Tessera annuale in sede.\nCosta 5 &euro;"))
        assertEquals("<p>Tessera annuale in sede.<br>Costa 5 €</p>", RichMarkup.toHtml("Tessera annuale in sede.\nCosta 5 €"))
        assertEquals("", RichMarkup.fromHtml(null))
        assertEquals("", RichMarkup.toHtml("  \n "))
    }

    @Test fun specialCharactersAreEscapedNotFormatted() {
        val marks = RichMarkup.fromHtml("<p>2 * 3 = 6 e [nota] con un_trattino &lt;b&gt;</p>")
        assertEquals("2 \\* 3 = 6 e \\[nota\\] con un\\_trattino <b>", marks)
        assertEquals("<p>2 * 3 = 6 e [nota] con un_trattino &lt;b&gt;</p>", RichMarkup.toHtml(marks))
    }

    @Test fun unsafeLinksAndUnknownTagsAreDropped() {
        assertEquals("clicca qui", RichMarkup.fromHtml("<p><a href=\"javascript:alert(1)\">clicca qui</a><script></script></p>"))
        val html = RichMarkup.toHtml("[clicca](javascript:alert(1)) <img src=x>")
        assertFalse(html.contains("<a "))
        assertFalse(html.contains("<img"))
    }

    @Test fun unclosedMarksCloseAtTheEndOfTheLineAndNestingStaysValid() {
        assertEquals("<p><strong>aperto</strong></p>", RichMarkup.toHtml("**aperto"))
        assertEquals("<p><strong><em>tutti e due</em></strong></p>", RichMarkup.toHtml("***tutti e due***"))
        assertEquals("<p><strong>a<em>b</em></strong><em>c</em></p>", RichMarkup.toHtml("**a*b**c*"))
    }

    @Test fun plainSummaryHasNoMarks() {
        assertEquals("Il circolo Musica dal vivo info", RichMarkup.plain("<h2>Il circolo</h2><p>Musica <strong>dal vivo</strong></p><p><a href=\"https://x.test\">info</a></p>"))
    }
}
