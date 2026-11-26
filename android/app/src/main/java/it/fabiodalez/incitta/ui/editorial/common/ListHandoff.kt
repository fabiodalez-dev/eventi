package it.fabiodalez.incitta.ui.editorial.common

import androidx.lifecycle.SavedStateHandle
import androidx.navigation.NavHostController
import java.util.concurrent.ConcurrentHashMap
import kotlinx.serialization.builtins.MapSerializer
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.json.Json

/*
 * Aprire un elenco della redazione già filtrato da un'altra schermata: i
 * riquadri del riepilogo (R1) portano a «eventi in attesa», «segnalazioni
 * aperte», «iscrizioni in attesa», come i collegamenti del pannello.
 *
 * Chi apre l'elenco naviga sulla destinazione dell'area e lascia nella sua
 * voce di navigazione il nome dell'elenco, la scheda e i filtri
 * (`openEditorialList`). L'elenco li prende una volta sola
 * (`takeEditorialListTarget`) e parte da lì:
 *
 *     EditorialListHandoff.accept("events")          // nel grafo dell'area, una volta
 *     composable<EventsRoute> { entry ->
 *         val target = remember(entry) { entry.savedStateHandle.takeEditorialListTarget() }
 *         val initial = target?.applyTo(ListQuery()) ?: ListQuery()
 *         …
 *     }
 *
 * Un elenco che non chiama `accept` non riceve niente: chi apre ricade sulla
 * pagina del pannello già filtrata, che è quello che il riepilogo fa finché
 * l'elenco non è nativo. Così nessun riquadro apre un elenco senza il suo
 * filtro.
 */

/** Nome dell'elenco (`events`, `venues`, `applications`, `reports`, `import_sources`, `users`), scheda e filtri. */
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
}

/** Gli elenchi che sanno ricevere una destinazione: li dichiarano i grafi delle aree. */
internal object EditorialListHandoff {
    private val lists = ConcurrentHashMap.newKeySet<String>()

    /** Da chiamare nel grafo dell'area per ogni elenco che legge `takeEditorialListTarget`. */
    fun accept(list: String) { lists += list }

    fun accepts(list: String): Boolean = list in lists

    internal const val KEY_LIST = "editorial.list.name"
    internal const val KEY_TAB = "editorial.list.tab"
    internal const val KEY_FILTERS = "editorial.list.filters"

    private val filtersSerializer = MapSerializer(String.serializer(), String.serializer())

    internal fun encodeFilters(filters: Map<String, String>): String = Json.encodeToString(filtersSerializer, filters)

    internal fun decodeFilters(value: String?): Map<String, String> =
        value?.let { runCatching { Json.decodeFromString(filtersSerializer, it) }.getOrNull() } ?: emptyMap()
}

/** Scrive la destinazione nella voce di navigazione di un elenco. */
internal fun SavedStateHandle.putEditorialListTarget(target: EditorialListTarget) {
    this[EditorialListHandoff.KEY_LIST] = target.list
    this[EditorialListHandoff.KEY_TAB] = target.tab
    this[EditorialListHandoff.KEY_FILTERS] = EditorialListHandoff.encodeFilters(target.filters)
}

/** La destinazione lasciata da chi ha aperto l'elenco, una volta sola: dopo la lettura non c'è più. */
internal fun SavedStateHandle.takeEditorialListTarget(): EditorialListTarget? {
    val list = remove<String>(EditorialListHandoff.KEY_LIST) ?: return null
    val tab = remove<String>(EditorialListHandoff.KEY_TAB)
    val filters = EditorialListHandoff.decodeFilters(remove<String>(EditorialListHandoff.KEY_FILTERS))
    return EditorialListTarget(list, tab, filters)
}

/** Apre la destinazione `entry` di un'area e le lascia l'elenco da mostrare già filtrato. */
internal fun NavHostController.openEditorialList(entry: Any, target: EditorialListTarget) {
    navigate(entry)
    currentBackStackEntry?.savedStateHandle?.putEditorialListTarget(target)
}
