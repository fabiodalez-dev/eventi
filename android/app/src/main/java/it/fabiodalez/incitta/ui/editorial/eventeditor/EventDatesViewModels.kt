package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.editorial.EditorDate
import it.fabiodalez.incitta.data.editorial.EditorDateResult
import it.fabiodalez.incitta.data.editorial.EditorDatesMeta
import it.fabiodalez.incitta.data.editorial.EditorVenueChoice
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EventEditorApi
import it.fabiodalez.incitta.data.editorial.EventEditorSource
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Un gesto chiesto su una data, in attesa di conferma nella schermata. */
internal sealed interface DateGesture {
    val date: EditorDate
    data class Shift(override val date: EditorDate) : DateGesture
    data class Cancel(override val date: EditorDate) : DateGesture
    data class SoldOut(override val date: EditorDate) : DateGesture
    data class Delete(override val date: EditorDate) : DateGesture
}

/** L'elenco delle date: righe, il quadro dell'evento, il gesto in corso e il messaggio del pannello. */
internal data class EventDatesUiState(
    val dates: Loadable<List<EditorDate>> = Loadable.Loading,
    val meta: EditorDatesMeta = EditorDatesMeta(),
    val loadingMore: Boolean = false,
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    val gesture: DateGesture? = null,
) {
    val zone: ZoneId get() = meta.zone
    val canLoadMore: Boolean get() = meta.currentPage < meta.lastPage
}

/**
 * Le date di un evento (R-EV-19…24): elenco in ordine di inizio, cinquanta
 * per pagina, e i gesti della riga con le stesse condizioni del pannello.
 */
internal class EventDatesViewModel(
    private val repository: EditorialRepository,
    saved: SavedStateHandle,
    private val source: EventEditorSource = EventEditorApi.of(repository),
) : ViewModel() {
    val eventId: Long = saved.get<Long>(KEY_EVENT) ?: 0L
    private val state = MutableStateFlow(EventDatesUiState())
    val uiState: StateFlow<EventDatesUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(dates = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val page = repository.call { source.dates(eventId, 1) }
                state.update { it.copy(dates = if (page.data.isEmpty()) Loadable.Empty else Loadable.Ready(page.data), meta = page.meta) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(dates = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun loadMore() {
        val current = state.value
        val rows = (current.dates as? Loadable.Ready)?.value ?: return
        if (current.loadingMore || !current.canLoadMore) return
        state.update { it.copy(loadingMore = true) }
        viewModelScope.launch {
            try {
                val page = repository.call { source.dates(eventId, current.meta.currentPage + 1) }
                state.update { it.copy(loadingMore = false, dates = Loadable.Ready(rows + page.data), meta = page.meta) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(loadingMore = false, error = requestFailureMessage(e)) }
            }
        }
    }

    fun ask(gesture: DateGesture) {
        state.update { it.copy(gesture = gesture, error = null, message = null) }
    }

    fun dismiss() = state.update { it.copy(gesture = null) }
    fun consumeMessage() = state.update { it.copy(message = null, error = null) }

    fun shift(date: EditorDate, minutes: Int, scope: String) = gesture { source.shiftDate(eventId, date.id, minutes, scope) }
    fun cancel(date: EditorDate, note: String?, scope: String) = gesture { source.cancelDate(eventId, date.id, note, scope) }
    fun toggleSoldOut(date: EditorDate) = gesture { source.toggleSoldOut(eventId, date.id) }

    fun delete(date: EditorDate) {
        if (state.value.busy) return
        state.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                repository.call { source.deleteDate(eventId, date.id) }
                state.update { current ->
                    val rows = ((current.dates as? Loadable.Ready)?.value ?: emptyList()).filterNot { it.id == date.id }
                    current.copy(busy = false, gesture = null, dates = if (rows.isEmpty()) Loadable.Empty else Loadable.Ready(rows), meta = current.meta.copy(total = (current.meta.total - 1).coerceAtLeast(0)))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, gesture = null, error = requestFailureMessage(e)) }
            }
        }
    }

    /** Un gesto di serie può cambiare più righe: dopo, l'elenco si rilegge. */
    private fun gesture(block: suspend () -> EditorDateResult) {
        if (state.value.busy) return
        state.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                val result = repository.call { block() }
                state.update { it.copy(busy = false, gesture = null, message = result.message) }
                reloadQuietly()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, gesture = null, error = requestFailureMessage(e)) }
            }
        }
    }

    private suspend fun reloadQuietly() {
        try {
            val page = repository.call { source.dates(eventId, 1) }
            state.update { it.copy(dates = if (page.data.isEmpty()) Loadable.Empty else Loadable.Ready(page.data), meta = page.meta) }
        } catch (e: CancellationException) { throw e } catch (_: Exception) { }
    }

    companion object {
        const val KEY_EVENT = "eventId"
    }
}

/** Lo stato del modulo di una data. */
internal data class DateEditorUiState(
    val loading: Boolean = true,
    val loadError: String? = null,
    val meta: EditorDatesMeta = EditorDatesMeta(),
    val date: EditorDate? = null,
    val draft: EditorialDateDraft = EditorialDateDraft(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val venueResults: List<EditorVenueChoice> = emptyList(),
    val saved: Boolean = false,
) {
    val creating: Boolean get() = date == null
    val zone: ZoneId get() = meta.zone
}

/** «Aggiungi data» e «Modifica» di una data (`dateId` 0 per una data nuova). */
internal class DateEditorViewModel(
    private val repository: EditorialRepository,
    saved: SavedStateHandle,
    private val source: EventEditorSource = EventEditorApi.of(repository),
) : ViewModel() {
    val eventId: Long = saved.get<Long>(EventDatesViewModel.KEY_EVENT) ?: 0L
    val dateId: Long = saved.get<Long>(KEY_DATE) ?: 0L
    private val state = MutableStateFlow(DateEditorUiState())
    val uiState: StateFlow<DateEditorUiState> = state.asStateFlow()
    private var venueSearch: Job? = null

    init { load() }

    fun load() {
        state.update { it.copy(loading = true, loadError = null) }
        viewModelScope.launch {
            try {
                if (dateId > 0) {
                    val envelope = repository.call { source.date(eventId, dateId) }
                    state.update { it.copy(loading = false, meta = envelope.meta, date = envelope.data, draft = EditorialDateDraft.from(envelope.data)) }
                } else {
                    val page = repository.call { source.dates(eventId, 1) }
                    state.update { it.copy(loading = false, meta = page.meta) }
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(loading = false, loadError = requestFailureMessage(e)) }
            }
        }
    }

    fun edit(change: (EditorialDateDraft) -> EditorialDateDraft) = state.update { it.copy(draft = change(it.draft)) }

    fun searchVenues(query: String) {
        venueSearch?.cancel()
        venueSearch = viewModelScope.launch {
            delay(300)
            try {
                val found = repository.call { source.venues(query.trim(), null, approved = true) }
                state.update { it.copy(venueResults = found) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) { }
        }
    }

    fun save() {
        val current = state.value
        if (current.busy) return
        val body = current.draft.toJson(current.zone)
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val result = repository.call { if (current.creating) source.createDate(eventId, body) else source.updateDate(eventId, dateId, body) }
                state.update { it.copy(busy = false, date = result.data, draft = EditorialDateDraft.from(result.data), saved = true) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, errors = FormErrors.from(e, EditorialDateDraft.FIELD_KEYS)) }
            }
        }
    }

    fun consumeSaved() = state.update { it.copy(saved = false) }

    companion object {
        const val KEY_DATE = "dateId"
    }
}
