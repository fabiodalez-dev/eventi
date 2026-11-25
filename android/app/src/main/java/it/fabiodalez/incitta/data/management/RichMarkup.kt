package it.fabiodalez.incitta.data.management

/*
 * L'editor minimo della descrizione (decisione 4, opzione b: nessuna
 * libreria). Sul sito i testi lunghi sono HTML scritto con l'editor del
 * pannello: grassetto, corsivo, sottolineato, barrato, link, titoli H2 e H3,
 * citazioni, elenchi puntati e numerati. Nell'app si scrivono in un campo di
 * testo con pochi segni, che i pulsanti sopra il campo inseriscono da soli:
 *
 *     ## Titolo            ### Titoletto        > citazione
 *     - voce di elenco     1. voce numerata
 *     **grassetto**  *corsivo*  __sottolineato__  ~~barrato~~  [testo](https://…)
 *
 * Una riga vuota separa i paragrafi; un a capo dentro un paragrafo resta un a
 * capo. Il server riceve HTML con i soli tag che il suo filtro accetta
 * (`Description::sanitize`), quindi un testo scritto sul sito e salvato
 * dall'app conserva la sua formattazione. Elenchi annidati e attributi che il
 * filtro del sito non tiene vengono appiattiti. Un segno da scrivere così
 * com'è si scrive preceduto da `\`.
 */
internal object RichMarkup {
    private const val SPECIAL = "\\*_~[]"

    /** Da HTML (o testo semplice, come lo salvano le versioni vecchie) ai segni dell'editor. */
    fun fromHtml(html: String?): String {
        if (html.isNullOrBlank()) return ""
        if (!html.contains('<')) return escapeText(decodeEntities(html)).trim()
        val out = StringBuilder()
        val lists = ArrayDeque<ListContext>()
        var quote = false
        var linkHref: String? = null
        /* Il segno di inizio riga in attesa (titolo, voce di elenco): si scrive con il primo testo della riga. */
        var pendingPrefix = ""
        var atLineStart = true

        fun blockBreak() {
            out.setLength(out.trimEnd(' ').length)
            atLineStart = true
            if (out.isEmpty()) return
            while (!out.endsWith("\n\n")) out.append('\n')
        }
        fun lineBreak() {
            out.setLength(out.trimEnd(' ').length)
            if (out.isNotEmpty() && !out.endsWith("\n")) out.append('\n')
            atLineStart = true
        }
        fun write(text: String) {
            if (text.isEmpty()) return
            if (atLineStart) {
                if (quote) out.append("> ")
                out.append(pendingPrefix)
                pendingPrefix = ""
                atLineStart = false
            }
            out.append(text)
        }

        for (token in tokenize(html)) {
            when (token) {
                is Token.Text -> {
                    val text = decodeEntities(token.text).replace(Regex("\\s+"), " ")
                    val clean = if (atLineStart) text.trimStart() else text
                    if (clean.isNotEmpty()) write(escapeText(clean))
                }
                is Token.Open -> when (token.name) {
                    "p", "div" -> if (lists.isEmpty()) { if (quote) lineBreak() else blockBreak() }
                    "h2" -> { blockBreak(); pendingPrefix = "## " }
                    "h3", "h4", "h5", "h6" -> { blockBreak(); pendingPrefix = "### " }
                    "blockquote" -> { blockBreak(); quote = true }
                    "ul", "ol" -> { if (lists.isEmpty()) blockBreak() else lineBreak(); lists.addLast(ListContext(token.name == "ol")) }
                    "li" -> {
                        lineBreak()
                        val list = lists.lastOrNull()
                        pendingPrefix = if (list?.ordered == true) "${++list.count}. " else "- "
                    }
                    "br" -> { if (lists.isEmpty()) lineBreak() else write(" ") }
                    "strong", "b" -> write("**")
                    "em", "i" -> write("*")
                    "u" -> write("__")
                    "s", "strike", "del" -> write("~~")
                    "a" -> { linkHref = token.href?.takeIf(::isSafeLink); if (linkHref != null) write("[") }
                    else -> Unit
                }
                is Token.Close -> when (token.name) {
                    "p", "div" -> if (lists.isEmpty()) { if (quote) lineBreak() else blockBreak() }
                    "h2", "h3", "h4", "h5", "h6" -> { blockBreak(); pendingPrefix = "" }
                    "blockquote" -> { blockBreak(); quote = false }
                    "ul", "ol" -> { lists.removeLastOrNull(); if (lists.isEmpty()) blockBreak() }
                    "li" -> { lineBreak(); pendingPrefix = "" }
                    "strong", "b" -> write("**")
                    "em", "i" -> write("*")
                    "u" -> write("__")
                    "s", "strike", "del" -> write("~~")
                    "a" -> { linkHref?.let { write("]($it)") }; linkHref = null }
                    else -> Unit
                }
            }
        }
        return out.toString().lines().joinToString("\n") { it.trimEnd() }.replace(Regex("\n{3,}"), "\n\n").trim()
    }

    /** Dai segni dell'editor all'HTML che il server accetta. Un testo vuoto resta vuoto. */
    fun toHtml(markup: String?): String {
        val lines = markup.orEmpty().replace("\r\n", "\n").split('\n')
        val html = StringBuilder()
        val paragraph = mutableListOf<String>()
        val quote = mutableListOf<String>()
        var list: Pair<Boolean, MutableList<String>>? = null

        fun flushParagraph() {
            if (paragraph.isNotEmpty()) html.append("<p>").append(paragraph.joinToString("<br>") { inline(it) }).append("</p>")
            paragraph.clear()
        }
        fun flushQuote() {
            if (quote.isNotEmpty()) html.append("<blockquote><p>").append(quote.joinToString("<br>") { inline(it) }).append("</p></blockquote>")
            quote.clear()
        }
        fun flushList() {
            list?.let { (ordered, items) ->
                val tag = if (ordered) "ol" else "ul"
                html.append("<$tag>")
                items.forEach { html.append("<li><p>").append(inline(it)).append("</p></li>") }
                html.append("</$tag>")
            }
            list = null
        }
        fun flushAll() { flushParagraph(); flushQuote(); flushList() }

        for (raw in lines) {
            val line = raw.trimEnd()
            val ordered = ORDERED.matchEntire(line.trimStart())
            when {
                line.isBlank() -> flushAll()
                line.startsWith("### ") -> { flushAll(); html.append("<h3>").append(inline(line.removePrefix("### ").trim())).append("</h3>") }
                line.startsWith("## ") -> { flushAll(); html.append("<h2>").append(inline(line.removePrefix("## ").trim())).append("</h2>") }
                line.startsWith(">") -> { flushParagraph(); flushList(); quote += line.removePrefix(">").trimStart() }
                line.trimStart().startsWith("- ") || line.trimStart().startsWith("• ") -> {
                    flushParagraph(); flushQuote()
                    if (list?.first != false) { flushList(); list = false to mutableListOf() }
                    list!!.second += line.trimStart().drop(2).trim()
                }
                ordered != null -> {
                    flushParagraph(); flushQuote()
                    if (list?.first != true) { flushList(); list = true to mutableListOf() }
                    list!!.second += ordered.groupValues[1].trim()
                }
                list != null && raw.startsWith("  ") -> list!!.second[list!!.second.lastIndex] += " " + line.trim()
                else -> { flushQuote(); flushList(); paragraph += line }
            }
        }
        flushAll()
        return html.toString()
    }

    /** Il testo semplice, senza segni: per le righe di stato («Descrizione: Musica dal vivo…»). */
    fun plain(html: String?): String =
        fromHtml(html).replace(Regex("(?m)^(#{2,3} |> |- |\\d+\\. )"), "")
            .replace(Regex("\\[([^\\]]*)]\\([^)]*\\)"), "$1")
            .replace(Regex("\\\\(.)|\\*\\*|__|~~|\\*")) { it.groupValues[1] }
            .replace(Regex("\\s+"), " ").trim()

    private val ORDERED = Regex("^\\d{1,3}[.)] (.*)$")

    /* Grassetto, corsivo, sottolineato, barrato e link dentro una riga. Un segno lasciato aperto si chiude a fine riga. */
    private fun inline(text: String): String {
        val out = StringBuilder()
        val open = ArrayDeque<String>()
        var i = 0
        fun toggle(tag: String) {
            if (open.lastOrNull() == tag) { open.removeLast(); out.append("</$tag>") }
            else if (tag in open) {
                /* Chiude in ordine quelli aperti dopo, poi riapre: l'HTML resta ben annidato. */
                val reopen = mutableListOf<String>()
                while (open.last() != tag) { val t = open.removeLast(); out.append("</$t>"); reopen += t }
                open.removeLast(); out.append("</$tag>")
                reopen.asReversed().forEach { open.addLast(it); out.append("<$it>") }
            } else { open.addLast(tag); out.append("<$tag>") }
        }
        while (i < text.length) {
            val c = text[i]
            when {
                c == '\\' && i + 1 < text.length && text[i + 1] in SPECIAL -> { out.append(escapeHtml(text[i + 1].toString())); i += 2 }
                /* `***` che chiude corsivo e grassetto insieme: prima il corsivo, aperto per ultimo. */
                text.startsWith("***", i) && open.lastOrNull() == "em" -> { toggle("em"); i += 1 }
                text.startsWith("**", i) -> { toggle("strong"); i += 2 }
                text.startsWith("__", i) -> { toggle("u"); i += 2 }
                text.startsWith("~~", i) -> { toggle("s"); i += 2 }
                c == '*' -> { toggle("em"); i += 1 }
                c == '[' -> {
                    val link = LINK.matchAt(text, i)
                    if (link != null && isSafeLink(link.groupValues[2])) {
                        out.append("<a href=\"").append(escapeHtml(link.groupValues[2])).append("\">").append(inline(link.groupValues[1])).append("</a>")
                        i += link.value.length
                    } else { out.append("["); i += 1 }
                }
                else -> { out.append(escapeHtml(c.toString())); i += 1 }
            }
        }
        while (open.isNotEmpty()) out.append("</${open.removeLast()}>")
        return out.toString()
    }

    private val LINK = Regex("\\[((?:\\\\.|[^\\]\\\\])*)]\\(([^)\\s]+)\\)")

    private fun isSafeLink(href: String): Boolean {
        val lower = href.trim().lowercase()
        return lower.startsWith("https://") || lower.startsWith("http://") || lower.startsWith("mailto:")
    }

    private fun escapeText(text: String): String = buildString { text.forEach { if (it in SPECIAL) append('\\'); append(it) } }

    private fun escapeHtml(text: String): String = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;")

    private fun decodeEntities(text: String): String = ENTITY.replace(text) { match ->
        val name = match.groupValues[1]
        when {
            name.startsWith("#x") || name.startsWith("#X") -> name.drop(2).toIntOrNull(16)?.let { String(Character.toChars(it)) }
            name.startsWith("#") -> name.drop(1).toIntOrNull()?.let { String(Character.toChars(it)) }
            else -> NAMED[name]
        } ?: match.value
    }

    private val ENTITY = Regex("&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z]{2,8});")
    private val NAMED = mapOf("amp" to "&", "lt" to "<", "gt" to ">", "quot" to "\"", "apos" to "'", "nbsp" to " ", "laquo" to "«", "raquo" to "»", "rsquo" to "’", "lsquo" to "‘", "egrave" to "è", "eacute" to "é", "agrave" to "à", "ograve" to "ò", "ugrave" to "ù", "igrave" to "ì", "hellip" to "…", "ndash" to "–", "mdash" to "—", "euro" to "€")

    private class ListContext(val ordered: Boolean) { var count = 0 }

    private sealed interface Token {
        data class Text(val text: String) : Token
        data class Open(val name: String, val href: String?) : Token
        data class Close(val name: String) : Token
    }

    private val TAG = Regex("<(/?)([a-zA-Z][a-zA-Z0-9]*)([^>]*)>")
    private val HREF = Regex("href\\s*=\\s*(\"([^\"]*)\"|'([^']*)')", RegexOption.IGNORE_CASE)

    private fun tokenize(html: String): List<Token> {
        val tokens = mutableListOf<Token>()
        var last = 0
        val source = html.replace(Regex("<!--.*?-->", RegexOption.DOT_MATCHES_ALL), "")
        for (match in TAG.findAll(source)) {
            if (match.range.first > last) tokens += Token.Text(source.substring(last, match.range.first))
            val name = match.groupValues[2].lowercase()
            if (match.groupValues[1] == "/") tokens += Token.Close(name)
            else {
                val href = HREF.find(match.groupValues[3])?.let { decodeEntities(it.groupValues[2].ifEmpty { it.groupValues[3] }) }
                tokens += Token.Open(name, href)
            }
            last = match.range.last + 1
        }
        if (last < source.length) tokens += Token.Text(source.substring(last))
        return tokens
    }
}
