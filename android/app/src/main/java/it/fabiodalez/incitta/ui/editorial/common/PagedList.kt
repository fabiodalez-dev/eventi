package it.fabiodalez.incitta.ui.editorial.common

import it.fabiodalez.incitta.data.management.countOf
import it.fabiodalez.incitta.data.requestFailureMessage
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/*
 * La convenzione degli elenchi della redazione (contratto delle fondamenta):
 * il server accetta `page`, `per_page` (al massimo 50), `q`, `tab`,
 * `filter[chiave]`, `sort`, e risponde
 * `{"data": [...], "meta": {"current_page", "last_page", "per_page", "total", "tabs": {"pending": 4}}}`.
 * Ogni riga porta le sue `abilities` (vedi `ActionMenu`).
 */

/** I criteri di un elenco: ricerca, scheda, filtri, ordinamento, righe per pagina. */
internal data class ListQuery(
    val q: String = "",
    val tab: String? = null,
    val filters: Map<String, String> = emptyMap(),
    val sort: String? = null,
    val perPage: Int = DEFAULT_PER_PAGE,
) {
    /** I parametri della pagina `page`, sempre nello stesso ordine (filtri per chiave). */
    fun params(page: Int): List<Pair<String, String>> = buildList {
        add("page" to page.coerceAtLeast(1).toString())
        add("per_page" to perPage.coerceIn(1, MAX_PER_PAGE).toString())
        q.trim().takeIf { it.isNotEmpty() }?.let { add("q" to it) }
        tab?.takeIf { it.isNotBlank() }?.let { add("tab" to it) }
        filters.toSortedMap().forEach { (key, value) -> if (value.isNotBlank()) add("filter[$key]" to value) }
        sort?.takeIf { it.isNotBlank() }?.let { add("sort" to it) }
    }

    /** Quanti filtri sono accesi: il numero accanto a «Filtri». */
    val activeFilters: Int get() = filters.count { it.value.isNotBlank() }

    companion object {
        const val DEFAULT_PER_PAGE = 20
        const val MAX_PER_PAGE = 50
    }
}

@Serializable
internal data class PageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    @SerialName("per_page") val perPage: Int = ListQuery.DEFAULT_PER_PAGE,
    val total: Int = 0,
    /* I conteggi per scheda, se il server li manda: `{"pending": 4, "published": 120}`. */
    val tabs: JsonElement? = null,
) {
    val hasMore: Boolean get() = currentPage < lastPage
    fun tabCounts(): Map<String, Int> = (tabs as? JsonObject)?.mapValues { (_, value) -> countOf(value) } ?: emptyMap()
}

/** Una pagina di un elenco della redazione. */
@Serializable
internal data class EditorialPage<T>(val data: List<T> = emptyList(), val meta: PageMeta = PageMeta())

/** Lo stato di un elenco paginato, come lo disegna `PagedListScreen`. */
internal data class PagedState<T>(
    val query: ListQuery = ListQuery(),
    val items: List<T> = emptyList(),
    /* L'ultima pagina arrivata; 0 finché non ne è arrivata nessuna. */
    val page: Int = 0,
    val hasMore: Boolean = true,
    val total: Int = 0,
    val loading: Boolean = false,
    val error: String? = null,
    val tabCounts: Map<String, Int> = emptyMap(),
) {
    /** Vuoto davvero: almeno una pagina arrivata, nessuna riga, nessun errore. */
    val isEmpty: Boolean get() = page > 0 && items.isEmpty() && !loading && error == null
}

/**
 * Il motore di un elenco: carica la prima pagina, aggiunge le successive
 * scorrendo, ricomincia quando cambiano ricerca, scheda, filtri o
 * ordinamento. Una sola richiesta alla volta: un criterio nuovo annulla
 * quella in corso, così una risposta vecchia non sovrascrive la nuova.
 *
 * `load` riceve i criteri e la pagina e chiama il server, di solito dentro
 * `repository.call { }` per i 423 della redazione.
 */
internal class PagedListController<T>(
    private val scope: CoroutineScope,
    initial: ListQuery = ListQuery(),
    private val load: suspend (ListQuery, Int) -> EditorialPage<T>,
) {
    private val state = MutableStateFlow(PagedState<T>(query = initial))
    val current: StateFlow<PagedState<T>> = state.asStateFlow()
    private var job: Job? = null

    /** Ricomincia dalla prima pagina con i criteri di adesso (all'apertura e dopo un'azione). */
    fun refresh() = restart(state.value.query)

    fun search(q: String) { if (q != state.value.query.q || state.value.page == 0) restart(state.value.query.copy(q = q)) }
    fun selectTab(tab: String?) = restart(state.value.query.copy(tab = tab))
    fun applyFilters(filters: Map<String, String>) = restart(state.value.query.copy(filters = filters.filterValues { it.isNotBlank() }))
    fun sortBy(sort: String?) = restart(state.value.query.copy(sort = sort))

    /** La pagina successiva, se c'è e se non se ne sta già caricando una. */
    fun loadMore() {
        val now = state.value
        if (now.loading || !now.hasMore || now.error != null || now.page == 0) return
        fetch(now.page + 1, append = true)
    }

    /** Dopo un errore: riprova la pagina che mancava (la prima, o la successiva). */
    fun retry() {
        val now = state.value
        if (now.page == 0) restart(now.query) else { state.update { it.copy(error = null) }; fetch(now.page + 1, append = true) }
    }

    /** Sostituisce una riga dopo un'azione (stesse `abilities` aggiornate dal server), senza ricaricare l'elenco. */
    fun replace(predicate: (T) -> Boolean, row: T) = state.update { now -> now.copy(items = now.items.map { if (predicate(it)) row else it }) }

    /** Toglie una riga che con i criteri attuali non c'è più (pubblicata, eliminata). */
    fun remove(predicate: (T) -> Boolean) = state.update { now -> now.copy(items = now.items.filterNot(predicate), total = (now.total - now.items.count(predicate)).coerceAtLeast(0)) }

    private fun restart(query: ListQuery) {
        job?.cancel()
        state.update { PagedState(query = query, loading = true, tabCounts = it.tabCounts) }
        fetch(1, append = false)
    }

    private fun fetch(page: Int, append: Boolean) {
        val query = state.value.query
        state.update { it.copy(loading = true, error = null) }
        job = scope.launch {
            try {
                val result = load(query, page)
                state.update { now ->
                    if (now.query != query) now
                    else now.copy(
                        items = if (append) now.items + result.data else result.data,
                        page = result.meta.currentPage.coerceAtLeast(page),
                        hasMore = result.meta.hasMore,
                        total = result.meta.total,
                        loading = false,
                        tabCounts = result.meta.tabCounts().ifEmpty { now.tabCounts },
                    )
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                state.update { now -> if (now.query != query) now else now.copy(loading = false, error = requestFailureMessage(e)) }
            }
        }
    }
}
