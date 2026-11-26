package it.fabiodalez.incitta.ui.editorial.common

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import java.util.concurrent.ConcurrentHashMap

/*
 * Aprire un elenco della redazione già filtrato da un'altra schermata: i
 * riquadri del riepilogo (R1) portano a «eventi in attesa», «segnalazioni
 * aperte», «iscrizioni in attesa», come i collegamenti del pannello.
 *
 * Ogni area dichiara nel suo grafo, una volta, gli elenchi che sa ricevere:
 * il nome dell'elenco (quello di `target.area` del riepilogo), chi lo apre
 * e la rotta dell'elenco con la scheda e i filtri nei suoi argomenti
 * (`tab`, `filter` = `chiave:valore,chiave:valore`):
 *
 *     EditorialListHandoff.accept("events", { it.eventsView }) { target ->
 *         EditorialEventsListRoute(target.tab.orEmpty(), target.filterParam)
 *     }
 *
 * L'elenco legge la destinazione dal suo stato salvato una volta sola
 * (`takeEditorialListTarget`) e parte da lì; dopo valgono i criteri che la
 * persona sceglie, anche quando il sistema chiude e riapre l'app.
 *
 * Un elenco che non è dichiarato non riceve niente: chi apre ricade sulla
 * pagina del pannello già filtrata, che è quello che il riepilogo fa finché
 * l'elenco non è nativo. Così nessun riquadro apre un elenco senza il suo
 * filtro.
 */

/** Nome dell'elenco (`events`, `venues`, `applications`, `reports`, `comments`, `reviews`, `users`, …), scheda e filtri. */
internal data class EditorialListTarget(
    val list: String,
    val tab: String? = null,
    val filters: Map<String, String> = emptyMap(),
) {
    /** I criteri di partenza dell'elenco: la scheda e i filtri della destinazione sopra quelli predefiniti. */
    fun applyTo(query: ListQuery): ListQuery = query.copy(
        tab = tab ?: query.tab,
        filters = (query.filters + filters).filterValues { it.isNotBlank() },
    )

    /** I filtri nell'argomento `filter` della rotta dell'elenco. */
    val filterParam: String get() = encodeEditorialFilterParam(filters)
}

/** `status:pending,missing_poster:1`, chiavi in ordine: l'argomento `filter` delle rotte degli elenchi. */
internal fun encodeEditorialFilterParam(filters: Map<String, String>): String =
    filters.filterValues { it.isNotBlank() }.toSortedMap().entries.joinToString(",") { "${it.key}:${it.value}" }

/** Il contrario di `encodeEditorialFilterParam`; le parti senza chiave o senza valore si scartano. */
internal fun parseEditorialFilterParam(value: String?): Map<String, String> = value.orEmpty().split(',').mapNotNull { pair ->
    val key = pair.substringBefore(':', "").trim()
    val item = pair.substringAfter(':', "").trim()
    if (key.isEmpty() || item.isEmpty()) null else key to item
}.toMap()

/** Gli elenchi che sanno ricevere una destinazione: li dichiarano i grafi delle aree. */
internal object EditorialListHandoff {
    private class Entry(val allowed: (EditorialPermissions) -> Boolean, val route: (EditorialListTarget) -> Any)

    private val lists = ConcurrentHashMap<String, Entry>()

    /**
     * Da chiamare nel grafo dell'area per ogni elenco che legge
     * `takeEditorialListTarget`: `allowed` è il permesso che apre l'elenco,
     * `route` la sua rotta con scheda e filtri.
     */
    fun accept(list: String, allowed: (EditorialPermissions) -> Boolean, route: (EditorialListTarget) -> Any) {
        lists[list] = Entry(allowed, route)
    }

    fun accepts(list: String): Boolean = lists.containsKey(list)

    /** La rotta dell'elenco già filtrato; `null` se l'elenco non è nativo o la persona non lo apre. */
    fun route(target: EditorialListTarget, can: EditorialPermissions): Any? =
        lists[target.list]?.takeIf { it.allowed(can) }?.route?.invoke(target)

    /* Gli argomenti delle rotte degli elenchi e il segno «già letta» nello stato salvato. */
    internal const val ARG_TAB = "tab"
    internal const val ARG_FILTER = "filter"
    internal const val KEY_TAKEN = "editorial.list.taken"
}

/**
 * La destinazione scritta negli argomenti della rotta dell'elenco (`tab`,
 * `filter`), una volta sola: la seconda lettura, anche dopo che il sistema
 * ha chiuso e riaperto l'app, dà `null`, così restano i criteri che la
 * persona ha scelto nel frattempo. `null` anche se la rotta non porta né
 * scheda né filtri (l'elenco aperto dal menu).
 */
internal fun SavedStateHandle.takeEditorialListTarget(list: String): EditorialListTarget? {
    if (get<Boolean>(EditorialListHandoff.KEY_TAKEN) == true) return null
    this[EditorialListHandoff.KEY_TAKEN] = true
    val tab = get<String>(EditorialListHandoff.ARG_TAB)?.takeIf { it.isNotBlank() }
    val filters = parseEditorialFilterParam(get<String>(EditorialListHandoff.ARG_FILTER))
    if (tab == null && filters.isEmpty()) return null
    return EditorialListTarget(list, tab, filters)
}
