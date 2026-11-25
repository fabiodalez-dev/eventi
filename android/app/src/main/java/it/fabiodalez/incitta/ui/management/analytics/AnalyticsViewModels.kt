package it.fabiodalez.incitta.ui.management.analytics

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.AnalyticsPeriods
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsSummary
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import it.fabiodalez.incitta.data.management.CHANNEL_LABEL_MAX
import it.fabiodalez.incitta.data.management.ChannelsList
import it.fabiodalez.incitta.data.management.DateAnalyticsRow
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventChoice
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.EventShareLinks
import it.fabiodalez.incitta.data.management.ManagementAnalyticsSource
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.OccurrenceAnalyticsReport
import it.fabiodalez.incitta.data.management.ReportQuery
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Statistiche (P5), come le pagine dei pannelli. Il soggetto arriva dalla
 * rotta per chiave (`venue:12`); periodo, date, evento e canale stanno nello
 * stato salvato della voce, così dopo la chiusura dell'app la schermata
 * rinasce con gli stessi filtri. I rapporti si leggono solo quando la
 * persona cambia qualcosa: niente aggiornamento automatico, è un hosting
 * condiviso.
 */

/** Il soggetto della schermata; `null` se la persona non lo gestisce (più). */
internal suspend fun resolveAnalyticsSubject(repository: ManagementRepository, key: String?): ManagementSubject? {
    val pick: (ManagementSubjects) -> ManagementSubject? = { list ->
        if (key.isNullOrBlank()) list.find(repository.selectedSubjectKey) ?: list.all().firstOrNull() else list.find(key)
    }
    /* L'elenco in memoria basta quasi sempre; un soggetto appena aggiunto si cerca rileggendolo una volta. */
    val found = repository.subjects.value?.let(pick) ?: pick(repository.loadSubjects())
    found?.let { repository.selectedSubjectKey = it.key }
    return found
}

internal fun ManagementRepository.analyticsApi(): ManagementAnalyticsSource =
    analytics ?: throw IllegalStateException("Statistiche non disponibili in questa sessione")

/** Un file scaricato o il suo errore: lo stesso giro della biglietteria (cache dell'app, «Apri» e «Condividi»). */
internal data class ExportState(val busy: String? = null, val file: DownloadedFile? = null, val error: String? = null)

/** Scarica un file di statistiche nella cartella della gestione, dopo aver tolto quelli vecchi di un'ora. */
private suspend fun downloadExport(
    source: ManagementAnalyticsSource, subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery,
    dataset: String, format: String, cacheDir: File,
): DownloadedFile {
    runCatching { ManagementFiles.purge(cacheDir) }
    return source.export(subject, target, query, dataset, format, ManagementFiles.directory(cacheDir))
}

// ------------------------------------------------------------------- menu

internal data class AnalyticsHubUiState(val subject: Loadable<ManagementSubject> = Loadable.Loading)

/** La prima schermata dell'area: le tre pagine del gruppo «Statistiche» del pannello, più i canali per il referente. */
internal class AnalyticsHubViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(AnalyticsHubUiState())
    val uiState: StateFlow<AnalyticsHubUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(subject = Loadable.Loading) }
        viewModelScope.launch {
            val result = try {
                resolveAnalyticsSubject(repository, key)?.let { Loadable.Ready(it) } ?: Loadable.Empty
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(subject = result) }
        }
    }
}

// ---------------------------------------------------------------- «Statistiche»

internal data class StatisticsUiState(
    val subject: ManagementSubject? = null,
    val period: String = "month",
    val page: Int = 1,
    val sharePage: Int = 1,
    val summary: Loadable<AnalyticsSummary> = Loadable.Loading,
    val refreshing: Boolean = false,
)

/** La pagina «Statistiche» (W-ST-01): periodo, cinque numeri, andamento, azioni, eventi, sponsorizzate, link brevi. */
internal class StatisticsViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(StatisticsUiState(
        period = saved.get<String>(KEY_PERIOD)?.takeIf { it in SUMMARY_PERIODS } ?: "month",
        page = saved.get<Int>(KEY_PAGE) ?: 1,
        sharePage = saved.get<Int>(KEY_SHARE_PAGE) ?: 1,
    ))
    val uiState: StateFlow<StatisticsUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load() }

    fun load() {
        loading?.cancel()
        val current = state.value
        state.update { it.copy(refreshing = it.summary is Loadable.Ready, summary = if (it.summary is Loadable.Ready) it.summary else Loadable.Loading) }
        loading = viewModelScope.launch {
            val result: Loadable<AnalyticsSummary> = try {
                val subject = current.subject ?: resolveAnalyticsSubject(repository, key)
                if (subject == null) Loadable.Empty else {
                    state.update { it.copy(subject = subject) }
                    Loadable.Ready(repository.analyticsApi().summary(subject, current.period, current.page, current.sharePage))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(summary = result, refreshing = false) }
        }
    }

    fun setPeriod(period: String) {
        if (period !in SUMMARY_PERIODS || period == state.value.period) return
        saved[KEY_PERIOD] = period
        saved[KEY_PAGE] = 1
        saved[KEY_SHARE_PAGE] = 1
        state.update { it.copy(period = period, page = 1, sharePage = 1) }
        load()
    }

    fun eventsPage(page: Int) {
        if (page < 1 || page == state.value.page) return
        saved[KEY_PAGE] = page
        state.update { it.copy(page = page) }
        load()
    }

    fun sharesPage(page: Int) {
        if (page < 1 || page == state.value.sharePage) return
        saved[KEY_SHARE_PAGE] = page
        state.update { it.copy(sharePage = page) }
        load()
    }

    companion object {
        val SUMMARY_PERIODS = setOf("week", "month", "quarter")
    }
}

// ---------------------------------------- «Statistiche in dettaglio» e soggetto

internal data class ReportUiState(
    val subject: ManagementSubject? = null,
    val target: AnalyticsTarget? = null,
    val query: ReportQuery = ReportQuery(),
    /* Il titolo dell'evento del filtro, per mostrarlo prima che arrivi il rapporto. */
    val eventTitle: String? = null,
    val report: Loadable<AnalyticsReport> = Loadable.Loading,
    val refreshing: Boolean = false,
    val export: ExportState = ExportState(),
    /* Solo nella pagina di un evento: le date, i link brevi, il foglio di una data. */
    val dates: Loadable<List<DateAnalyticsRow>>? = null,
    val shareLinks: Loadable<EventShareLinks>? = null,
    val occurrence: Loadable<OccurrenceAnalyticsReport>? = null,
)

/**
 * «Statistiche in dettaglio» (W-ST-02…04, `target` nullo) e la pagina di un
 * evento, un locale o un organizzatore (W-ST-05). Nella pagina di un evento
 * anche le sue date (W-ST-07, W-DT-08) e i suoi link brevi (W-PB-05).
 */
internal class ReportViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val target: AnalyticsTarget? = saved.get<String>(KEY_TYPE)?.takeIf { it in SUBJECT_TYPES }?.let { type ->
        saved.get<Long>(KEY_ID)?.takeIf { it > 0 }?.let { AnalyticsTarget(type, it) }
    }
    private val state = MutableStateFlow(ReportUiState(target = target, query = savedQuery(saved, includeEvent = target == null), eventTitle = saved[KEY_EVENT_TITLE]))
    val uiState: StateFlow<ReportUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load() }

    fun load() {
        loading?.cancel()
        val current = state.value
        state.update { it.copy(refreshing = it.report is Loadable.Ready, report = if (it.report is Loadable.Ready) it.report else Loadable.Loading) }
        loading = viewModelScope.launch {
            val result: Loadable<AnalyticsReport> = try {
                val subject = current.subject ?: resolveAnalyticsSubject(repository, key)
                if (subject == null) Loadable.Empty else {
                    state.update { it.copy(subject = subject) }
                    Loadable.Ready(repository.analyticsApi().report(subject, target, current.query))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(report = result, refreshing = false) }
            if (target?.type == "event" && result is Loadable.Ready && state.value.dates == null) loadEventExtras()
        }
    }

    /** Le date e i link brevi dell'evento: una volta sola, non cambiano con il periodo. */
    fun loadEventExtras() {
        val subject = state.value.subject ?: return
        val event = target?.takeIf { it.type == "event" } ?: return
        state.update { it.copy(dates = Loadable.Loading, shareLinks = Loadable.Loading) }
        viewModelScope.launch {
            val dates: Loadable<List<DateAnalyticsRow>> = try {
                repository.analyticsApi().dates(subject, event.id).let { if (it.isEmpty()) Loadable.Empty else Loadable.Ready(it) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(dates = dates) }
            val links: Loadable<EventShareLinks> = try {
                Loadable.Ready(repository.analyticsApi().shareLinks(subject, event.id))
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(shareLinks = links) }
        }
    }

    /** Un periodo con nome: le date scelte a mano si dimenticano, come nel pannello. */
    fun setPeriod(period: String) {
        if (period !in DETAIL_PERIODS) return
        apply(state.value.query.copy(period = period, from = null, until = null))
    }

    /** I filtri scelti nel foglio «Filtra i dati». */
    fun apply(query: ReportQuery, eventTitle: String? = state.value.eventTitle) {
        val clean = if (target != null) query.copy(event = null) else query
        storeQuery(saved, clean)
        saved[KEY_EVENT_TITLE] = eventTitle
        state.update { it.copy(query = clean, eventTitle = if (clean.event == null) null else eventTitle) }
        load()
    }

    /** «Azzera filtri» (o «Reimposta periodo e canale» nella pagina di un soggetto): ultimi 30 giorni, nessun filtro. */
    fun reset() = apply(ReportQuery(), null)

    /** Gli eventi del foglio «Scegli un evento»: quelli di cui il soggetto vede le statistiche. */
    suspend fun searchEvents(search: String): EventChoiceGroups {
        val subject = state.value.subject ?: resolveAnalyticsSubject(repository, key) ?: return EventChoiceGroups()
        return repository.analyticsApi().events(subject, search)
    }

    fun export(dataset: String, format: String, cacheDir: File) {
        val subject = state.value.subject ?: return
        if (state.value.export.busy != null) return
        state.update { it.copy(export = ExportState(busy = format)) }
        viewModelScope.launch {
            val next = try {
                ExportState(file = downloadExport(repository.analyticsApi(), subject, target, state.value.query, dataset, format, cacheDir))
            } catch (e: CancellationException) { throw e } catch (e: Exception) { ExportState(error = requestFailureMessage(e)) }
            state.update { it.copy(export = next) }
        }
    }

    private var lastOccurrence: Long? = null

    /** «Riprova» nel foglio di una data. */
    fun retryOccurrence() { lastOccurrence?.let(::openOccurrence) }

    /** Il foglio «Statistiche» di una data. */
    fun openOccurrence(occurrenceId: Long) {
        lastOccurrence = occurrenceId
        val subject = state.value.subject ?: return
        val event = target?.takeIf { it.type == "event" } ?: return
        state.update { it.copy(occurrence = Loadable.Loading) }
        viewModelScope.launch {
            val report: Loadable<OccurrenceAnalyticsReport> = try {
                Loadable.Ready(repository.analyticsApi().occurrence(subject, event.id, occurrenceId))
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            // Il foglio può essere già stato chiuso: in quel caso resta chiuso.
            state.update { if (it.occurrence == null) it else it.copy(occurrence = report) }
        }
    }

    fun closeOccurrence() = state.update { it.copy(occurrence = null) }
}

/** I filtri salvati nella voce di navigazione (gli stessi nomi dei parametri delle rotte). */
private fun savedQuery(saved: SavedStateHandle, includeEvent: Boolean): ReportQuery {
    val period = saved.get<String>(KEY_PERIOD)?.takeIf { it in DETAIL_PERIODS || it == AnalyticsPeriods.CUSTOM } ?: AnalyticsPeriods.DEFAULT
    return ReportQuery(
        period = period,
        from = saved.get<String>(KEY_FROM)?.takeIf { period == AnalyticsPeriods.CUSTOM && it.isNotBlank() },
        until = saved.get<String>(KEY_UNTIL)?.takeIf { period == AnalyticsPeriods.CUSTOM && it.isNotBlank() },
        event = if (includeEvent) saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 } else null,
        channel = saved.get<String>(KEY_CHANNEL)?.takeIf { it.isNotBlank() },
    )
}

private fun storeQuery(saved: SavedStateHandle, query: ReportQuery) {
    saved[KEY_PERIOD] = query.period
    saved[KEY_FROM] = query.from
    saved[KEY_UNTIL] = query.until
    saved[KEY_EVENT] = query.event ?: 0L
    saved[KEY_CHANNEL] = query.channel
}

// ------------------------------------------------------------------ tabelle

internal data class AnalyticsTableUiState(
    val subject: ManagementSubject? = null,
    val page: Int = 1,
    val table: Loadable<AnalyticsTable> = Loadable.Loading,
    val export: ExportState = ExportState(),
)

/** Una tabella di «Tutti i dati» (W-ST-04): venti righe per pagina e i due file da scaricare. */
internal class AnalyticsTableViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val target: AnalyticsTarget? = saved.get<String>(KEY_TYPE)?.takeIf { it in SUBJECT_TYPES }?.let { type ->
        saved.get<Long>(KEY_ID)?.takeIf { it > 0 }?.let { AnalyticsTarget(type, it) }
    }
    private val query = savedQuery(saved, includeEvent = target == null)
    val dataset: String = saved.get<String>(KEY_DATASET)?.takeIf { it in DATASETS } ?: "events"
    private val state = MutableStateFlow(AnalyticsTableUiState(page = saved.get<Int>(KEY_PAGE) ?: 1))
    val uiState: StateFlow<AnalyticsTableUiState> = state.asStateFlow()
    private var loading: Job? = null

    /** Il soggetto e i filtri della tabella, per aprire la pagina di un evento con lo stesso periodo e canale. */
    val reportQuery: ReportQuery get() = query

    init { load() }

    fun load() {
        loading?.cancel()
        val page = state.value.page
        state.update { it.copy(table = Loadable.Loading) }
        loading = viewModelScope.launch {
            val result: Loadable<AnalyticsTable> = try {
                val subject = state.value.subject ?: resolveAnalyticsSubject(repository, key)
                if (subject == null) Loadable.Empty else {
                    state.update { it.copy(subject = subject) }
                    Loadable.Ready(repository.analyticsApi().table(subject, target, query, dataset, page))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(table = result) }
        }
    }

    fun page(page: Int) {
        if (page < 1 || page == state.value.page) return
        saved[KEY_PAGE] = page
        state.update { it.copy(page = page) }
        load()
    }

    fun export(format: String, cacheDir: File) {
        val subject = state.value.subject ?: return
        if (state.value.export.busy != null) return
        state.update { it.copy(export = ExportState(busy = format)) }
        viewModelScope.launch {
            val next = try {
                ExportState(file = downloadExport(repository.analyticsApi(), subject, target, query, dataset, format, cacheDir))
            } catch (e: CancellationException) { throw e } catch (e: Exception) { ExportState(error = requestFailureMessage(e)) }
            state.update { it.copy(export = next) }
        }
    }
}

// ------------------------------------------------------------ canali propri

internal data class ChannelsUiState(
    val subject: ManagementSubject? = null,
    val channels: Loadable<ChannelsList> = Loadable.Loading,
    val label: String = "",
    val event: EventChoice? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
    val created: String? = null,
)

/** I canali propri del locale e i loro QR (W-ST-06): solo il referente, come la sezione del pannello. */
internal class ChannelsViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(ChannelsUiState(label = saved[KEY_LABEL] ?: ""))
    val uiState: StateFlow<ChannelsUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(channels = Loadable.Loading) }
        viewModelScope.launch {
            val result: Loadable<ChannelsList> = try {
                val subject = state.value.subject ?: resolveAnalyticsSubject(repository, key)
                if (subject == null || !subject.permissions.analyticsChannels) Loadable.Empty else {
                    state.update { it.copy(subject = subject) }
                    Loadable.Ready(repository.analyticsApi().channels(subject))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(channels = result) }
        }
    }

    fun setLabel(value: String) {
        val label = value.take(CHANNEL_LABEL_MAX)
        saved[KEY_LABEL] = label
        state.update { it.copy(label = label, errors = it.errors.copy(fields = it.errors.fields - "label")) }
    }

    fun setEvent(event: EventChoice?) = state.update { it.copy(event = event, errors = it.errors.copy(fields = it.errors.fields - "event")) }

    suspend fun searchEvents(search: String): EventChoiceGroups {
        val subject = state.value.subject ?: return EventChoiceGroups()
        return repository.analyticsApi().events(subject, search)
    }

    fun create() {
        val subject = state.value.subject ?: return
        if (state.value.busy) return
        val label = state.value.label.trim()
        val eventId = state.value.event?.id
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.analyticsApi().addChannel(subject, label, eventId)
                saved[KEY_LABEL] = ""
                state.update { it.copy(channels = Loadable.Ready(result), label = "", event = null, created = result.created,
                    message = result.message?.let(UiText::Raw) ?: UiText.Res(R.string.analytics_channel_create)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(errors = FormErrors.from(e, CHANNEL_FIELDS)) }
            } finally {
                state.update { it.copy(busy = false) }
            }
        }
    }

    fun consumeMessage() = state.update { it.copy(message = null) }

    companion object {
        val CHANNEL_FIELDS = listOf("label", "event")
    }
}

private const val KEY_SUBJECT = "subject"
private const val KEY_TYPE = "type"
private const val KEY_ID = "id"
private const val KEY_PERIOD = "period"
private const val KEY_FROM = "from"
private const val KEY_UNTIL = "until"
private const val KEY_EVENT = "event"
private const val KEY_EVENT_TITLE = "eventTitle"
private const val KEY_CHANNEL = "channel"
private const val KEY_DATASET = "dataset"
private const val KEY_PAGE = "page"
private const val KEY_SHARE_PAGE = "sharePage"
private const val KEY_LABEL = "channelLabel"

internal val SUBJECT_TYPES = setOf("event", "venue", "organizer")
internal val DETAIL_PERIODS = setOf("week", "month", "quarter", "year", "all")
internal val DATASETS = listOf("events", "venues", "organizers", "channels", "daily", "links", "occurrences")
