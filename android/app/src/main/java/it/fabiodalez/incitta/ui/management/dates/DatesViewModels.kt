package it.fabiodalez.incitta.ui.management.dates

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.DateSaveResponse
import it.fabiodalez.incitta.data.management.EventDateChecksResult
import it.fabiodalez.incitta.data.management.DatesPageMeta
import it.fabiodalez.incitta.data.management.DatesPeriod
import it.fabiodalez.incitta.data.management.ManagedEventOccurrence
import it.fabiodalez.incitta.data.management.ManagementDatesApi
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.VenueLookup
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.social.socialApi
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.events.eventsApi
import it.fabiodalez.incitta.ui.management.events.eventsSubject
import it.fabiodalez.incitta.ui.management.events.problemText
import java.io.File
import java.time.LocalDate
import java.time.ZoneId
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * Gli stati delle schermate delle date. L'id dell'evento (e della data) sta
 * nello stato salvato della voce di navigazione; il soggetto è quello della
 * scheda dell'evento da cui si arriva (`eventsSubject`).
 */

internal fun ManagementRepository.datesApi(): ManagementDatesApi = dates ?: error("Date non disponibili")

/** Il fuso della città dell'evento, o quello del soggetto finché l'evento non è arrivato. */
internal fun datesZone(meta: DatesPageMeta?, subject: ManagementSubject?): ZoneId =
    meta?.event?.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: subject?.timezone ?: ZoneId.of("Europe/Rome")

/** Il messaggio di un salvataggio: l'organizzazione può aver rimandato l'evento in revisione. */
internal fun dateSaveMessage(response: DateSaveResponse, created: Boolean): UiText = when {
    response.meta.sentToReview -> UiText.Res(R.string.management_dates_sent_to_review)
    created -> UiText.Res(R.string.management_dates_created)
    else -> UiText.Res(R.string.management_dates_saved)
}

/** Il messaggio di un cambio di stato, come le notifiche del pannello («N date aggiornate»). */
internal fun changedMessage(changed: Int): UiText =
    if (changed == 0) UiText.Res(R.string.management_dates_nothing_changed) else UiText.Plural(R.plurals.management_dates_updated, changed, listOf(changed))

// ---------------------------------------------------------------- elenco

internal data class EventDatesUiState(
    val subject: ManagementSubject? = null,
    val period: DatesPeriod = DatesPeriod.All,
    val dates: Loadable<List<ManagedEventOccurrence>> = Loadable.Loading,
    val meta: DatesPageMeta? = null,
    val nextPage: Int? = null,
    val busy: Boolean = false,
    val message: UiText? = null,
    val error: UiText? = null,
    /* La locandina appena scaricata: la schermata la apre e poi la consuma. */
    val poster: DownloadedFile? = null,
    /* La grafica social di una data appena scaricata (W-DT-12, P10), con il titolo dell'evento: la schermata la mostra. */
    val socialPreview: DownloadedFile? = null,
)

internal class EventDatesViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    val eventId: Long = saved.get<Long>("eventId") ?: 0L
    private val state = MutableStateFlow(EventDatesUiState(period = DatesPeriod.entries.firstOrNull { it.value == saved.get<String>(KEY_PERIOD) } ?: DatesPeriod.All))
    val uiState: StateFlow<EventDatesUiState> = state.asStateFlow()
    private var loading: Job? = null
    private var shown = false

    init { load(1) }

    /** La schermata torna visibile (dopo la modifica di una data): si rilegge, tranne la prima volta. */
    fun onShown() {
        // Il messaggio lasciato dal modulo della data («Data salvata», «L'evento torna in revisione»).
        saved.remove<Int>(KEY_MESSAGE)?.let { id -> state.update { it.copy(message = UiText.Res(id), error = null) } }
        if (shown) load(1, quiet = true) else shown = true
    }

    fun showPeriod(period: DatesPeriod) {
        if (period == state.value.period) return
        saved[KEY_PERIOD] = period.value
        state.update { it.copy(period = period, dates = Loadable.Loading, nextPage = null) }
        load(1)
    }

    fun retry() = load(1)
    fun more() { state.value.nextPage?.let { if (!state.value.busy) load(it) } }

    private fun load(page: Int, quiet: Boolean = false) {
        loading?.cancel()
        if (page == 1 && !quiet) state.update { it.copy(dates = if (it.dates is Loadable.Ready) it.dates else Loadable.Loading) }
        loading = viewModelScope.launch {
            try {
                val subject = state.value.subject ?: repository.eventsSubject(saved) ?: throw IllegalStateException()
                state.update { it.copy(subject = subject) }
                val response = repository.datesApi().list(subject, eventId, state.value.period, page)
                state.update {
                    val before = (it.dates as? Loadable.Ready)?.value.orEmpty()
                    val all = if (page == 1) response.data else (before + response.data).distinctBy(ManagedEventOccurrence::id)
                    it.copy(dates = if (all.isEmpty()) Loadable.Empty else Loadable.Ready(all), meta = response.meta, nextPage = response.meta.nextPage)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                if (page == 1 && state.value.dates !is Loadable.Ready) state.update { it.copy(dates = Loadable.Failed(requestFailureMessage(e))) }
                else state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) }
            }
        }
    }

    /** «Annulla»: `series` per «questa e le successive» (solo se la data è di una serie). */
    fun cancel(date: ManagedEventOccurrence, series: Boolean) = act {
        changedMessage(repository.datesApi().changeStatus(it, eventId, date.id, "cancelled", series && date.series).meta.changed)
    }

    /** «Tutto esaurito» o «Di nuovo disponibile» sulla singola data. */
    fun toggleSoldOut(date: ManagedEventOccurrence) = act {
        changedMessage(repository.datesApi().changeStatus(it, eventId, date.id, if (date.soldOut) "scheduled" else "sold_out").meta.changed)
    }

    fun delete(date: ManagedEventOccurrence) = act {
        repository.datesApi().delete(it, eventId, date.id)
        UiText.Res(R.string.management_dates_deleted)
    }

    /** «Ripeti»: frequenza, giorni, «fino al» (data della città, compresa). */
    fun repeat(frequency: String, weekdays: Set<String>, until: LocalDate?) = act {
        val created = repository.datesApi().repeat(it, eventId, frequency, weekdays.sortedBy(WEEKDAYS::indexOf), until?.toString()).meta.created
        if (created == 0) UiText.Res(R.string.management_dates_no_new) else UiText.Plural(R.plurals.management_dates_created_many, created, listOf(created))
    }

    /** Scarica la locandina in PDF nella cartella dei file della gestione; la apre la schermata. */
    fun downloadPoster(date: ManagedEventOccurrence, cacheDir: File) = act(reload = false) {
        runCatching { ManagementFiles.purge(cacheDir) }
        val file = repository.datesApi().poster(it, eventId, date.id, ManagementFiles.directory(cacheDir))
        state.update { current -> current.copy(poster = file) }
        null
    }

    fun consumePoster() { state.update { it.copy(poster = null) } }

    /** «Grafica social» (organizzazione): l'anteprima JPEG della grafica della data, da guardare, salvare o condividere. */
    fun downloadSocialPreview(date: ManagedEventOccurrence, cacheDir: File) = act(reload = false) {
        runCatching { ManagementFiles.purge(cacheDir) }
        val file = repository.socialApi().preview(it, eventId, date.id, ManagementFiles.directory(cacheDir))
        state.update { current -> current.copy(socialPreview = file) }
        null
    }

    fun consumeSocialPreview() { state.update { it.copy(socialPreview = null) } }
    fun consumeMessage() { state.update { it.copy(message = null, error = null) } }

    private fun act(reload: Boolean = true, block: suspend (ManagementSubject) -> UiText?) {
        if (state.value.busy) return
        val subject = state.value.subject ?: return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val message = block(subject)
                state.update { it.copy(message = message) }
                if (reload) load(1, quiet = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(problemText(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_PERIOD = "dates_period"
        /* Lo mette il modulo della data nello stato della voce dell'elenco, prima di tornarci. */
        const val KEY_MESSAGE = "dates_message"
        val WEEKDAYS = listOf("mon", "tue", "wed", "thu", "fri", "sat", "sun")
    }
}

// ---------------------------------------------------------------- modulo

internal data class DateEditorUiState(
    val subject: ManagementSubject? = null,
    val meta: DatesPageMeta? = null,
    /* La data come l'ha mandata il server (in modifica), per le etichette e i permessi. */
    val original: ManagedEventOccurrence? = null,
    val form: DateForm = DateForm(),
    val loading: Loadable<Unit> = Loadable.Loading,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    /* I campi che non passano i controlli fatti sul telefono (la schermata scrive il motivo). */
    val local: Set<String> = emptySet(),
    val venues: List<VenueLookup> = emptyList(),
    /* «Stessa sera in zona» e chiusura straordinaria per l'orario scelto, come nel modulo del pannello. */
    val checks: EventDateChecksResult = EventDateChecksResult(),
    /* Dopo il salvataggio si torna all'elenco con questo messaggio. */
    val done: UiText? = null,
)

internal class DateEditorViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val eventId: Long = saved.get<Long>("eventId") ?: 0L
    private val dateId: Long = saved.get<Long>("dateId") ?: 0L
    val creating: Boolean get() = dateId == 0L
    private val state = MutableStateFlow(DateEditorUiState())
    val uiState: StateFlow<DateEditorUiState> = state.asStateFlow()
    private var searching: Job? = null
    private var checksJob: Job? = null

    init { load() }

    fun load() {
        state.update { it.copy(loading = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val subject = state.value.subject ?: repository.eventsSubject(saved) ?: throw IllegalStateException()
                if (creating) {
                    val meta = repository.datesApi().list(subject, eventId, DatesPeriod.Upcoming, 1).meta
                    state.update { it.copy(subject = subject, meta = meta, loading = Loadable.Ready(Unit)) }
                } else {
                    val response = repository.datesApi().get(subject, eventId, dateId)
                    val zone = datesZone(response.meta, subject)
                    state.update { it.copy(subject = subject, meta = response.meta, original = response.data, form = DateForm.from(response.data, zone), loading = Loadable.Ready(Unit)) }
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(loading = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun consumeDone() { state.update { it.copy(done = null) } }

    fun edit(change: (DateForm) -> DateForm) {
        val before = state.value.form
        state.update { it.copy(form = change(it.form)) }
        val after = state.value.form
        if (before.startsAt != after.startsAt || before.endsAt != after.endsAt || before.venue != after.venue) refreshChecks()
    }

    /** Gli avvisi accanto all'orario, mezzo secondo dopo l'ultima modifica. Non bloccano: senza rete non si mostrano. */
    private fun refreshChecks() {
        checksJob?.cancel()
        val current = state.value
        val subject = current.subject ?: return
        val start = current.form.startsAt
        if (start == null) {
            state.update { it.copy(checks = EventDateChecksResult()) }
            return
        }
        checksJob = viewModelScope.launch {
            delay(500)
            val form = state.value.form
            val zone = datesZone(state.value.meta, subject)
            val body = buildJsonObject {
                put("starts_at", cityIso(start, zone))
                form.endsAt?.let { put("ends_at", cityIso(it, zone)) }
                put("event_id", eventId)
                if (subject.kind == SubjectKind.Organizer) form.venue?.let { put("venue_id", it.id) }
            }
            try { val result = repository.eventsApi().checks(subject, body); state.update { it.copy(checks = result) } }
            catch (e: CancellationException) { throw e }
            catch (_: Exception) { }
        }
    }

    /** La ricerca dei locali approvati per «Locale di questa data» (organizzazione). */
    fun searchVenues(query: String) {
        val subject = state.value.subject ?: return
        searching?.cancel()
        searching = viewModelScope.launch {
            try {
                val found = repository.eventsApi().venues(subject, query)
                state.update { it.copy(venues = found) }
            } catch (e: CancellationException) { throw e }
            catch (_: Exception) { state.update { it.copy(venues = emptyList()) } }
        }
    }

    fun save() {
        val current = state.value
        val subject = current.subject ?: return
        if (current.busy) return
        val local = current.form.localErrors()
        state.update { it.copy(local = local) }
        if (local.isNotEmpty()) return
        val body = current.form.toJson(subject.kind, datesZone(current.meta, subject))
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val api = repository.datesApi()
                val response = if (creating) api.create(subject, eventId, body) else api.update(subject, eventId, dateId, body)
                state.update { it.copy(done = dateSaveMessage(response, creating)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, fieldKeys(subject.kind))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        /** I campi che il modulo mostra, con i nomi del server: gli errori vanno sotto di loro. */
        fun fieldKeys(kind: SubjectKind): List<String> = listOf("starts_at", "ends_at", "practical_details", "cost_breakdown") + when (kind) {
            SubjectKind.Venue -> listOf("capacity", "capacity_left", "highlight", "lineup")
            SubjectKind.Organizer -> listOf("is_all_day", "venue_id", "status", "status_note")
        }
    }
}
