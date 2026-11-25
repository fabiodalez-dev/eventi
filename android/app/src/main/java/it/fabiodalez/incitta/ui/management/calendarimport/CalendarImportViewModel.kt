package it.fabiodalez.incitta.ui.management.calendarimport

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.management.CalendarDraft
import it.fabiodalez.incitta.data.management.CalendarFile
import it.fabiodalez.incitta.data.management.CalendarFileException
import it.fabiodalez.incitta.data.management.CalendarImportState
import it.fabiodalez.incitta.data.management.CalendarLimits
import it.fabiodalez.incitta.data.management.CalendarPreview
import it.fabiodalez.incitta.data.management.CalendarRunResult
import it.fabiodalez.incitta.data.management.CalendarSource
import it.fabiodalez.incitta.data.management.CalendarSourceType
import it.fabiodalez.incitta.data.management.ManagementCalendarImportSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.collaborators.selectedVenueId
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Le azioni sulla sorgente collegata, dopo l'eventuale conferma. */
internal enum class CalendarAction { Run, Pause, Resume, Disconnect }

internal data class CalendarImportUiState(
    val load: Loadable<CalendarImportState> = Loadable.Loading,
    val type: CalendarSourceType = CalendarSourceType.Ics,
    val url: String = "",
    val keywords: List<String> = emptyList(),
    val keywordInput: String = "",
    /* Il CSV scelto: il nome si mostra, i byte restano nel ViewModel. */
    val fileName: String? = null,
    val fileError: CalendarFileException.Reason? = null,
    /* L'anteprima guardata: finché non c'è (con il suo segno) «Collega» resta spento, come nella pagina. */
    val preview: CalendarPreview? = null,
    val previewBusy: Boolean = false,
    val connectBusy: Boolean = false,
    val actionBusy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: String? = null,
    val error: String? = null,
    /* L'esito di «Leggi adesso», da mostrare come la notifica della pagina. */
    val runResult: CalendarRunResult? = null,
) {
    val state: CalendarImportState? get() = (load as? Loadable.Ready)?.value
    val source: CalendarSource? get() = state?.source
    val limits: CalendarLimits get() = state?.limits ?: CalendarLimits()
    val previewed: Boolean get() = preview?.previewToken != null
    val busy: Boolean get() = previewBusy || connectBusy || actionBusy
    val canAddKeyword: Boolean get() = keywordInput.isNotBlank() && keywords.size < limits.maxKeywords
}

/**
 * «Calendario collegato» (W-IM-01…07): lo stato della sorgente, il modulo
 * (ICS o CSV, indirizzo o file, parole escluse), l'anteprima e le azioni. Le
 * regole le decide il server (`VenueCalendarImport`, la stessa classe della
 * pagina); qui resta la regola visibile della pagina: cambiato il tipo,
 * l'indirizzo o il file, l'anteprima di prima non vale più.
 */
internal class CalendarImportViewModel(
    repository: ManagementRepository,
    private val saved: SavedStateHandle,
    private val io: CoroutineDispatcher = Dispatchers.IO,
) : ViewModel() {
    private val api: ManagementCalendarImportSource? = repository.calendar
    val venueId: Long = selectedVenueId(repository, saved, KEY_VENUE)
    private val state = MutableStateFlow(
        CalendarImportUiState(
            type = CalendarSourceType.from(saved[KEY_TYPE]),
            url = saved[KEY_URL] ?: "",
            keywords = saved.get<ArrayList<String>>(KEY_KEYWORDS)?.toList() ?: emptyList(),
            keywordInput = saved[KEY_KEYWORD_INPUT] ?: "",
        ),
    )
    val uiState: StateFlow<CalendarImportUiState> = state.asStateFlow()
    private var file: CalendarFile? = null

    init { load() }

    /** Rilegge lo stato; il modulo si riempie dal server solo la prima volta, poi resta quello che si sta scrivendo. */
    fun load() {
        val source = api
        if (source == null || venueId <= 0) { state.update { it.copy(load = Loadable.Failed(NO_VENUE)) }; return }
        state.update { it.copy(load = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val loaded = source.state(venueId)
                state.update { it.copy(load = Loadable.Ready(loaded)) }
                if (saved.get<Boolean>(KEY_FILLED) != true) fill(loaded)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(load = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun fill(loaded: CalendarImportState) {
        saved[KEY_FILLED] = true
        val type = CalendarSourceType.from(loaded.form.type)
        val url = loaded.form.url.orEmpty()
        saved[KEY_TYPE] = type.value
        saved[KEY_URL] = url
        saved[KEY_KEYWORDS] = ArrayList(loaded.form.excludeKeywords)
        state.update { it.copy(type = type, url = url, keywords = loaded.form.excludeKeywords) }
    }

    fun setType(value: CalendarSourceType) {
        if (value == state.value.type) return
        saved[KEY_TYPE] = value.value
        state.update { it.copy(type = value).forgetPreview() }
    }

    fun setUrl(value: String) {
        saved[KEY_URL] = value
        state.update { it.copy(url = value).forgetPreview() }
    }

    fun setKeywordInput(value: String) {
        saved[KEY_KEYWORD_INPUT] = value
        state.update { it.copy(keywordInput = value) }
    }

    /** Aggiunge la parola scritta (o le parole separate da virgola), senza doppioni. */
    fun addKeyword() {
        val current = state.value
        val words = current.keywordInput.split(',').map { it.trim() }.filter { it.isNotEmpty() }
        val keywords = (current.keywords + words.filter { word -> current.keywords.none { it.equals(word, ignoreCase = true) } })
            .distinctBy { it.lowercase() }
            .take(current.limits.maxKeywords)
        saved[KEY_KEYWORDS] = ArrayList(keywords)
        saved[KEY_KEYWORD_INPUT] = ""
        state.update { it.copy(keywords = keywords, keywordInput = "") }
    }

    fun removeKeyword(word: String) {
        val keywords = state.value.keywords.filterNot { it == word }
        saved[KEY_KEYWORDS] = ArrayList(keywords)
        state.update { it.copy(keywords = keywords) }
    }

    /** Il file scelto con il selettore di sistema: si legge fuori dal thread principale, entro il tetto del server. */
    fun fileChosen(read: () -> CalendarFile) {
        viewModelScope.launch {
            try {
                val chosen = withContext(io) { read() }
                file = chosen
                state.update { it.copy(fileName = chosen.name, fileError = null).forgetPreview() }
            } catch (e: CancellationException) { throw e }
            catch (e: CalendarFileException) { state.update { it.copy(fileError = e.reason) } }
            catch (_: Exception) { state.update { it.copy(fileError = CalendarFileException.Reason.Unreadable) } }
        }
    }

    fun clearFile() {
        file = null
        state.update { it.copy(fileName = null, fileError = null).forgetPreview() }
    }

    fun clearMessage() = state.update { it.copy(message = null, runResult = null) }

    /** Guarda cosa entrerebbe, senza scrivere niente. */
    fun preview() {
        val source = api ?: return
        val current = state.value
        if (current.busy) return
        val draft = draft(current)
        val chosen = file.takeIf { current.type == CalendarSourceType.Csv }
        state.update { it.copy(previewBusy = true, errors = FormErrors.None, error = null, message = null, runResult = null) }
        viewModelScope.launch {
            try {
                val preview = source.preview(venueId, draft, chosen)
                state.update { it.copy(preview = preview) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(preview = null, errors = FormErrors.from(e, FIELDS)) } }
            finally { state.update { it.copy(previewBusy = false) } }
        }
    }

    /** Collega o aggiorna, con il segno dell'anteprima. Il modulo resta com'è, come nella pagina. */
    fun connect() {
        val source = api ?: return
        val current = state.value
        val token = current.preview?.previewToken
        if (current.busy) return
        if (token == null) { state.update { it.copy(error = null, errors = FormErrors(general = PREVIEW_FIRST)) }; return }
        val draft = draft(current)
        val chosen = file.takeIf { current.type == CalendarSourceType.Csv }
        state.update { it.copy(connectBusy = true, errors = FormErrors.None, error = null, message = null, runResult = null) }
        viewModelScope.launch {
            try {
                val change = source.connect(venueId, draft, chosen, token)
                state.update { it.copy(load = Loadable.Ready(change.data), message = change.message) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, FIELDS)) } }
            finally { state.update { it.copy(connectBusy = false) } }
        }
    }

    /** «Leggi adesso», «Sospendi», «Riattiva», «Scollega». */
    fun act(action: CalendarAction) {
        val source = api ?: return
        if (state.value.busy) return
        state.update { it.copy(actionBusy = true, error = null, message = null, runResult = null) }
        viewModelScope.launch {
            try {
                when (action) {
                    CalendarAction.Run -> {
                        val result = source.run(venueId)
                        state.update { it.copy(load = Loadable.Ready(result.state), runResult = result) }
                    }
                    CalendarAction.Pause, CalendarAction.Resume -> {
                        val change = if (action == CalendarAction.Pause) source.pause(venueId) else source.resume(venueId)
                        state.update { it.copy(load = Loadable.Ready(change.data), message = change.message) }
                    }
                    CalendarAction.Disconnect -> {
                        val change = source.disconnect(venueId)
                        /* Come nella pagina: scollegato, l'anteprima di prima non vale più. */
                        state.update { it.copy(load = Loadable.Ready(change.data), message = change.message).forgetPreview() }
                    }
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(actionBusy = false) } }
        }
    }

    private fun draft(current: CalendarImportUiState) = CalendarDraft(current.type, current.url, current.keywords)

    private fun CalendarImportUiState.forgetPreview() = copy(preview = null)

    companion object {
        const val KEY_VENUE = "venue"
        const val KEY_TYPE = "type"
        const val KEY_URL = "url"
        const val KEY_KEYWORDS = "keywords"
        const val KEY_KEYWORD_INPUT = "keyword_input"
        const val KEY_FILLED = "filled"
        val FIELDS = listOf("type", "url", "file", "exclude_keywords")
        const val NO_VENUE = "Apri un locale dalla gestione per collegare il suo calendario."
        const val PREVIEW_FIRST = "Guarda l'anteprima prima di collegare."
    }
}
