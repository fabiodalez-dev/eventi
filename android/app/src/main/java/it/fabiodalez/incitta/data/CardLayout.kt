package it.fabiodalez.incitta.data

/*
 * La vista degli elenchi, come sul sito (`resources/js/card-layout.js`):
 * `full` card a tutta riga, `grid` due colonne, `list` riga compatta con la
 * locandina a sinistra. Chi ha un accesso la tiene nel profilo, e la scelta
 * fatta qui vale anche sul sito dal telefono, e viceversa.
 */
val CARD_LAYOUTS = listOf("full", "grid", "list")

/** Le righe di un elenco: una card per riga, oppure due affiancate nella griglia. */
fun <T> cardRows(items: List<T>, layout: String): List<List<T>> =
    if (layout == "grid") items.chunked(2) else items.map(::listOf)
