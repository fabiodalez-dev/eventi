package it.fabiodalez.incitta.ui.editorial.events

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.editorial.EditorialActivityRow
import it.fabiodalez.incitta.data.editorial.EditorialEventDetail
import it.fabiodalez.incitta.data.editorial.EditorialEventFilters
import it.fabiodalez.incitta.data.editorial.EditorialEventResult
import it.fabiodalez.incitta.data.editorial.EditorialEventRow
import it.fabiodalez.incitta.data.editorial.EditorialEventsApi
import it.fabiodalez.incitta.data.editorial.EditorialEventsSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialSubmissionDetail
import it.fabiodalez.incitta.data.editorial.EditorialSubmissionRow
import it.fabiodalez.incitta.data.editorial.MergeCandidate
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.parseEditorialFilterParam
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import java.io.File
import java.time.LocalDateTime
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * I ViewModel dell'area eventi (R2). Ogni chiamata passa da
 * `repository.call { }`: con un 423 l'area chiede la password, sblocca e
 * ripete. Nessuna chiamata dentro `StateFlow.update { }`.
 */

/** Una decisione della redazione su un evento, come i pulsanti di `EventModeration`. */
internal sealed interface EventDecision {
    data object Publish : EventDecision
    data object Unpublish : EventDecision
    data class Reject(val reason: String) : EventDecision
    data class Cancel(val note: String?) : EventDecision
    data class Feature(val featured: Boolean, val until: String?) : EventDecision
    data object Duplicate : EventDecision
    data class Merge(val absorbed: Long) : EventDecision
    data object Destroy : EventDecision
    data object Restore : EventDecision
}

/** Com'è andata una decisione: la scheda aggiornata (o la copia), il messaggio, se l'evento è finito nel cestino. */
internal data class DecisionOutcome(
    val detail: EditorialEventDetail? = null,
    val message: String? = null,
    val trashed: Boolean = false,
    val copyId: Long? = null,
)

/** Esegue una decisione sull'evento `id` con l'API della redazione. */
internal suspend fun EditorialEventsSource.decide(id: Long, decision: EventDecision): DecisionOutcome {
    fun outcome(result: EditorialEventResult) = DecisionOutcome(result.data, listOfNotNull(result.message, result.detail).joinToString("\n").ifBlank { null })
    return when (decision) {
        EventDecision.Publish -> outcome(publish(id))
        EventDecision.Unpublish -> outcome(unpublish(id))
        is EventDecision.Reject -> outcome(reject(id, decision.reason))
        is EventDecision.Cancel -> outcome(cancel(id, decision.note))
        is EventDecision.Feature -> outcome(feature(id, decision.featured, decision.until))
        EventDecision.Duplicate -> duplicate(id).let { DecisionOutcome(it.data, it.message, copyId = it.data.id) }
        is EventDecision.Merge -> outcome(merge(id, decision.absorbed))
        EventDecision.Destroy -> destroy(id).let { DecisionOutcome(message = it.message, trashed = true) }
        EventDecision.Restore -> outcome(restore(id))
    }
}

/** Lo stato delle azioni di una schermata: occupata, ultimo messaggio, ultimo errore. */
internal data class ActionState(val busy: Boolean = false, val message: String? = null, val error: String? = null)

/** Le schede di `ListEvents`, nell'ordine del pannello: `null` è «Tutti». */
internal val EVENT_TABS: List<String?> = listOf(null, "pending", "published", "draft", "cancelled")

/** Le chiavi dei dodici filtri della tabella, con i valori che il server accetta. */
internal object EventFilterKeys {
    const val STATUS = "status"
    const val CITY = "city"
    const val VENUE = "venue"
    const val CATEGORY = "category"
    const val SOURCE = "source"
    const val FEATURED = "is_featured"
    const val TRASHED = "trashed"
    val FLAGS = listOf("published_today", "published_this_week", "missing_poster", "incomplete", "possible_duplicates")
}

/**
 * L'elenco degli eventi (R-EV-01…04, 17 in gruppo): schede, filtri,
 * ricerca, decisioni dalla riga, selezione per «Elimina» in gruppo,
 * esportazione in Excel. `tab` e filtri iniziali arrivano dalla rotta (i
 * riquadri del riepilogo aprono l'elenco già filtrato).
 */
internal class EventsQueueViewModel(
    private val repository: EditorialRepository,
    private val saved: SavedStateHandle,
    private val source: EditorialEventsSource = EditorialEventsApi.of(repository),
) : ViewModel() {
    private val initialFilters: Map<String, String> = saved.get<String>("filter")?.let(::parseFilterParam).orEmpty()
    val list = PagedListController(
        viewModelScope,
        ListQuery(tab = saved.get<String>("tab")?.takeIf { it.isNotBlank() }, filters = initialFilters),
    ) { query, page -> repository.call { source.list(query.params(page)) } }
    val rows: StateFlow<PagedState<EditorialEventRow>> = list.current

    private val filtersState = MutableStateFlow(EditorialEventFilters())
    val filters: StateFlow<EditorialEventFilters> = filtersState.asStateFlow()
    private val actionState = MutableStateFlow(ActionState())
    val action: StateFlow<ActionState> = actionState.asStateFlow()
    private val selectionState = MutableStateFlow<Set<Long>>(emptySet())
    /** Le righe scelte per «Elimina» in gruppo (al massimo 50, come il server). */
    val selection: StateFlow<Set<Long>> = selectionState.asStateFlow()
    private val exportState = MutableStateFlow<DownloadedFile?>(null)
    val exported: StateFlow<DownloadedFile?> = exportState.asStateFlow()
    private val copyState = MutableStateFlow<Long?>(null)
    /** La copia appena creata da «Duplica», da aprire. */
    val openCopy: StateFlow<Long?> = copyState.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch {
            try { filtersState.value = repository.call { source.filters() } } catch (e: CancellationException) { throw e } catch (_: Exception) { }
        }
    }

    fun toggleSelection(id: Long) = selectionState.update { if (id in it) it - id else if (it.size >= MAX_BULK) it else it + id }
    fun clearSelection() { selectionState.value = emptySet() }

    fun decide(row: EditorialEventRow, decision: EventDecision) {
        if (actionState.value.busy) return
        actionState.value = ActionState(busy = true)
        viewModelScope.launch {
            try {
                val outcome = repository.call { source.decide(row.id, decision) }
                actionState.value = ActionState(message = outcome.message)
                outcome.copyId?.let { copyState.value = it }
                list.refresh()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                actionState.value = ActionState(error = requestFailureMessage(e))
            }
        }
    }

    fun deleteSelected(messageFor: (Int, Int) -> String) {
        val ids = selectionState.value.toList()
        if (ids.isEmpty() || actionState.value.busy) return
        actionState.value = ActionState(busy = true)
        viewModelScope.launch {
            try {
                val result = repository.call { source.bulkDestroy(ids) }
                selectionState.value = emptySet()
                actionState.value = ActionState(message = messageFor(result.deleted.size, result.skipped))
                list.refresh()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                actionState.value = ActionState(error = requestFailureMessage(e))
            }
        }
    }

    /** Il foglio Excel delle righe che si stanno guardando, nella cache dell'app. */
    fun export(cacheDir: File) {
        if (actionState.value.busy) return
        actionState.value = ActionState(busy = true)
        val query = rows.value.query
        viewModelScope.launch {
            try {
                ManagementFiles.purge(cacheDir)
                val params = query.params(1).filterNot { it.first == "page" || it.first == "per_page" }
                exportState.value = repository.call { source.export(params, ManagementFiles.directory(cacheDir)) }
                actionState.value = ActionState()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                actionState.value = ActionState(error = requestFailureMessage(e))
            }
        }
    }

    fun consumeExport() { exportState.value = null }
    fun consumeCopy() { copyState.value = null }
    fun consumeMessage() = actionState.update { it.copy(message = null, error = null) }

    companion object {
        const val MAX_BULK = 50

        /** `status:pending,missing_poster:1`: i filtri iniziali di una rotta (la forma comune di `ListHandoff`). */
        fun parseFilterParam(value: String): Map<String, String> = parseEditorialFilterParam(value)
    }
}

/**
 * La scheda in sola lettura (R-EV-07) con le decisioni (R-EV-08…14, 17), i
 * collegamenti alle pagine collegate e «Unisci» con la ricerca dei doppioni.
 */
internal class EventRecordViewModel(
    private val repository: EditorialRepository,
    saved: SavedStateHandle,
    private val source: EditorialEventsSource = EditorialEventsApi.of(repository),
) : ViewModel() {
    val eventId: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow<Loadable<EditorialEventDetail>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<EditorialEventDetail>> = state.asStateFlow()
    private val actionState = MutableStateFlow(ActionState())
    val action: StateFlow<ActionState> = actionState.asStateFlow()
    private val candidatesState = MutableStateFlow<List<MergeCandidate>>(emptyList())
    val candidates: StateFlow<List<MergeCandidate>> = candidatesState.asStateFlow()
    private val copyState = MutableStateFlow<Long?>(null)
    val openCopy: StateFlow<Long?> = copyState.asStateFlow()
    private val trashedState = MutableStateFlow(false)
    /** Vero dopo «Elimina»: la schermata torna all'elenco. */
    val trashed: StateFlow<Boolean> = trashedState.asStateFlow()
    private var search: Job? = null

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            try { state.value = Loadable.Ready(repository.call { source.detail(eventId) }) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.value = Loadable.Failed(requestFailureMessage(e)) }
        }
    }

    fun decide(decision: EventDecision) {
        if (actionState.value.busy) return
        actionState.value = ActionState(busy = true)
        viewModelScope.launch {
            try {
                val outcome = repository.call { source.decide(eventId, decision) }
                when {
                    outcome.trashed -> trashedState.value = true
                    outcome.copyId != null -> copyState.value = outcome.copyId
                    outcome.detail != null -> state.value = Loadable.Ready(outcome.detail)
                }
                actionState.value = ActionState(message = outcome.message)
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                actionState.value = ActionState(error = requestFailureMessage(e))
            }
        }
    }

    /** I doppioni per titolo, con una pausa breve fra un tasto e l'altro. */
    fun searchCandidates(query: String) {
        search?.cancel()
        search = viewModelScope.launch {
            delay(SEARCH_PAUSE_MILLIS)
            try { candidatesState.value = repository.call { source.mergeCandidates(eventId, query.trim()) } }
            catch (e: CancellationException) { throw e }
            catch (_: Exception) { candidatesState.value = emptyList() }
        }
    }

    fun consumeCopy() { copyState.value = null }
    fun consumeMessage() = actionState.update { it.copy(message = null, error = null) }

    companion object { const val SEARCH_PAUSE_MILLIS = 300L }
}

/** La cronologia delle modifiche con «Ripristina» (R-EV-25). */
internal class EventActivityViewModel(
    private val repository: EditorialRepository,
    saved: SavedStateHandle,
    private val source: EditorialEventsSource = EditorialEventsApi.of(repository),
) : ViewModel() {
    val eventId: Long = saved.get<Long>("id") ?: 0L
    val list = PagedListController<EditorialActivityRow>(viewModelScope) { query, page -> repository.call { source.activity(eventId, query.params(page)) } }
    private val actionState = MutableStateFlow(ActionState())
    val action: StateFlow<ActionState> = actionState.asStateFlow()

    init { list.refresh() }

    fun restore(row: EditorialActivityRow) {
        if (actionState.value.busy) return
        actionState.value = ActionState(busy = true)
        viewModelScope.launch {
            try {
                val result = repository.call { source.restoreRevision(eventId, row.id) }
                actionState.value = ActionState(message = result.message)
                list.refresh()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                actionState.value = ActionState(error = requestFailureMessage(e))
            }
        }
    }

    fun consumeMessage() = actionState.update { it.copy(message = null, error = null) }
}

/** L'anteprima della pagina pubblica (R-EV-16, R-WB-01). */
internal class EventPreviewViewModel(
    private val repository: EditorialRepository,
    saved: SavedStateHandle,
    private val source: EditorialEventsSource = EditorialEventsApi.of(repository),
) : ViewModel() {
    private val eventId: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow<Loadable<EventDetail>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<EventDetail>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            try { state.value = Loadable.Ready(repository.call { source.preview(eventId) }) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.value = Loadable.Failed(requestFailureMessage(e)) }
        }
    }
}

/** Le proposte dal pubblico (R-SB-01): elenco con il filtro di stato e la ricerca nel titolo. */
internal class SubmissionsViewModel(
    private val repository: EditorialRepository,
    saved: SavedStateHandle,
    private val source: EditorialEventsSource = EditorialEventsApi.of(repository),
) : ViewModel() {
    val list = PagedListController(
        viewModelScope,
        ListQuery(filters = saved.get<String>("status")?.takeIf { it.isNotBlank() }?.let { mapOf("status" to it) }.orEmpty()),
    ) { query, page -> repository.call { source.submissions(query.params(page)) } }
    val rows: StateFlow<PagedState<EditorialSubmissionRow>> = list.current

    init { list.refresh() }
}

/** Il modulo dell'evento da pubblicare da una proposta, come lo scrive la persona. */
internal data class SubmissionDraft(
    val title: String = "",
    /* La descrizione nei segni dell'editor (`RichText`): si manda in HTML. */
    val description: String = "",
    val venueId: String? = null,
    val categoryId: String? = null,
    val startsAt: LocalDateTime? = null,
    val endsAt: LocalDateTime? = null,
    val priceType: String = "unknown",
    val priceMin: String = "",
) {
    /** I problemi che si vedono prima di chiedere al server (gli stessi del modulo del pannello). */
    fun problems(maxTitle: Int = 255): Set<String> = buildSet {
        if (title.isBlank()) add("title")
        if (title.trim().length > maxTitle) add("title")
        if (venueId.isNullOrBlank()) add("venue_id")
        if (categoryId.isNullOrBlank()) add("category_id")
        if (startsAt == null) add("starts_at_hint")
        if (startsAt != null && endsAt != null && !endsAt.isAfter(startsAt)) add("ends_at")
        if (priceType.isBlank()) add("price_type")
        val price = priceMin.trim().replace(',', '.')
        if (price.isNotEmpty() && (price.toDoubleOrNull() == null || price.toDouble() < 0)) add("price_min")
    }

    /** Il corpo di «Crea e pubblica»: date nell'ora della città con il fuso, descrizione in HTML. */
    fun body(zone: java.time.ZoneId): JsonObject = buildJsonObject {
        put("title", title.trim())
        put("raw_text", RichText.toHtml(description).ifBlank { null })
        venueId?.toLongOrNull()?.let { put("venue_id", it) }
        categoryId?.toLongOrNull()?.let { put("category_id", it) }
        startsAt?.let { put("starts_at_hint", cityIso(it, zone)) }
        put("ends_at", endsAt?.let { cityIso(it, zone) })
        put("price_type", priceType)
        put("price_min", priceMin.trim().replace(',', '.').toDoubleOrNull())
    }

    companion object {
        fun from(detail: EditorialSubmissionDetail): SubmissionDraft {
            val form = detail.form ?: return SubmissionDraft(title = detail.title)
            return SubmissionDraft(
                title = form.title,
                description = RichText.toMarkup(form.rawText),
                venueId = form.venueId?.toString(),
                categoryId = form.categoryId?.toString(),
                startsAt = cityLocalDateTime(form.startsAtHint, detail.zone),
                endsAt = cityLocalDateTime(form.endsAt, detail.zone),
                priceType = form.priceType,
                priceMin = form.priceMin?.let { if (it % 1.0 == 0.0) it.toLong().toString() else it.toString() }.orEmpty(),
            )
        }

        val FIELDS = listOf("title", "raw_text", "venue_id", "category_id", "starts_at_hint", "ends_at", "price_type", "price_min")
    }
}

/** «Esamina» una proposta (R-SB-02…05): dati ricevuti, modulo, «Crea e pubblica», «Rifiuta proposta». */
internal class SubmissionReviewViewModel(
    private val repository: EditorialRepository,
    private val saved: SavedStateHandle,
    private val source: EditorialEventsSource = EditorialEventsApi.of(repository),
) : ViewModel() {
    val submissionId: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow<Loadable<EditorialSubmissionDetail>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<EditorialSubmissionDetail>> = state.asStateFlow()
    private val draftState = MutableStateFlow(SubmissionDraft())
    val draft: StateFlow<SubmissionDraft> = draftState.asStateFlow()
    private val errorsState = MutableStateFlow(FormErrors.None)
    val errors: StateFlow<FormErrors> = errorsState.asStateFlow()
    private val actionState = MutableStateFlow(ActionState())
    val action: StateFlow<ActionState> = actionState.asStateFlow()
    private val publishedState = MutableStateFlow<Long?>(null)
    /** L'evento appena creato: la schermata apre la sua scheda. */
    val published: StateFlow<Long?> = publishedState.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            try {
                val detail = repository.call { source.submission(submissionId) }
                draftState.value = SubmissionDraft.from(detail)
                state.value = Loadable.Ready(detail)
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.value = Loadable.Failed(requestFailureMessage(e))
            }
        }
    }

    fun edit(change: (SubmissionDraft) -> SubmissionDraft) = draftState.update(change)

    fun publish(requiredMessage: String) {
        val detail = (state.value as? Loadable.Ready)?.value ?: return
        if (actionState.value.busy) return
        val draft = draftState.value
        val problems = draft.problems(detail.limits.title)
        if (problems.isNotEmpty()) {
            errorsState.value = FormErrors(fields = problems.associateWith { requiredMessage })
            return
        }
        errorsState.value = FormErrors.None
        actionState.value = ActionState(busy = true)
        viewModelScope.launch {
            try {
                val result = repository.call { source.publishSubmission(submissionId, draft.body(detail.zone)) }
                actionState.value = ActionState(message = result.message)
                publishedState.value = result.data.eventId
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                errorsState.value = FormErrors.from(e, SubmissionDraft.FIELDS)
                actionState.value = ActionState()
            }
        }
    }

    fun reject(notes: String) {
        if (actionState.value.busy) return
        actionState.value = ActionState(busy = true)
        viewModelScope.launch {
            try {
                val result = repository.call { source.rejectSubmission(submissionId, notes) }
                actionState.value = ActionState(message = result.message)
                load()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                actionState.value = ActionState(error = requestFailureMessage(e))
            }
        }
    }

    fun consumePublished() { publishedState.value = null }
    fun consumeMessage() = actionState.update { it.copy(message = null, error = null) }
}
