package it.fabiodalez.incitta.ui.editorial.analytics

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.AnalyticsProfileChoice
import it.fabiodalez.incitta.data.editorial.EditorialAnalyticsSource
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialReportQuery
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.SEARCH_MISS_BULK_MAX
import it.fabiodalez.incitta.data.editorial.SearchMissRow
import it.fabiodalez.incitta.data.editorial.SeoAuditRow
import it.fabiodalez.incitta.data.editorial.SeoCodeProblem
import it.fabiodalez.incitta.data.editorial.SeoPage
import it.fabiodalez.incitta.data.editorial.seoCodeProblem
import it.fabiodalez.incitta.data.management.AnalyticsOption
import it.fabiodalez.incitta.data.management.AnalyticsPeriods
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.management.analytics.ExportState
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
 * R10 — Statistiche, SEO, ricerche (R-ST-01…06), come le tre voci del
 * gruppo «Statistiche» del pannello della redazione. Chi vede quale voce lo
 * dicono i `permissions` del banco (statistiche e SEO: `analytics_view`, di
 * admin e super admin; ricerche: `search_misses_manage`, anche del
 * moderatore); il server rifà ogni controllo. Ogni chiamata passa da
 * `repository.call { }`: con un 423 compare la richiesta della password e la
 * chiamata riparte. I rapporti si rileggono solo quando la persona cambia
 * qualcosa: è un hosting condiviso.
 */

// --------------------------------------------------------------------- menu

/** Le tre voci del gruppo «Statistiche» del pannello, nell'ordine del menu, con il permesso che le mostra. */
internal enum class AnalyticsSection(val permission: String, val title: Int, val help: Int) {
    Report("analytics_view", R.string.editorial_analytics_report_title, R.string.editorial_analytics_report_help),
    Seo("analytics_view", R.string.editorial_analytics_seo_title, R.string.editorial_analytics_seo_help),
    SearchMisses("search_misses_manage", R.string.editorial_analytics_misses_title, R.string.editorial_analytics_misses_help),
}

/** Le voci che la persona vede: il moderatore ha solo le ricerche senza risultati, come nel pannello. */
internal fun analyticsSections(can: EditorialPermissions): List<AnalyticsSection> =
    AnalyticsSection.entries.filter { can.allows(it.permission) }

internal class AnalyticsHubViewModel(repository: EditorialRepository) : ViewModel() {
    val desk: StateFlow<EditorialDesk?> = repository.current
}

// ------------------------------------------------------ «Statistiche condivisioni»

/** I nomi dei filtri scelti, per mostrarli prima che arrivi il rapporto (e dopo la rotazione). */
internal data class FilterLabels(val venue: String? = null, val organizer: String? = null, val event: String? = null)

internal data class EditorialReportUiState(
    val target: AnalyticsTarget? = null,
    val query: EditorialReportQuery = EditorialReportQuery(),
    val labels: FilterLabels = FilterLabels(),
    val report: Loadable<AnalyticsReport> = Loadable.Loading,
    val refreshing: Boolean = false,
    val export: ExportState = ExportState(),
)

/**
 * «Statistiche condivisioni» (R-ST-01, `target` nullo) e la pagina di un
 * evento, un locale o un organizzatore (R-ST-02), con le tabelle e i file
 * (R-ST-03) dalla schermata della tabella.
 */
internal class EditorialReportViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialAnalyticsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val target: AnalyticsTarget? = savedTarget(saved)
    private val state = MutableStateFlow(EditorialReportUiState(
        target = target,
        query = savedReportQuery(saved, includeEntities = target == null),
        labels = FilterLabels(saved[KEY_VENUE_LABEL], saved[KEY_ORGANIZER_LABEL], saved[KEY_EVENT_LABEL]),
    ))
    val uiState: StateFlow<EditorialReportUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load() }

    fun load() {
        loading?.cancel()
        val query = state.value.query
        state.update { it.copy(refreshing = it.report is Loadable.Ready, report = if (it.report is Loadable.Ready) it.report else Loadable.Loading) }
        loading = viewModelScope.launch {
            val result: Loadable<AnalyticsReport> = try {
                Loadable.Ready(repository.call { source.report(target, query, repository.selectedCity) })
            } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            state.update { it.copy(report = result, refreshing = false) }
        }
    }

    /** Un periodo con nome: le date scelte a mano si dimenticano, come nel pannello. */
    fun setPeriod(period: String) {
        if (period !in REPORT_PERIODS || (period == state.value.query.period && !state.value.query.custom)) return
        apply(state.value.query.copy(period = period, from = null, until = null), state.value.labels)
    }

    /** I filtri scelti nel foglio «Filtri». Nella pagina di un soggetto restano solo periodo e canale. */
    fun apply(query: EditorialReportQuery, labels: FilterLabels) {
        val clean = if (target != null) query.periodAndChannel() else query
        val cleanLabels = FilterLabels(
            venue = labels.venue.takeIf { (clean.venue ?: 0) > 0 },
            organizer = labels.organizer.takeIf { (clean.organizer ?: 0) > 0 },
            event = labels.event.takeIf { (clean.event ?: 0) > 0 },
        )
        storeReportQuery(saved, clean)
        saved[KEY_VENUE_LABEL] = cleanLabels.venue
        saved[KEY_ORGANIZER_LABEL] = cleanLabels.organizer
        saved[KEY_EVENT_LABEL] = cleanLabels.event
        state.update { it.copy(query = clean, labels = cleanLabels) }
        load()
    }

    /** «Azzera filtri» (nella pagina di un soggetto «Reimposta periodo e canale»): ultimi 30 giorni, nessun filtro. */
    fun reset() = apply(EditorialReportQuery(), FilterLabels())

    /**
     * Gli eventi del filtro «Evento» (ristretti al locale e all'organizzatore
     * scelti nel foglio) e di «Guarda un altro evento» (senza l'evento che si
     * sta guardando).
     */
    suspend fun searchEvents(search: String, venue: Long?, organizer: Long?): EventChoiceGroups =
        repository.call { source.events(search, venue, organizer, target?.takeIf { it.type == "event" }?.id, repository.selectedCity) }

    /** Le scelte dei filtri «Locale» (`venues`) e «Organizzatore» (`organizers`). */
    suspend fun searchProfiles(type: String, search: String): List<AnalyticsProfileChoice> =
        repository.call { source.profiles(type, search, repository.selectedCity) }

    /** Il file di una tabella (o dell'intero rapporto in Excel), nella cache dell'app per «Apri» e «Condividi». */
    fun export(dataset: String, format: String, cacheDir: File) {
        if (state.value.export.busy != null) return
        val query = state.value.query
        state.update { it.copy(export = ExportState(busy = format)) }
        viewModelScope.launch {
            val next = try {
                runCatching { ManagementFiles.purge(cacheDir) }
                ExportState(file = repository.call { source.export(target, query, dataset, format, repository.selectedCity, ManagementFiles.directory(cacheDir)) })
            } catch (e: CancellationException) { throw e } catch (e: Exception) { ExportState(error = requestFailureMessage(e)) }
            state.update { it.copy(export = next) }
        }
    }
}

// ------------------------------------------------------------------ tabelle

internal data class EditorialTableUiState(
    val page: Int = 1,
    val table: Loadable<AnalyticsTable> = Loadable.Loading,
    val export: ExportState = ExportState(),
)

/** Una tabella di «Tabelle» (R-ST-03): venti righe per pagina e i due file da scaricare, come i pulsanti del pannello. */
internal class EditorialTableViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialAnalyticsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val target: AnalyticsTarget? = savedTarget(saved)
    val query: EditorialReportQuery = savedReportQuery(saved, includeEntities = target == null)
    val dataset: String = saved.get<String>(KEY_DATASET)?.takeIf { it in ANALYTICS_DATASETS } ?: "events"
    private val state = MutableStateFlow(EditorialTableUiState(page = saved.get<Int>(KEY_PAGE) ?: 1))
    val uiState: StateFlow<EditorialTableUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load() }

    fun load() {
        loading?.cancel()
        val page = state.value.page
        state.update { it.copy(table = Loadable.Loading) }
        loading = viewModelScope.launch {
            val result: Loadable<AnalyticsTable> = try {
                Loadable.Ready(repository.call { source.table(target, query, dataset, page, repository.selectedCity) })
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
        if (state.value.export.busy != null) return
        state.update { it.copy(export = ExportState(busy = format)) }
        viewModelScope.launch {
            val next = try {
                runCatching { ManagementFiles.purge(cacheDir) }
                ExportState(file = repository.call { source.export(target, query, dataset, format, repository.selectedCity, ManagementFiles.directory(cacheDir)) })
            } catch (e: CancellationException) { throw e } catch (e: Exception) { ExportState(error = requestFailureMessage(e)) }
            state.update { it.copy(export = next) }
        }
    }
}

// ---------------------------------------------------------------------- SEO

internal data class SeoUiState(
    val page: Loadable<SeoPage> = Loadable.Loading,
    val cityId: Long? = null,
    val code: String = "",
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
) {
    val codeProblem: SeoCodeProblem?
        get() = (page as? Loadable.Ready)?.value?.searchConsole?.let { seoCodeProblem(code, it.max, it.pattern) } ?: seoCodeProblem(code)
    val canSave: Boolean get() = !saving && cityId != null && codeProblem == null
}

/**
 * «SEO e qualità del catalogo» (R-ST-04, R-ST-05): il modulo di Search
 * Console (cambiando città il campo mostra il codice salvato di quella
 * città, come nel pannello) e il controllo a schede, venti righe per pagina.
 */
internal class SeoViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialAnalyticsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(SeoUiState(cityId = saved.get<Long>(KEY_SEO_CITY)?.takeIf { it > 0 }, code = saved[KEY_SEO_CODE] ?: ""))
    val uiState: StateFlow<SeoUiState> = state.asStateFlow()

    /* Il controllo: «Da sistemare» è la scheda predefinita, venti righe come la pagina. */
    val audit = PagedListController(viewModelScope, ListQuery(tab = saved[KEY_SEO_TAB] ?: SEO_TAB_ISSUES, perPage = SEO_PER_PAGE)) { query, page ->
        repository.call { source.seoAudit(query.params(page)) }
    }

    init {
        load()
        audit.refresh()
    }

    fun load() {
        state.update { it.copy(page = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val page = repository.call { source.seo() }
                state.update { now ->
                    /* Un campo già toccato (rotazione) resta com'era; altrimenti parte dalla città del pannello e dal suo codice. */
                    val cityId = now.cityId?.takeIf { id -> page.searchConsole.cities.any { it.id == id } }
                        ?: page.searchConsole.defaultCityId ?: page.searchConsole.cities.firstOrNull()?.id
                    val code = if (saved.contains(KEY_SEO_CODE)) now.code else page.searchConsole.cities.firstOrNull { it.id == cityId }?.verification.orEmpty()
                    now.copy(page = Loadable.Ready(page), cityId = cityId, code = code)
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(page = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    /** Un'altra città: il campo prende il suo codice salvato, come `afterStateUpdated` del pannello. */
    fun selectCity(id: Long) {
        val page = (state.value.page as? Loadable.Ready)?.value ?: return
        val city = page.searchConsole.cities.firstOrNull { it.id == id } ?: return
        saved[KEY_SEO_CITY] = id
        saved.remove<String>(KEY_SEO_CODE)
        state.update { it.copy(cityId = id, code = city.verification.orEmpty(), errors = FormErrors.None, message = null) }
    }

    fun setCode(value: String) {
        saved[KEY_SEO_CODE] = value
        state.update { it.copy(code = value, errors = it.errors.copy(fields = it.errors.fields - FIELD_CODE), message = null) }
    }

    fun save() {
        val now = state.value
        val cityId = now.cityId ?: return
        if (!now.canSave) return
        state.update { it.copy(saving = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.saveVerification(cityId, now.code) }
                saved.remove<String>(KEY_SEO_CODE)
                state.update { current ->
                    val page = (current.page as? Loadable.Ready)?.value
                    val updated = page?.copy(searchConsole = page.searchConsole.copy(cities = page.searchConsole.cities.map { if (it.id == result.data.id) result.data else it }))
                    current.copy(
                        page = updated?.let { Loadable.Ready(it) } ?: current.page,
                        code = result.data.verification.orEmpty(),
                        message = result.meta.message?.takeIf { it.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(R.string.editorial_seo_saved),
                    )
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(errors = FormErrors.from(e, SEO_FIELDS)) }
            } finally {
                state.update { it.copy(saving = false) }
            }
        }
    }

    fun selectTab(tab: String) {
        if (tab !in SEO_TABS) return
        saved[KEY_SEO_TAB] = tab
        audit.selectTab(tab)
    }

    fun consumeMessage() = state.update { it.copy(message = null) }

    companion object {
        const val FIELD_CITY = "city_id"
        const val FIELD_CODE = "verification"
        val SEO_FIELDS = listOf(FIELD_CITY, FIELD_CODE)
        const val SEO_TAB_ISSUES = "issues"
        const val SEO_TAB_ALL = "all"
        val SEO_TABS = setOf(SEO_TAB_ISSUES, SEO_TAB_ALL)
        const val SEO_PER_PAGE = 20
    }
}

// ------------------------------------------------- ricerche senza risultati

internal data class SearchMissesUiState(
    val cities: List<AnalyticsOption> = emptyList(),
    /* Le righe scelte per «Elimina selezionati». */
    val selected: Set<Long> = emptySet(),
    /* La conferma aperta: una riga (`id`) o il gruppo (`null` con `confirmingBulk`). */
    val confirming: SearchMissRow? = null,
    val confirmingBulk: Boolean = false,
    val busy: Boolean = false,
    val error: String? = null,
    val message: UiText? = null,
)

/**
 * Le ricerche senza risultati (R-ST-06): ricerca sulla parola, filtro
 * «Città», ordine per «Quante volte» (predefinito), «Ultima volta» o «Prima
 * volta», «Elimina» su una riga o sulle righe scelte. Togliere una riga non
 * è un'azione pericolosa: niente password, solo la conferma del pannello.
 */
internal class SearchMissesViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialAnalyticsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(SearchMissesUiState())
    val uiState: StateFlow<SearchMissesUiState> = state.asStateFlow()

    val list = PagedListController(viewModelScope, ListQuery(
        q = saved.get<String>(KEY_MISS_QUERY).orEmpty(),
        filters = saved.get<String>(KEY_MISS_CITY)?.takeIf { it.isNotBlank() }?.let { mapOf(FILTER_CITY to it) } ?: emptyMap(),
        sort = saved.get<String>(KEY_MISS_SORT)?.takeIf { it in SORTS },
    )) { query, page ->
        val result = repository.call { source.searchMisses(query.params(page)) }
        if (result.choices.cities.isNotEmpty()) state.update { it.copy(cities = result.choices.cities) }
        result.page()
    }

    init { list.refresh() }

    fun search(q: String) {
        saved[KEY_MISS_QUERY] = q
        clearSelection()
        list.search(q)
    }

    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_MISS_CITY] = filters[FILTER_CITY]
        clearSelection()
        list.applyFilters(filters)
    }

    fun sortBy(sort: String?) {
        val clean = sort?.takeIf { it in SORTS }
        saved[KEY_MISS_SORT] = clean
        list.sortBy(clean)
    }

    fun toggle(row: SearchMissRow) {
        state.update { now ->
            val next = if (row.id in now.selected) now.selected - row.id else if (now.selected.size < SEARCH_MISS_BULK_MAX) now.selected + row.id else now.selected
            now.copy(selected = next)
        }
    }

    fun clearSelection() = state.update { it.copy(selected = emptySet()) }

    fun askRemove(row: SearchMissRow) = state.update { it.copy(confirming = row, confirmingBulk = false, error = null) }
    fun askRemoveSelected() { if (state.value.selected.isNotEmpty()) state.update { it.copy(confirming = null, confirmingBulk = true, error = null) } }
    fun dismiss() = state.update { it.copy(confirming = null, confirmingBulk = false, error = null) }

    fun confirm() {
        val now = state.value
        if (now.busy) return
        val ids = when {
            now.confirming != null -> listOf(now.confirming.id)
            now.confirmingBulk -> now.selected.toList()
            else -> return
        }
        if (ids.isEmpty()) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { if (now.confirming != null) source.removeSearchMiss(ids.single()) else source.removeSearchMisses(ids) }
                list.remove { it.id in ids }
                state.update {
                    it.copy(confirming = null, confirmingBulk = false, selected = it.selected - ids.toSet(),
                        message = result.meta.message?.takeIf { text -> text.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(R.string.editorial_misses_removed))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(error = requestFailureMessage(e)) }
            } finally {
                state.update { it.copy(busy = false) }
            }
        }
    }

    fun consumeMessage() = state.update { it.copy(message = null) }

    companion object {
        const val FILTER_CITY = "city"
        /* Le colonne che la tabella del pannello ordina; senza ordine vale «Quante volte», dal più cercato. */
        const val SORT_COUNT_ASC = "count"
        const val SORT_LAST = "-last_searched_at"
        const val SORT_FIRST = "first_searched_at"
        val SORTS = setOf(SORT_COUNT_ASC, SORT_LAST, SORT_FIRST)
    }
}

// --------------------------------------------------------- stato salvato

/** Il soggetto della rotta: `event`, `venue` o `organizer` con il suo id, oppure nessuno (tutti gli eventi). */
private fun savedTarget(saved: SavedStateHandle): AnalyticsTarget? =
    saved.get<String>(KEY_TYPE)?.takeIf { it in SUBJECT_TYPES }?.let { type -> saved.get<Long>(KEY_ID)?.takeIf { it > 0 }?.let { AnalyticsTarget(type, it) } }

/** I filtri salvati nella voce di navigazione: gli stessi nomi dei campi delle rotte. */
internal fun savedReportQuery(saved: SavedStateHandle, includeEntities: Boolean): EditorialReportQuery {
    val period = saved.get<String>(KEY_PERIOD)?.takeIf { it in REPORT_PERIODS || it == AnalyticsPeriods.CUSTOM } ?: AnalyticsPeriods.DEFAULT
    val custom = period == AnalyticsPeriods.CUSTOM
    return EditorialReportQuery(
        period = period,
        from = saved.get<String>(KEY_FROM)?.takeIf { custom && it.isNotBlank() },
        until = saved.get<String>(KEY_UNTIL)?.takeIf { custom && it.isNotBlank() },
        venue = if (includeEntities) saved.get<Long>(KEY_VENUE)?.takeIf { it > 0 } else null,
        organizer = if (includeEntities) saved.get<Long>(KEY_ORGANIZER)?.takeIf { it > 0 } else null,
        event = if (includeEntities) saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 } else null,
        channel = saved.get<String>(KEY_CHANNEL)?.takeIf { it.isNotBlank() },
    )
}

private fun storeReportQuery(saved: SavedStateHandle, query: EditorialReportQuery) {
    saved[KEY_PERIOD] = query.period
    saved[KEY_FROM] = query.from
    saved[KEY_UNTIL] = query.until
    saved[KEY_VENUE] = query.venue ?: 0L
    saved[KEY_ORGANIZER] = query.organizer ?: 0L
    saved[KEY_EVENT] = query.event ?: 0L
    saved[KEY_CHANNEL] = query.channel
}

private const val KEY_TYPE = "type"
private const val KEY_ID = "id"
private const val KEY_PERIOD = "period"
private const val KEY_FROM = "from"
private const val KEY_UNTIL = "until"
private const val KEY_VENUE = "venue"
private const val KEY_ORGANIZER = "organizer"
private const val KEY_EVENT = "event"
private const val KEY_CHANNEL = "channel"
private const val KEY_VENUE_LABEL = "venueLabel"
private const val KEY_ORGANIZER_LABEL = "organizerLabel"
private const val KEY_EVENT_LABEL = "eventLabel"
private const val KEY_DATASET = "dataset"
private const val KEY_PAGE = "page"
private const val KEY_SEO_CITY = "seoCity"
private const val KEY_SEO_CODE = "seoCode"
private const val KEY_SEO_TAB = "seoTab"
private const val KEY_MISS_QUERY = "missQuery"
private const val KEY_MISS_CITY = "missCity"
private const val KEY_MISS_SORT = "missSort"

internal val SUBJECT_TYPES = setOf("event", "venue", "organizer")
internal val REPORT_PERIODS = setOf("week", "month", "quarter", "year", "all")
internal val ANALYTICS_DATASETS = listOf("events", "venues", "organizers", "channels", "daily", "links", "occurrences")
