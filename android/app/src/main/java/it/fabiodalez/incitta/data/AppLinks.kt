package it.fabiodalez.incitta.data

private val EVENT_DAY = Regex("[0-9]{4}-[0-9]{2}-[0-9]{2}")

/*
 * Con gli App Links verificati Android consegna all'app tutti i percorsi del
 * manifest, senza passare dal browser. Questi esistono solo sul sito: file da
 * scaricare e moduli che l'app non ha. Aperti qui si perderebbero, mostrando
 * la scheda al posto del modulo o niente del tutto.
 */
internal fun isWebOnlyLink(parts: List<String>): Boolean {
    val last = parts.lastOrNull().orEmpty()
    return when {
        last.endsWith(".ics") || last.endsWith(".pdf") -> true
        parts.size == 3 && parts[0] in setOf("eventi", "locali") && last == "segnala" -> true
        parts.size == 3 && parts[0] == "locali" && last == "rivendica" -> true
        parts.size == 2 && parts[0] == "eventi" && EVENT_DAY.matches(last) -> true
        else -> false
    }
}

/* L'app stessa risponde ai collegamenti del sito: va esclusa, o il collegamento torna qui. */
internal fun pickBrowser(preferred: String?, installed: List<String>, self: String): String? =
    preferred?.takeIf { it != self && it in installed } ?: installed.firstOrNull { it != self }
