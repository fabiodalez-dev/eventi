package it.fabiodalez.incitta.ui.management.common

/**
 * La descrizione dell'evento fra l'HTML del sito e il testo che si scrive sul
 * telefono (decisione 4 del piano: editor minimo scritto a mano, senza
 * librerie nuove).
 *
 * Il testo usa pochi segni, gli stessi che inserisce la barra dell'editor:
 *
 * - `**grassetto**`, `*corsivo*`, `[parola](https://…)` per un link;
 * - `## Titolo` e `### Sottotitolo` a inizio riga;
 * - `- voce` per un elenco puntato, `1. voce` per un elenco numerato;
 * - una riga vuota fra due paragrafi, un a capo semplice dentro il paragrafo.
 *
 * Sono le stesse cose che permette l'editor del sito (grassetto, corsivo,
 * link, titoli, elenchi): una descrizione scritta sul sito e salvata dal
 * telefono non perde la formattazione. Il server ripulisce comunque l'HTML
 * che riceve (`Description::sanitize`).
 */
internal object RichText {
    /** L'HTML del sito come testo da modificare. */
    fun toMarkup(html: String?): String {
        if (html.isNullOrBlank()) return ""
        if (!html.contains('<')) return decode(html).trim()
        val out = StringBuilder()
        val lists = ArrayDeque<ListState>()
        var href: String? = null

        fun breakBlock() {
            if (out.isEmpty()) return
            val trimmed = out.trimEnd(' ')
            out.setLength(trimmed.length)
            if (!out.endsWith("\n\n")) out.append(if (out.endsWith("\n")) "\n" else "\n\n")
        }

        TAG.findAll(html).fold(0) { cursor, match ->
            appendText(out, html.substring(cursor, match.range.first))
            val closing = match.groupValues[1] == "/"
            val name = match.groupValues[2].lowercase()
            val attributes = match.groupValues[3]
            when (name) {
                "p", "div", "blockquote" -> if (lists.isEmpty() && !closing) breakBlock()
                "br" -> out.append('\n')
                "h1", "h2", "h3", "h4" -> if (!closing) {
                    breakBlock(); out.append(if (name == "h3" || name == "h4") "### " else "## ")
                } else breakBlock()
                "ul", "ol" -> if (!closing) {
                    if (lists.isEmpty()) breakBlock() else if (!out.endsWith("\n")) out.append('\n')
                    lists.addLast(ListState(ordered = name == "ol"))
                } else {
                    lists.removeLastOrNull()
                    if (lists.isEmpty()) breakBlock()
                }
                "li" -> if (!closing) {
                    if (out.isNotEmpty() && !out.endsWith("\n")) out.append('\n')
                    val list = lists.lastOrNull()
                    val indent = "  ".repeat((lists.size - 1).coerceAtLeast(0))
                    if (list?.ordered == true) { list.count++; out.append(indent).append(list.count).append(". ") } else out.append(indent).append("- ")
                }
                "strong", "b" -> out.append("**")
                "em", "i" -> out.append('*')
                "a" -> if (!closing) {
                    href = HREF.find(attributes)?.groupValues?.get(2)?.let(::decode)
                    if (href != null) out.append('[')
                } else {
                    href?.let { out.append("](").append(it).append(')') }
                    href = null
                }
            }
            match.range.last + 1
        }.let { cursor -> appendText(out, html.substring(cursor)) }

        return out.toString().replace(Regex("[ \\t]+\n"), "\n").replace(Regex("\n{3,}"), "\n\n").trim()
    }

    /** Il testo scritto sul telefono come HTML per il server. */
    fun toHtml(markup: String): String {
        val lines = markup.replace("\r\n", "\n").split('\n')
        val html = StringBuilder()
        val paragraph = mutableListOf<String>()
        var list: String? = null

        fun flushParagraph() {
            if (paragraph.isNotEmpty()) {
                html.append("<p>").append(paragraph.joinToString("<br>") { inline(it) }).append("</p>")
                paragraph.clear()
            }
        }
        fun closeList() {
            list?.let { html.append("</").append(it).append('>') }
            list = null
        }

        for (raw in lines) {
            val line = raw.trimEnd()
            val trimmed = line.trimStart()
            val bullet = BULLET.matchEntire(trimmed)
            val number = NUMBERED.matchEntire(trimmed)
            when {
                trimmed.isEmpty() -> { flushParagraph(); closeList() }
                trimmed.startsWith("### ") -> { flushParagraph(); closeList(); html.append("<h3>").append(inline(trimmed.removePrefix("### ").trim())).append("</h3>") }
                trimmed.startsWith("## ") || trimmed.startsWith("# ") -> {
                    flushParagraph(); closeList()
                    html.append("<h2>").append(inline(trimmed.substringAfter(' ').trim())).append("</h2>")
                }
                bullet != null || number != null -> {
                    flushParagraph()
                    val wanted = if (bullet != null) "ul" else "ol"
                    if (list != wanted) { closeList(); html.append('<').append(wanted).append('>'); list = wanted }
                    val text = (bullet ?: number)!!.groupValues[1]
                    html.append("<li><p>").append(inline(text.trim())).append("</p></li>")
                }
                else -> { closeList(); paragraph += line.trim() }
            }
        }
        flushParagraph()
        closeList()
        return html.toString()
    }

    /** Il testo senza segni, per l'anteprima e per la lettura ad alta voce. */
    fun plain(markup: String): String = markup.lines().joinToString("\n") { line ->
        val text = line.trimStart().let { trimmed ->
            when {
                trimmed.startsWith("### ") -> trimmed.removePrefix("### ")
                trimmed.startsWith("## ") -> trimmed.removePrefix("## ")
                trimmed.startsWith("- ") -> "• " + trimmed.removePrefix("- ")
                else -> trimmed
            }
        }
        LINK.replace(text) { it.groupValues[1] }.replace("**", "").replace(Regex("(?<![*\\w])\\*(?!\\s)(.+?)(?<!\\s)\\*")) { it.groupValues[1] }
    }.trim()

    private fun inline(text: String): String {
        var html = escape(text)
        html = LINK_ESCAPED.replace(html) { "<a href=\"${it.groupValues[2]}\">${it.groupValues[1]}</a>" }
        html = BOLD.replace(html) { "<strong>${it.groupValues[1]}</strong>" }
        html = ITALIC.replace(html) { "<em>${it.groupValues[1]}</em>" }
        return html
    }

    private fun appendText(out: StringBuilder, text: String) {
        if (text.isEmpty()) return
        val decoded = decode(text).replace(Regex("[\\s&&[^\\u00A0]]+"), " ").replace('\u00A0', ' ')
        if (decoded.isBlank() && (out.isEmpty() || out.endsWith("\n") || out.endsWith(" "))) return
        out.append(if (out.isEmpty() || out.endsWith("\n")) decoded.trimStart() else decoded)
    }

    private fun escape(text: String): String = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;")

    private fun decode(text: String): String = ENTITY.replace(text) { match ->
        val entity = match.groupValues[1]
        when {
            entity.startsWith("#x") || entity.startsWith("#X") -> entity.drop(2).toIntOrNull(16)?.let { String(Character.toChars(it)) } ?: match.value
            entity.startsWith("#") -> entity.drop(1).toIntOrNull()?.let { String(Character.toChars(it)) } ?: match.value
            else -> NAMED[entity] ?: match.value
        }
    }

    private class ListState(val ordered: Boolean, var count: Int = 0)

    private val TAG = Regex("<(/?)([a-zA-Z0-9]+)([^>]*)>")
    private val HREF = Regex("href\\s*=\\s*([\"'])(.*?)\\1", RegexOption.IGNORE_CASE)
    private val ENTITY = Regex("&(#?[a-zA-Z0-9]+);")
    private val BULLET = Regex("^[-•]\\s+(.*)$")
    private val NUMBERED = Regex("^\\d+[.)]\\s+(.*)$")
    private val LINK = Regex("\\[([^\\]]+)]\\((https?://[^)\\s]+)\\)")
    private val LINK_ESCAPED = Regex("\\[([^\\]]+)]\\((https?://[^)\\s]+)\\)")
    private val BOLD = Regex("\\*\\*(.+?)\\*\\*")
    private val ITALIC = Regex("(?<![*\\w])\\*(?![\\s*])(.+?)(?<![\\s*])\\*(?![*\\w])")
    private val NAMED = mapOf(
        "amp" to "&", "lt" to "<", "gt" to ">", "quot" to "\"", "apos" to "'", "nbsp" to "\u00A0",
        "rsquo" to "’", "lsquo" to "‘", "rdquo" to "”", "ldquo" to "“", "hellip" to "…", "ndash" to "–", "mdash" to "—",
        "egrave" to "è", "eacute" to "é", "agrave" to "à", "ograve" to "ò", "ugrave" to "ù", "igrave" to "ì", "euro" to "€",
    )
}
