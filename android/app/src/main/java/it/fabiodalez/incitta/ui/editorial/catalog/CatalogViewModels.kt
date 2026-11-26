package it.fabiodalez.incitta.ui.editorial.catalog

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.editorial.CatalogChoices
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CatalogRecord
import it.fabiodalez.incitta.data.editorial.CatalogSectionSummary
import it.fabiodalez.incitta.data.editorial.EditorialCatalogApi
import it.fabiodalez.incitta.data.editorial.EditorialCatalogSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json

/*
 * R11 — Catalogo (R-CT-01…07). I ViewModel portano le richieste e tengono lo
 * stato: criteri degli elenchi e bozze dei moduli sopravvivono alla
 * rotazione. Chi può fare che cosa lo dicono `sections` dell'ingresso e le
 * `abilities` di ogni voce, calcolate dal server con le policy del pannello.
 * Ogni chiamata passa da `repository.call { }`: sui 423 (sblocco scaduto,
 * password recente per eliminare, unire e modificare una città) chiede la
 * password e riprova.
 */

/** Le API dell'area con il client e il token della sessione. */
internal fun catalogSource(repository: EditorialRepository): EditorialCatalogSource =
    EditorialCatalogApi(checkNotNull(repository.client) { "client della redazione mancante" }, repository.token) { repository.selectedCity }

private suspend fun <T> loadable(block: suspend () -> T): Loadable<T> = try {
    Loadable.Ready(block())
} catch (e: CancellationException) {
    throw e
} catch (e: Exception) {
    Loadable.Failed(requestFailureMessage(e))
}

/** L'ingresso dell'area: le voci che la persona vede, con il conteggio. */
internal class CatalogHomeViewModel(
    private val repository: EditorialRepository,
    private val api: EditorialCatalogSource,
) : ViewModel() {
    private val state = MutableStateFlow<Loadable<List<CatalogSectionSummary>>>(Loadable.Loading)
    val sections: StateFlow<Loadable<List<CatalogSectionSummary>>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            val result = loadable { repository.call { api.overview() }.sections.filter { it.kind != null } }
            state.value = if (result is Loadable.Ready && result.value.isEmpty()) Loadable.Empty else result
        }
    }
}

/** I filtri sì/no di ogni voce, come i `TernaryFilter` della tabella del pannello. */
internal fun catalogFilters(kind: CatalogKind): List<Pair<String, Int>> = when (kind) {
    CatalogKind.Categories -> listOf("is_active" to R.string.editorial_catalog_field_active, "is_nightlife" to R.string.editorial_catalog_field_nightlife)
    CatalogKind.Tags -> listOf("is_approved" to R.string.editorial_catalog_field_approved)
    CatalogKind.Features -> emptyList()
    CatalogKind.Cities -> listOf("is_active" to R.string.editorial_catalog_field_active)
    CatalogKind.Pages -> listOf("is_published" to R.string.editorial_catalog_field_published)
}

/** La finestra «Unisci» di un tag: il tag che resta, la ricerca del doppione, la scelta. */
internal data class MergeState(
    val keep: CatalogRecord,
    val query: String = "",
    val results: Loadable<List<CatalogRecord>> = Loadable.Loading,
    val chosen: CatalogRecord? = null,
)

/** Lo stato delle azioni di riga: la conferma aperta, l'unione, la richiesta in corso, l'esito. */
internal data class CatalogActionsState(
    val pendingDelete: CatalogRecord? = null,
    val merge: MergeState? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val message: UiText? = null,
)

/** L'elenco di una voce: ricerca, filtri, ordinamento, eliminazione e (per i tag) unione dalla riga. */
internal class CatalogListViewModel(
    private val repository: EditorialRepository,
    private val api: EditorialCatalogSource,
    private val saved: SavedStateHandle,
    val kind: CatalogKind,
) : ViewModel() {
    val list = PagedListController(
        viewModelScope,
        ListQuery(q = saved[KEY_Q] ?: "", filters = savedFilters(saved), sort = saved[KEY_SORT]),
    ) { query, page -> repository.call { api.list(kind, query.params(page)) } }
    val rows: StateFlow<PagedState<CatalogRecord>> = list.current

    private val actionState = MutableStateFlow(CatalogActionsState())
    val actions: StateFlow<CatalogActionsState> = actionState.asStateFlow()
    private var mergeSearch: Job? = null

    init { list.refresh() }

    fun search(q: String) { saved[KEY_Q] = q; list.search(q) }
    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_FILTERS] = ArrayList(filters.filterValues { it.isNotBlank() }.map { "${it.key}=${it.value}" })
        list.applyFilters(filters)
    }
    fun sortBy(sort: String?) { saved[KEY_SORT] = sort; list.sortBy(sort) }
    fun refresh() = list.refresh()

    fun requestDelete(record: CatalogRecord) {
        if (!record.allows("destroy")) return
        actionState.update { it.copy(pendingDelete = record, error = null) }
    }

    fun dismiss() = actionState.update { if (it.busy) it else it.copy(pendingDelete = null, merge = null, error = null) }
    fun clearMessage() = actionState.update { it.copy(message = null) }

    fun confirmDelete() {
        val record = actionState.value.pendingDelete ?: return
        if (actionState.value.busy) return
        actionState.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val message = repository.call { api.destroy(kind, record.id) }
                list.remove { it.id == record.id }
                actionState.update { it.copy(busy = false, pendingDelete = null, message = message?.let(UiText::Raw) ?: UiText.Res(R.string.editorial_catalog_deleted)) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                actionState.update { it.copy(busy = false, error = requestFailureMessage(e)) }
            }
        }
    }

    /** «Unisci» di un tag: apre la finestra e cerca subito fra tutti i tag. */
    fun requestMerge(record: CatalogRecord) {
        if (kind != CatalogKind.Tags || !record.allows("merge")) return
        actionState.update { it.copy(merge = MergeState(record), error = null) }
        searchMerge("")
    }

    /** La ricerca del doppione, con una pausa come la ricerca degli elenchi; il tag che resta non è fra le scelte. */
    fun searchMerge(query: String) {
        val merge = actionState.value.merge ?: return
        actionState.update { it.copy(merge = merge.copy(query = query, results = Loadable.Loading)) }
        mergeSearch?.cancel()
        mergeSearch = viewModelScope.launch {
            if (query.isNotEmpty()) delay(MERGE_SEARCH_PAUSE)
            val result = loadable {
                repository.call { api.list(CatalogKind.Tags, ListQuery(q = query, perPage = MERGE_RESULTS).params(1)) }.data.filter { it.id != merge.keep.id }
            }
            val shown = if (result is Loadable.Ready && result.value.isEmpty()) Loadable.Empty else result
            actionState.update { now ->
                val open = now.merge
                if (open != null && open.keep.id == merge.keep.id && open.query == query) now.copy(merge = open.copy(results = shown)) else now
            }
        }
    }

    fun chooseMerge(record: CatalogRecord) = actionState.update { now -> now.copy(merge = now.merge?.copy(chosen = record)) }

    fun confirmMerge() {
        val merge = actionState.value.merge ?: return
        val absorbed = merge.chosen ?: return
        if (actionState.value.busy) return
        actionState.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val change = repository.call { api.mergeTag(merge.keep.id, absorbed.id) }
                list.remove { it.id == absorbed.id }
                list.replace({ it.id == change.record.id }, change.record)
                actionState.update { it.copy(busy = false, merge = null, message = change.message?.let(UiText::Raw)) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                actionState.update { it.copy(busy = false, error = requestFailureMessage(e)) }
            }
        }
    }

    companion object {
        private const val KEY_Q = "catalog_q"
        private const val KEY_FILTERS = "catalog_filters"
        private const val KEY_SORT = "catalog_sort"
        private const val MERGE_SEARCH_PAUSE = 350L
        private const val MERGE_RESULTS = 20

        private fun savedFilters(saved: SavedStateHandle): Map<String, String> =
            saved.get<ArrayList<String>>(KEY_FILTERS).orEmpty().mapNotNull { entry ->
                val key = entry.substringBefore('=', "")
                val value = entry.substringAfter('=', "")
                if (key.isBlank() || value.isBlank()) null else key to value
            }.toMap()
    }
}

/** Dove va la schermata dopo un'azione riuscita. */
internal sealed interface CatalogDone {
    /** Voce nuova: si apre il suo modulo al posto di quello vuoto. */
    data class Created(val id: Long) : CatalogDone
    /** Voce eliminata: si torna all'elenco. */
    data object Deleted : CatalogDone
}

/** Lo stato del modulo, a parte la bozza. */
internal data class CatalogFormState(
    /* Il modulo salvato sul server: `Ready` con la voce (abilità, conteggi), o `Ready(null)` per una voce nuova. */
    val record: Loadable<CatalogRecord?> = Loadable.Loading,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    /* I problemi trovati sul telefono, campo del server → testo (risorsa): la schermata li mette sotto i campi come quelli del server. */
    val problems: Map<String, Int> = emptyMap(),
    val message: UiText? = null,
    /* 409 sul salvataggio di una pagina: qualcuno l'ha cambiata nel frattempo. */
    val conflict: Boolean = false,
    val confirmDelete: Boolean = false,
    val preview: Loadable<String>? = null,
    val done: CatalogDone? = null,
)

/**
 * Il modulo di una voce, nuova (`id` nullo) o salvata. La bozza vive nel
 * `SavedStateHandle`: una rotazione non perde quello che si sta scrivendo e
 * non la sostituisce con quella del server.
 */
internal class CatalogFormViewModel(
    private val repository: EditorialRepository,
    private val api: EditorialCatalogSource,
    private val saved: SavedStateHandle,
    val kind: CatalogKind,
    private val id: Long?,
) : ViewModel() {
    private val draftState = MutableStateFlow(restoreDraft() ?: CatalogDraft.empty(kind))
    val draft: StateFlow<CatalogDraft> = draftState.asStateFlow()

    private val formState = MutableStateFlow(CatalogFormState(record = if (id == null) Loadable.Ready(null) else Loadable.Loading))
    val state: StateFlow<CatalogFormState> = formState.asStateFlow()

    private val choicesState = MutableStateFlow<Loadable<CatalogChoices>>(Loadable.Loading)
    val choices: StateFlow<Loadable<CatalogChoices>> = choicesState.asStateFlow()

    val isNew: Boolean get() = id == null

    init {
        if (id != null) load(keepDraft = saved.contains(KEY_DRAFT))
        loadChoices()
    }

    fun loadChoices() {
        choicesState.value = Loadable.Loading
        viewModelScope.launch { choicesState.value = loadable { repository.call { api.choices() } } }
    }

    /** Rilegge la voce dal server; `keepDraft` falso sostituisce la bozza (dopo un 409, «Ricarica»). */
    fun load(keepDraft: Boolean = false) {
        val current = id ?: return
        formState.update { it.copy(record = Loadable.Loading, conflict = false, errors = FormErrors.None) }
        viewModelScope.launch {
            val result = loadable { repository.call { api.show(kind, current) } }
            if (result is Loadable.Ready && result.value != null && !keepDraft) setDraft(CatalogDraft.from(kind, result.value))
            formState.update { it.copy(record = result) }
        }
    }

    fun edit(transform: (CatalogDraft) -> CatalogDraft) {
        setDraft(transform(draftState.value))
        if (!formState.value.errors.isEmpty || formState.value.problems.isNotEmpty()) formState.update { it.copy(errors = FormErrors.None, problems = emptyMap()) }
    }

    fun clearMessage() = formState.update { it.copy(message = null) }

    /** Salva: prima i controlli che si fanno sul telefono, poi il server con le regole del pannello. */
    fun save(admin: Boolean) {
        if (formState.value.busy) return
        val now = draftState.value
        val problems = now.problems(kind)
        if (problems.isNotEmpty()) {
            formState.update { it.copy(problems = problems, errors = FormErrors.None, message = null) }
            return
        }
        formState.update { it.copy(busy = true, problems = emptyMap(), errors = FormErrors.None, conflict = false) }
        viewModelScope.launch {
            try {
                val body = now.toJson(kind, admin)
                val change = repository.call { if (id == null) api.create(kind, body) else api.update(kind, id, body) }
                if (id == null) {
                    formState.update { it.copy(busy = false, done = CatalogDone.Created(change.record.id)) }
                } else {
                    setDraft(CatalogDraft.from(kind, change.record))
                    formState.update { it.copy(busy = false, record = Loadable.Ready(change.record), message = change.message?.let(UiText::Raw) ?: UiText.Res(R.string.editorial_catalog_saved)) }
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val conflict = (e as? ApiException)?.status == 409 && kind == CatalogKind.Pages
                formState.update { it.copy(busy = false, conflict = conflict, errors = FormErrors.from(e, CatalogDraft.fieldKeys(kind))) }
            }
        }
    }

    fun requestDelete() {
        val record = (formState.value.record as? Loadable.Ready)?.value ?: return
        if (record.allows("destroy")) formState.update { it.copy(confirmDelete = true, errors = FormErrors.None) }
    }

    fun dismissDelete() = formState.update { if (it.busy) it else it.copy(confirmDelete = false) }

    fun confirmDelete() {
        val current = id ?: return
        if (formState.value.busy) return
        formState.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                repository.call { api.destroy(kind, current) }
                formState.update { it.copy(busy = false, confirmDelete = false, done = CatalogDone.Deleted) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                formState.update { it.copy(busy = false, confirmDelete = false, errors = FormErrors(general = requestFailureMessage(e))) }
            }
        }
    }

    /** L'anteprima del corpo di una pagina, con la conversione del sito. */
    fun preview() {
        if (kind != CatalogKind.Pages) return
        val body = draftState.value.body
        formState.update { it.copy(preview = Loadable.Loading) }
        viewModelScope.launch {
            val result = loadable { repository.call { api.previewPage(body) } }
            formState.update { it.copy(preview = if (result is Loadable.Ready && result.value.isBlank()) Loadable.Empty else result) }
        }
    }

    fun closePreview() = formState.update { it.copy(preview = null) }

    /** Dopo che la schermata ha seguito `done`. */
    fun consumeDone() = formState.update { it.copy(done = null) }

    private fun setDraft(next: CatalogDraft) {
        draftState.value = next
        saved[KEY_DRAFT] = JSON.encodeToString(CatalogDraft.serializer(), next)
    }

    private fun restoreDraft(): CatalogDraft? = saved.get<String>(KEY_DRAFT)?.let { runCatching { JSON.decodeFromString(CatalogDraft.serializer(), it) }.getOrNull() }

    companion object {
        private const val KEY_DRAFT = "catalog_draft"
        private val JSON = Json { ignoreUnknownKeys = true }
    }
}
