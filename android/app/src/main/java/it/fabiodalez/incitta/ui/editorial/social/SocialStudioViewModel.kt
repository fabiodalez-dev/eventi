package it.fabiodalez.incitta.ui.editorial.social

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialSocialSource
import it.fabiodalez.incitta.data.management.SOCIAL_MAX_CARDS
import it.fabiodalez.incitta.data.management.SOCIAL_MAX_SELECTION
import it.fabiodalez.incitta.data.management.SOCIAL_PAGE_SIZE
import it.fabiodalez.incitta.data.management.SOCIAL_TITLE_MAX
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialCatalogPage
import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.data.management.SocialDay
import it.fabiodalez.incitta.data.management.SocialEventRef
import it.fabiodalez.incitta.data.management.SocialFormatChoice
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.time.YearMonth
import java.time.ZoneId
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Lo studio delle grafiche social della redazione (R-SO-01, R-SO-02), come
 * la pagina «Social» del pannello: lo stesso comportamento dello studio del
 * locale (P10, `ui/management/social`), con la città di lavoro al posto del
 * locale e senza il foglio «Scegli un evento» (nel pannello della redazione
 * lo studio si restringe a un evento solo arrivando dalla sua scheda).
 *
 * - un pacchetto riguarda un giorno solo: toccare una card di un altro giorno
 *   sposta il giorno e la sceglie da sola; una dello stesso giorno entra o
 *   esce dalla selezione;
 * - toccare un giorno del calendario lo fa diventare il periodo dell'elenco e
 *   sceglie tutte le sue serate;
 * - cambiando città si riparte da capo, senza l'evento, come `updatedCityId`.
 */
internal data class EditorialStudioUiState(
    val zone: ZoneId = ZoneId.of("Europe/Rome"),
    /* L'evento a cui è ristretto lo studio, con il suo titolo. */
    val event: SocialEventRef? = null,
    val period: String = "next30",
    val from: String = "",
    val until: String = "",
    val search: String = "",
    val limit: Int = SOCIAL_PAGE_SIZE,
    val catalog: Loadable<SocialCatalogPage> = Loadable.Loading,
    val month: String = "",
    val calendar: Loadable<SocialCalendar> = Loadable.Loading,
    /* Il giorno delle grafiche, `Y-m-d`, e le sue serate preparabili. */
    val date: String = "",
    val day: Loadable<SocialDay> = Loadable.Loading,
    val selected: List<Long> = emptyList(),
    val options: SocialOptions = SocialOptions(),
    val recent: Loadable<List<SocialBatchSummary>> = Loadable.Loading,
    val generating: Boolean = false,
    val switchingCity: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    /* Il pacchetto appena preparato: la schermata lo apre e poi lo consuma. */
    val prepared: String? = null,
) {
    val dayCards: List<SocialCard> get() = day.valueOrNull?.data.orEmpty()
    /* Le serate scelte, solo fra quelle del giorno: l'ordine resta quello del giorno. */
    val chosen: List<SocialCard> get() = dayCards.filter { it.id in selected }
    val canGenerate: Boolean get() = !generating && chosen.isNotEmpty() && chosen.size <= SOCIAL_MAX_SELECTION
    /* Il titolo scritto a mano vale per una serata sola, come nella pagina. */
    val titleTooMany: Boolean get() = options.title.isNotBlank() && chosen.size > 1
}

internal class EditorialSocialStudioViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialSocialSource,
    private val saved: SavedStateHandle,
    private val searchDelayMillis: Long = SEARCH_DELAY_MILLIS,
) : ViewModel() {
    private val state = MutableStateFlow(restore())
    val uiState: StateFlow<EditorialStudioUiState> = state.asStateFlow()
    /** Il banco, per il selettore della città (nascosto con una città sola). */
    val desk: StateFlow<EditorialDesk?> = repository.current
    private var catalogJob: Job? = null
    private var calendarJob: Job? = null
    private var dayJob: Job? = null
    private var searchJob: Job? = null
    private var recentJob: Job? = null
    /* Dopo un cambio di periodo il calendario va sul mese del nuovo periodo, come `setPeriod` della pagina. */
    private var monthFollowsPeriod = false

    init { start() }

    private fun restore(): EditorialStudioUiState {
        val eventId = saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 }
        return EditorialStudioUiState(
            event = eventId?.let { SocialEventRef(it, saved.get<String>(KEY_EVENT_TITLE).orEmpty()) },
            period = saved[KEY_PERIOD] ?: "next30",
            from = saved[KEY_FROM] ?: "",
            until = saved[KEY_UNTIL] ?: "",
            search = saved[KEY_SEARCH] ?: "",
            limit = saved[KEY_LIMIT] ?: SOCIAL_PAGE_SIZE,
            month = saved[KEY_MONTH] ?: "",
            date = saved[KEY_DATE] ?: "",
            selected = saved.get<LongArray>(KEY_SELECTED)?.toList().orEmpty(),
            options = SocialOptions(
                format = SocialFormatChoice.from(saved[KEY_FORMAT]),
                monochrome = saved[KEY_MONOCHROME] ?: true,
                showAddress = saved[KEY_ADDRESS] ?: true,
                showPrice = saved[KEY_PRICE] ?: true,
                cover = saved[KEY_COVER] ?: false,
                title = saved[KEY_TITLE] ?: "",
            ),
        )
    }

    /** Apre lo studio; dopo una rotazione rilegge lo stesso giorno con la stessa selezione. */
    fun start() {
        loadDay(state.value.date.ifBlank { null }, selectAll = saved.get<LongArray>(KEY_SELECTED) == null)
        loadCatalog()
        loadRecent()
    }

    private fun city(): String? = repository.selectedCity

    // ---------------------------------------------------------- letture

    /** Le serate preparabili di un giorno. Senza giorno è quello da cui si apre la pagina: lo dice il server. */
    private fun loadDay(date: String?, selectAll: Boolean, keep: List<Long>? = null) {
        dayJob?.cancel()
        state.update { it.copy(day = Loadable.Loading) }
        val eventId = state.value.event?.id
        dayJob = viewModelScope.launch {
            try {
                val day = repository.call { source.day(city(), date, eventId) }
                val ids = day.data.map(SocialCard::id)
                val first = state.value.date.isBlank()
                state.update {
                    val selected = when {
                        keep != null -> keep.filter { id -> id in ids }
                        selectAll -> ids
                        else -> it.selected.filter { id -> id in ids }
                    }
                    it.copy(
                        day = Loadable.Ready(day),
                        date = day.meta.date,
                        selected = selected,
                        event = it.event?.let { ref -> day.meta.event ?: ref },
                        month = it.month.ifBlank { day.meta.date.take(7) },
                        zone = socialZone(day.meta.timezone) ?: it.zone,
                    )
                }
                persistSelection()
                saved[KEY_MONTH] = state.value.month
                state.value.event?.let { saved[KEY_EVENT_TITLE] = it.title }
                if (first || state.value.calendar !is Loadable.Ready) loadCalendar() else loadCalendar(quiet = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(day = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun loadCatalog() {
        catalogJob?.cancel()
        val current = state.value
        val query = SocialCatalogQuery(current.period, current.from, current.until, current.search, current.limit, current.event?.id)
        state.update { it.copy(catalog = if (it.catalog is Loadable.Ready) it.catalog else Loadable.Loading) }
        catalogJob = viewModelScope.launch {
            try {
                val page = repository.call { source.catalog(city(), query) }
                state.update { it.copy(catalog = Loadable.Ready(page), from = page.meta.from, until = page.meta.until, zone = socialZone(page.meta.timezone) ?: it.zone) }
                saved[KEY_FROM] = page.meta.from
                saved[KEY_UNTIL] = page.meta.until
                if (monthFollowsPeriod) {
                    monthFollowsPeriod = false
                    setMonth(page.meta.from.take(7))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(catalog = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun loadCalendar(quiet: Boolean = false) {
        val current = state.value
        val month = current.month.ifBlank { current.date.take(7) }
        if (month.isBlank()) return
        calendarJob?.cancel()
        if (!quiet) state.update { it.copy(calendar = if (it.calendar is Loadable.Ready) it.calendar else Loadable.Loading) }
        calendarJob = viewModelScope.launch {
            try {
                val calendar = repository.call { source.calendar(city(), month, current.date, current.event?.id, current.search) }
                state.update { it.copy(calendar = Loadable.Ready(calendar)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(calendar = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun loadRecent() {
        recentJob?.cancel()
        recentJob = viewModelScope.launch {
            try {
                val list = repository.call { source.recent(city()) }
                state.update { it.copy(recent = if (list.isEmpty()) Loadable.Empty else Loadable.Ready(list)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(recent = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun retryCatalog() = loadCatalog()
    fun retryCalendar() = loadCalendar()
    fun retryDay() = loadDay(state.value.date.ifBlank { null }, selectAll = false)
    fun retryRecent() = loadRecent()

    // ---------------------------------------------------------- città

    /**
     * Il selettore «Città» della pagina: cambia la città di lavoro di tutta
     * l'area (il banco si rilegge) e lo studio riparte dal giorno da cui si
     * apre, senza l'evento.
     */
    fun selectCity(slug: String) {
        if (slug == repository.selectedCity || state.value.switchingCity) return
        state.update { it.copy(switchingCity = true) }
        viewModelScope.launch {
            try {
                repository.selectCity(slug)
                listOf(KEY_EVENT, KEY_EVENT_TITLE, KEY_DATE, KEY_SELECTED, KEY_MONTH).forEach { saved.remove<Any>(it) }
                saved[KEY_PERIOD] = "next30"
                saved[KEY_FROM] = ""
                saved[KEY_UNTIL] = ""
                saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
                saved[KEY_TITLE] = ""
                state.update {
                    it.copy(
                        event = null, period = "next30", from = "", until = "", limit = SOCIAL_PAGE_SIZE, month = "", date = "",
                        selected = emptyList(), calendar = Loadable.Loading, catalog = Loadable.Loading, recent = Loadable.Loading,
                        options = it.options.copy(title = ""), errors = FormErrors.None,
                    )
                }
                loadDay(null, selectAll = true)
                loadCatalog()
                loadRecent()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors(general = requestFailureMessage(e))) } }
            finally { state.update { it.copy(switchingCity = false) } }
        }
    }

    // ---------------------------------------------------------- periodo e ricerca

    fun setPeriod(period: String) {
        if (period !in PERIODS) return
        saved[KEY_PERIOD] = period
        saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
        if (period != "custom") { saved[KEY_FROM] = ""; saved[KEY_UNTIL] = "" }
        state.update { it.copy(period = period, limit = SOCIAL_PAGE_SIZE, from = if (period == "custom") it.from else "", until = if (period == "custom") it.until else "") }
        monthFollowsPeriod = true
        loadCatalog()
    }

    /** «Dal» e «Al»: il periodo diventa «personalizzato». */
    fun setRange(from: String?, until: String?) {
        val current = state.value
        val start = from ?: current.from
        val end = until ?: current.until
        saved[KEY_PERIOD] = "custom"
        saved[KEY_FROM] = start
        saved[KEY_UNTIL] = end
        saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
        state.update { it.copy(period = "custom", from = start, until = end, limit = SOCIAL_PAGE_SIZE) }
        loadCatalog()
    }

    /** La ricerca: si aspetta che si smetta di scrivere, poi si rileggono elenco e calendario. */
    fun setSearch(value: String) {
        val text = value.take(100)
        saved[KEY_SEARCH] = text
        saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
        state.update { it.copy(search = text, limit = SOCIAL_PAGE_SIZE) }
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(searchDelayMillis)
            loadCatalog()
            loadCalendar(quiet = true)
        }
    }

    /** «Mostra altre serate»: 24 card in più, fino a 240. */
    fun showMore() {
        val limit = (state.value.limit + SOCIAL_PAGE_SIZE).coerceAtMost(SOCIAL_MAX_CARDS)
        saved[KEY_LIMIT] = limit
        state.update { it.copy(limit = limit) }
        loadCatalog()
    }

    // ---------------------------------------------------------- calendario

    fun shiftMonth(step: Int) {
        val month = runCatching { YearMonth.parse(state.value.month) }.getOrNull() ?: return
        setMonth((if (step < 0) month.minusMonths(1) else month.plusMonths(1)).toString())
    }

    private fun setMonth(month: String) {
        if (month.isBlank()) return
        saved[KEY_MONTH] = month
        state.update { it.copy(month = month) }
        loadCalendar()
    }

    /** Un giorno del calendario: l'elenco mostra quel giorno e le sue serate diventano la selezione. */
    fun pickDay(day: String) {
        saved[KEY_PERIOD] = "custom"
        saved[KEY_FROM] = day
        saved[KEY_UNTIL] = day
        saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
        saved[KEY_MONTH] = day.take(7)
        state.update { it.copy(period = "custom", from = day, until = day, limit = SOCIAL_PAGE_SIZE, month = day.take(7), errors = FormErrors.None) }
        loadCatalog()
        loadDay(day, selectAll = true)
    }

    // ---------------------------------------------------------- selezione

    /** Una card dell'elenco: di un altro giorno sposta il giorno e la sceglie da sola; dello stesso entra o esce. */
    fun pick(card: SocialCard) {
        if (!card.selectable) return
        if (card.businessDate != state.value.date) {
            state.update { it.copy(selected = listOf(card.id), errors = FormErrors.None) }
            loadDay(card.businessDate, selectAll = false, keep = listOf(card.id))
            return
        }
        toggle(card.id)
    }

    /** Una serata del giorno, dalla fila delle anteprime. */
    fun toggle(id: Long) {
        state.update { current ->
            current.copy(selected = if (id in current.selected) current.selected - id else current.selected + id, errors = FormErrors.None)
        }
        persistSelection()
    }

    fun selectAll() {
        state.update { it.copy(selected = it.dayCards.map(SocialCard::id), errors = FormErrors.None) }
        persistSelection()
    }

    /** «Mostra tutti gli eventi del giorno»: lo stesso giorno, con tutte le sue serate, senza il titolo scritto a mano. */
    fun clearEvent() {
        saved.remove<Long>(KEY_EVENT)
        saved.remove<String>(KEY_EVENT_TITLE)
        saved[KEY_TITLE] = ""
        state.update { it.copy(event = null, options = it.options.copy(title = ""), errors = FormErrors.None) }
        loadCatalog()
        loadDay(state.value.date.ifBlank { null }, selectAll = true)
    }

    // ---------------------------------------------------------- opzioni e preparazione

    fun setOptions(options: SocialOptions) {
        val clean = options.copy(title = options.title.take(SOCIAL_TITLE_MAX))
        saved[KEY_FORMAT] = clean.format.value
        saved[KEY_MONOCHROME] = clean.monochrome
        saved[KEY_ADDRESS] = clean.showAddress
        saved[KEY_PRICE] = clean.showPrice
        saved[KEY_COVER] = clean.cover
        saved[KEY_TITLE] = clean.title
        state.update { it.copy(options = clean, errors = it.errors.copy(fields = it.errors.fields - "title")) }
    }

    /** «Prepara le grafiche selezionate». */
    fun generate() {
        val current = state.value
        if (!current.canGenerate) return
        if (current.titleTooMany) {
            state.update { it.copy(errors = FormErrors(fields = mapOf("title" to TITLE_SINGLE))) }
            return
        }
        val ids = current.chosen.map(SocialCard::id)
        state.update { it.copy(generating = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val batch = repository.call { source.prepare(city(), current.date, ids, current.options, current.event?.id) }
                state.update { it.copy(prepared = batch.id) }
                loadRecent()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, FIELDS)) } }
            finally { state.update { it.copy(generating = false) } }
        }
    }

    fun consumePrepared() { state.update { it.copy(prepared = null) } }

    private fun persistSelection() {
        saved[KEY_DATE] = state.value.date
        saved[KEY_SELECTED] = state.value.selected.toLongArray()
    }

    companion object {
        val PERIODS = listOf("today", "week", "next30", "custom")
        val FIELDS = listOf("title", "format", "image_fit")
        /* Lo stesso testo del server (`social.title_single`), per non mandare una richiesta che sarebbe respinta. */
        const val TITLE_SINGLE = "Il titolo alternativo si applica a un solo evento alla volta."
        const val SEARCH_DELAY_MILLIS = 400L
        const val KEY_EVENT = "event"
        const val KEY_EVENT_TITLE = "social_event_title"
        const val KEY_PERIOD = "social_period"
        const val KEY_FROM = "social_from"
        const val KEY_UNTIL = "social_until"
        const val KEY_SEARCH = "social_search"
        const val KEY_LIMIT = "social_limit"
        const val KEY_MONTH = "social_month"
        const val KEY_DATE = "social_date"
        const val KEY_SELECTED = "social_selected"
        const val KEY_FORMAT = "social_format"
        const val KEY_MONOCHROME = "social_monochrome"
        const val KEY_ADDRESS = "social_address"
        const val KEY_PRICE = "social_price"
        const val KEY_COVER = "social_cover"
        const val KEY_TITLE = "social_title"
    }
}

internal fun socialZone(id: String?): ZoneId? = id?.let { runCatching { ZoneId.of(it) }.getOrNull() }
