package it.fabiodalez.incitta.ui.management.events

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.management.EventSaveResponse
import it.fabiodalez.incitta.data.management.EventsTab
import it.fabiodalez.incitta.data.management.ManagedEventCard
import it.fabiodalez.incitta.data.management.ManagedEventDetail
import it.fabiodalez.incitta.data.management.ManagementEventsApi
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.cityIso
import java.time.LocalDateTime
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Gli stati delle schermate degli eventi. Il soggetto (locale o
 * organizzazione) è quello scelto nella pagina della gestione: la chiave
 * finisce nello stato salvato, così dopo la chiusura dell'app da parte del
 * sistema si riapre lo stesso.
 */

internal const val KEY_SUBJECT = "events_subject"

/** Il soggetto della chiave salvata, o quello scelto, o il primo; carica l'elenco se serve. */
internal suspend fun ManagementRepository.eventsSubject(saved: SavedStateHandle): ManagementSubject? {
    val list = subjects.value ?: loadSubjects()
    val subject = list.find(saved[KEY_SUBJECT]) ?: list.find(selectedSubjectKey) ?: list.all().firstOrNull()
    subject?.let { saved[KEY_SUBJECT] = it.key }
    return subject
}

internal fun ManagementRepository.eventsApi(): ManagementEventsApi = events ?: error("Eventi non disponibili")

/** Il messaggio di un salvataggio, come le notifiche del pannello. */
internal fun saveMessage(response: EventSaveResponse): UiText = when {
    response.meta.sentToReview -> UiText.Res(R.string.management_events_sent_to_review)
    response.meta.outcome == "published" -> UiText.Res(R.string.management_events_published)
    response.meta.outcome == "pending" -> UiText.Res(R.string.management_events_pending)
    response.meta.outcome == "scheduled" -> UiText.Res(R.string.management_events_scheduled_saved)
    response.meta.outcome == "draft" -> UiText.Res(R.string.management_events_draft_saved)
    else -> UiText.Res(R.string.management_events_saved)
}

// ---------------------------------------------------------------- elenco

internal data class EventsListUiState(
    val subject: ManagementSubject? = null,
    val tab: EventsTab = EventsTab.All,
    val status: String? = null,
    val query: String = "",
    val events: List<ManagedEventCard> = emptyList(),
    val nextPage: Int? = null,
    val total: Int = 0,
    val busy: Boolean = false,
    val loaded: Boolean = false,
    val error: String? = null,
    /* L'esito di «Tutto esaurito» sulla prossima data (P2). */
    val message: UiText? = null,
)

internal class EventsListViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val state = MutableStateFlow(EventsListUiState(
        /* La scheda scelta prima, altrimenti quella chiesta dalla rotta (il cartellone del riepilogo), altrimenti «Tutti». */
        tab = EventsTab.entries.firstOrNull { it.value == (saved.get<String>(KEY_TAB) ?: saved.get<String>(KEY_ROUTE_TAB)) } ?: EventsTab.All,
        status = saved[KEY_STATUS],
        query = saved[KEY_QUERY] ?: "",
    ))
    val uiState: StateFlow<EventsListUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load(1, clear = true) }

    fun showTab(tab: EventsTab) {
        if (state.value.tab == tab) return
        saved[KEY_TAB] = tab.value
        state.update { it.copy(tab = tab) }
        load(1, clear = true)
    }

    fun filterStatus(status: String?) {
        saved[KEY_STATUS] = status
        state.update { it.copy(status = status) }
        load(1, clear = true)
    }

    fun typeQuery(query: String) {
        saved[KEY_QUERY] = query
        state.update { it.copy(query = query) }
    }

    /**
     * «Tutto esaurito» / «Ci sono ancora posti» sulla prossima data, dalla
     * card (W-EV-19): il valore è esplicito, e la card prende lo stato nuovo.
     */
    fun setNextSoldOut(event: ManagedEventCard, soldOut: Boolean) {
        val subject = state.value.subject ?: return
        val api = repository.dates ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val response = api.soldOutNext(subject, event.id, soldOut)
                val date = response.data
                state.update { current ->
                    current.copy(
                        events = current.events.map { card ->
                            if (card.id == event.id) card.copy(nextDate = card.nextDate?.copy(status = date.status)) else card
                        },
                        message = if (response.meta.changed == 0) UiText.Res(R.string.management_dates_nothing_changed)
                            else UiText.Plural(R.plurals.management_dates_updated, response.meta.changed, listOf(response.meta.changed)),
                    )
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = problemText(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    fun search() = load(1, clear = true)
    fun refresh() = load(1, clear = false)
    fun more() { state.value.nextPage?.let { if (!state.value.busy) load(it, clear = false) } }

    private fun load(page: Int, clear: Boolean) {
        loading?.cancel()
        state.update { if (clear) it.copy(busy = true, error = null, events = emptyList(), nextPage = null, loaded = false) else it.copy(busy = true, error = null) }
        loading = viewModelScope.launch {
            try {
                val subject = state.value.subject ?: repository.eventsSubject(saved) ?: throw IllegalStateException()
                state.update { it.copy(subject = subject) }
                val current = state.value
                val response = repository.eventsApi().list(subject, current.tab, current.status, current.query, page)
                state.update {
                    it.copy(
                        events = if (page == 1) response.data else (it.events + response.data).distinctBy(ManagedEventCard::id),
                        nextPage = response.meta.nextPage, total = response.meta.total, loaded = true,
                    )
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_TAB = "events_tab"
        /* L'argomento `tab` di `EventsListRoute`. */
        const val KEY_ROUTE_TAB = "tab"
        const val KEY_STATUS = "events_status"
        const val KEY_QUERY = "events_query"
    }
}

// ---------------------------------------------------------------- scheda

internal data class EventOverviewUiState(
    val subject: ManagementSubject? = null,
    val event: Loadable<ManagedEventDetail> = Loadable.Loading,
    val busy: Boolean = false,
    val message: UiText? = null,
    val error: UiText? = null,
    /* Dopo «Elimina» si torna all'elenco; dopo «Duplica» si apre la modifica della copia. */
    val deleted: Boolean = false,
    val duplicatedId: Long? = null,
)

internal class EventOverviewViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val eventId: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow(EventOverviewUiState())
    val uiState: StateFlow<EventOverviewUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(event = if (it.event is Loadable.Ready) it.event else Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val subject = state.value.subject ?: repository.eventsSubject(saved) ?: throw IllegalStateException()
                val detail = repository.eventsApi().detail(subject, eventId)
                state.update { it.copy(subject = subject, event = Loadable.Ready(detail)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(event = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun publish() = act { subject, api -> api.publish(subject, eventId).also(::show).let { saveMessage(it) } }
    fun submit() = act { subject, api -> api.submit(subject, eventId).also(::show); UiText.Res(R.string.management_events_submitted) }
    fun cancelSchedule() = act { subject, api -> api.cancelSchedule(subject, eventId).also(::show); UiText.Res(R.string.management_events_schedule_cancelled) }

    /** `at` è l'ora scelta nella città dell'evento. */
    fun schedule(at: LocalDateTime) = act { subject, api ->
        val response = api.schedule(subject, eventId, cityIso(at, subject.timezone))
        show(response)
        UiText.Res(if (response.data.status.value == "pending") R.string.management_events_schedule_pending else R.string.management_events_schedule_saved)
    }

    fun duplicate() = act { subject, api ->
        val copy = api.duplicate(subject, eventId)
        state.update { it.copy(duplicatedId = copy.id) }
        UiText.Res(R.string.management_events_duplicated)
    }

    fun delete() = act { subject, api ->
        api.delete(subject, eventId)
        state.update { it.copy(deleted = true) }
        UiText.Res(R.string.management_events_deleted)
    }

    fun uploadPoster(image: PreparedImage) = act { subject, _ ->
        val media = repository.media ?: throw IllegalStateException()
        val result = media.uploadPoster(subject, eventId, image)
        refreshQuietly(subject)
        if (result["sent_to_review"]?.toString() == "true") UiText.Res(R.string.management_events_sent_to_review) else UiText.Res(R.string.management_events_poster_saved)
    }

    fun removePoster() = act { subject, _ ->
        (repository.media ?: throw IllegalStateException()).deletePoster(subject, eventId)
        refreshQuietly(subject)
        UiText.Res(R.string.management_events_poster_removed)
    }

    fun posterError(message: UiText) { state.update { it.copy(error = message) } }
    fun consumeMessage() { state.update { it.copy(message = null) } }
    fun consumeNavigation() { state.update { it.copy(duplicatedId = null, deleted = false) } }

    private fun show(response: EventSaveResponse) { state.update { it.copy(event = Loadable.Ready(response.data)) } }

    private suspend fun refreshQuietly(subject: ManagementSubject) {
        val detail = repository.eventsApi().detail(subject, eventId)
        state.update { it.copy(event = Loadable.Ready(detail)) }
    }

    private fun act(block: suspend (ManagementSubject, ManagementEventsApi) -> UiText) {
        if (state.value.busy) return
        val subject = state.value.subject ?: return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try { val message = block(subject, repository.eventsApi()); state.update { it.copy(message = message) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(problemText(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }
}

/** Il testo di un errore: per un 422 il primo messaggio del server (es. «Aggiungi almeno una data…»). */
internal fun problemText(error: Throwable): String {
    val problem = (error as? ApiException)?.problem
    val first = problem?.fields?.values?.firstOrNull()?.firstOrNull()
    return if (error is ApiException && error.status == 422 && !first.isNullOrBlank()) first else requestFailureMessage(error)
}

// ---------------------------------------------------------------- anteprima

internal class EventPreviewViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val eventId: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow<Loadable<EventDetail>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<EventDetail>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            try {
                val subject = repository.eventsSubject(saved) ?: throw IllegalStateException()
                state.value = Loadable.Ready(repository.eventsApi().preview(subject, eventId))
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.value = Loadable.Failed(requestFailureMessage(e)) }
        }
    }
}
