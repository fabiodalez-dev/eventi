package it.fabiodalez.incitta.ui.management.social

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventChoice
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSocialSource
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SOCIAL_MAX_CARDS
import it.fabiodalez.incitta.data.management.SOCIAL_MAX_SELECTION
import it.fabiodalez.incitta.data.management.SOCIAL_PAGE_SIZE
import it.fabiodalez.incitta.data.management.SOCIAL_TITLE_MAX
import it.fabiodalez.incitta.data.management.SocialBatch
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialCatalogPage
import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.data.management.SocialDay
import it.fabiodalez.incitta.data.management.SocialEventRef
import it.fabiodalez.incitta.data.management.SocialFormatChoice
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.io.File
import java.time.YearMonth
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Lo studio delle grafiche social (W-SO-01…08) e un pacchetto preparato
 * (W-SO-06, W-SO-07). Le regole stanno sul server (`SocialStudioDesk`, la
 * stessa classe della pagina «Social»); qui resta il comportamento visibile
 * della pagina:
 *
 * - un pacchetto riguarda un giorno solo: toccare una card di un altro giorno
 *   sposta il giorno e la sceglie da sola; una dello stesso giorno entra o
 *   esce dalla selezione;
 * - toccare un giorno del calendario lo fa diventare il periodo dell'elenco e
 *   sceglie tutte le sue serate;
 * - cambiato il giorno, si scelgono tutte le sue serate («seleziona tutte»).
 */

/** Il locale dello studio: quello della rotta, poi quello scelto nella gestione. Solo un locale ha lo studio. */
internal suspend fun resolveSocialVenue(repository: ManagementRepository, key: String?): ManagementSubject? {
    val pick: (it.fabiodalez.incitta.data.management.ManagementSubjects) -> ManagementSubject? = { list ->
        (if (key.isNullOrBlank()) list.find(repository.selectedSubjectKey) ?: list.all().firstOrNull() else list.find(key))
            ?.takeIf { it.kind == SubjectKind.Venue && it.permissions.socialUse }
    }
    val found = repository.subjects.value?.let(pick) ?: pick(repository.loadSubjects())
    found?.let { repository.selectedSubjectKey = it.key }
    return found
}

internal fun ManagementRepository.socialApi(): ManagementSocialSource =
    social ?: throw IllegalStateException("Grafiche social non disponibili in questa sessione")

internal data class SocialStudioUiState(
    val venue: Loadable<ManagementSubject> = Loadable.Loading,
    /* L'evento a cui è ristretto lo studio (W-SO-08), con il suo titolo. */
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
    val errors: FormErrors = FormErrors.None,
    /* Il pacchetto appena preparato: la schermata lo apre e poi lo consuma. */
    val prepared: String? = null,
) {
    val venueSubject: ManagementSubject? get() = venue.valueOrNull
    val dayCards: List<SocialCard> get() = day.valueOrNull?.data.orEmpty()
    /* Le serate scelte, solo fra quelle del giorno: l'ordine resta quello del giorno. */
    val chosen: List<SocialCard> get() = dayCards.filter { it.id in selected }
    val canGenerate: Boolean get() = !generating && chosen.isNotEmpty() && chosen.size <= SOCIAL_MAX_SELECTION
    /* Il titolo scritto a mano vale per una serata sola, come nella pagina. */
    val titleTooMany: Boolean get() = options.title.isNotBlank() && chosen.size > 1
}

internal class SocialStudioViewModel(
    private val repository: ManagementRepository,
    private val saved: SavedStateHandle,
    private val searchDelayMillis: Long = SEARCH_DELAY_MILLIS,
) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(restore())
    val uiState: StateFlow<SocialStudioUiState> = state.asStateFlow()
    private var catalogJob: Job? = null
    private var calendarJob: Job? = null
    private var dayJob: Job? = null
    private var searchJob: Job? = null
    /* Dopo un cambio di periodo il calendario va sul mese del nuovo periodo, come `setPeriod` della pagina. */
    private var monthFollowsPeriod = false

    init { start() }

    private fun restore(): SocialStudioUiState {
        val eventId = saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 }
        return SocialStudioUiState(
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

    /** Trova il locale e apre lo studio; «Riprova» riparte da qui. */
    fun start() {
        state.update { it.copy(venue = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val venue = resolveSocialVenue(repository, key)
                state.update { it.copy(venue = venue?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
                if (venue != null) {
                    // Già aperto (dopo una rotazione o un ritorno): si rilegge lo stesso giorno con la stessa selezione.
                    loadDay(state.value.date.ifBlank { null }, selectAll = saved.get<LongArray>(KEY_SELECTED) == null)
                    loadCatalog()
                    loadRecent()
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(venue = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun venueId(): Long? = state.value.venueSubject?.id

    // ---------------------------------------------------------- letture

    /**
     * Le serate preparabili di un giorno. Senza giorno è quello da cui si
     * apre la pagina (oggi, la prossima data dell'evento, o la prima serata
     * in arrivo): lo dice il server.
     */
    private fun loadDay(date: String?, selectAll: Boolean, keep: List<Long>? = null) {
        val id = venueId() ?: return
        dayJob?.cancel()
        state.update { it.copy(day = Loadable.Loading) }
        dayJob = viewModelScope.launch {
            try {
                val day = repository.socialApi().day(id, date, state.value.event?.id)
                val ids = day.data.map(SocialCard::id)
                val selected = when {
                    keep != null -> keep.filter { it in ids }
                    selectAll -> ids
                    else -> state.value.selected.filter { it in ids }
                }
                val first = state.value.date.isBlank()
                state.update {
                    it.copy(
                        day = Loadable.Ready(day),
                        date = day.meta.date,
                        selected = selected,
                        event = it.event?.let { ref -> day.meta.event ?: ref },
                        month = it.month.ifBlank { day.meta.date.take(7) },
                    )
                }
                persistSelection()
                saved[KEY_MONTH] = state.value.month
                if (first || state.value.calendar !is Loadable.Ready) loadCalendar() else loadCalendar(quiet = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(day = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun loadCatalog() {
        val id = venueId() ?: return
        catalogJob?.cancel()
        val current = state.value
        val query = SocialCatalogQuery(current.period, current.from, current.until, current.search, current.limit, current.event?.id)
        state.update { it.copy(catalog = if (it.catalog is Loadable.Ready) it.catalog else Loadable.Loading) }
        catalogJob = viewModelScope.launch {
            try {
                val page = repository.socialApi().catalog(id, query)
                state.update { it.copy(catalog = Loadable.Ready(page), from = page.meta.from, until = page.meta.until) }
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
        val id = venueId() ?: return
        val current = state.value
        val month = current.month.ifBlank { current.date.take(7) }
        if (month.isBlank()) return
        calendarJob?.cancel()
        if (!quiet) state.update { it.copy(calendar = if (it.calendar is Loadable.Ready) it.calendar else Loadable.Loading) }
        calendarJob = viewModelScope.launch {
            try {
                val calendar = repository.socialApi().calendar(id, month, current.date, current.event?.id, current.search)
                state.update { it.copy(calendar = Loadable.Ready(calendar)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(calendar = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    private fun loadRecent() {
        val id = venueId() ?: return
        viewModelScope.launch {
            try {
                val list = repository.socialApi().recent(id)
                state.update { it.copy(recent = if (list.isEmpty()) Loadable.Empty else Loadable.Ready(list)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(recent = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun retryCatalog() = loadCatalog()
    fun retryCalendar() = loadCalendar()
    fun retryDay() = loadDay(state.value.date.ifBlank { null }, selectAll = false)
    fun retryRecent() = loadRecent()

    /** Il foglio «Scegli un evento»: gli eventi del locale. */
    suspend fun searchEvents(query: String): EventChoiceGroups {
        val id = venueId() ?: return EventChoiceGroups()
        return repository.socialApi().events(id, query)
    }

    // ---------------------------------------------------------- periodo e ricerca (W-SO-01)

    fun setPeriod(period: String) {
        if (period !in PERIODS) return
        saved[KEY_PERIOD] = period
        saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
        if (period != "custom") { saved[KEY_FROM] = ""; saved[KEY_UNTIL] = "" }
        state.update { it.copy(period = period, limit = SOCIAL_PAGE_SIZE, from = if (period == "custom") it.from else "", until = if (period == "custom") it.until else "") }
        monthFollowsPeriod = true
        loadCatalog()
    }

    /** «Dal» e «Al»: il periodo diventa «a scelta». */
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

    /** «Mostra altre»: 24 card in più, fino a 240. */
    fun showMore() {
        val limit = (state.value.limit + SOCIAL_PAGE_SIZE).coerceAtMost(SOCIAL_MAX_CARDS)
        saved[KEY_LIMIT] = limit
        state.update { it.copy(limit = limit) }
        loadCatalog()
    }

    // ---------------------------------------------------------- calendario (W-SO-02)

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

    // ---------------------------------------------------------- selezione (W-SO-03)

    /**
     * Una card dell'elenco. Se è di un altro giorno, quel giorno diventa la
     * data delle grafiche e la serata è la sola scelta; se è dello stesso
     * giorno, entra o esce dalla selezione.
     */
    fun pick(card: SocialCard) {
        if (!card.selectable) return
        val current = state.value
        if (card.businessDate != current.date) {
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

    // ---------------------------------------------------------- evento (W-SO-08)

    /** Restringe lo studio a un evento del locale: si riapre sulla sua prossima data. */
    fun setEvent(choice: EventChoice) {
        saved[KEY_EVENT] = choice.id
        saved[KEY_EVENT_TITLE] = choice.title
        saved[KEY_PERIOD] = "next30"
        saved[KEY_FROM] = ""
        saved[KEY_UNTIL] = ""
        saved[KEY_LIMIT] = SOCIAL_PAGE_SIZE
        saved[KEY_MONTH] = ""
        state.update { it.copy(event = SocialEventRef(choice.id, choice.title), period = "next30", from = "", until = "", limit = SOCIAL_PAGE_SIZE, month = "", date = "", errors = FormErrors.None) }
        loadCatalog()
        loadDay(null, selectAll = true)
    }

    /** «Togli il filtro»: lo stesso giorno, con tutte le sue serate, e senza il titolo scritto a mano. */
    fun clearEvent() {
        saved.remove<Long>(KEY_EVENT)
        saved.remove<String>(KEY_EVENT_TITLE)
        saved[KEY_TITLE] = ""
        state.update { it.copy(event = null, options = it.options.copy(title = ""), errors = FormErrors.None) }
        loadCatalog()
        loadDay(state.value.date.ifBlank { null }, selectAll = true)
    }

    // ---------------------------------------------------------- opzioni (W-SO-04)

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

    // ---------------------------------------------------------- preparazione (W-SO-05)

    fun generate() {
        val current = state.value
        val id = current.venueSubject?.id ?: return
        if (!current.canGenerate) return
        if (current.titleTooMany) {
            state.update { it.copy(errors = FormErrors(fields = mapOf("title" to TITLE_SINGLE))) }
            return
        }
        val ids = current.chosen.map(SocialCard::id)
        state.update { it.copy(generating = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val batch = repository.socialApi().prepare(id, current.date, ids, current.options, current.event?.id)
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
        /* Gli errori del server che il modulo mostra sotto un campo; gli altri vanno in cima. */
        val FIELDS = listOf("title", "format", "image_fit")
        /* Lo stesso testo del server (`social.title_single`), per non mandare una richiesta che sarebbe respinta. */
        const val TITLE_SINGLE = "Il titolo alternativo si applica a un solo evento alla volta."
        const val SEARCH_DELAY_MILLIS = 400L
        const val KEY_SUBJECT = "subject"
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

// ---------------------------------------------------------------- un pacchetto

/** Cosa fare del file appena scaricato: lo fa la schermata, che ha il `Context`. */
internal enum class SocialFileUse { SaveImage, ShareImage, ShareZip, OpenZip }

internal data class SocialPendingFile(val file: DownloadedFile, val use: SocialFileUse, val name: String)

internal data class SocialBatchUiState(
    val venue: ManagementSubject? = null,
    val batch: Loadable<SocialBatch> = Loadable.Loading,
    /* L'immagine (o lo ZIP, -1) che si sta scaricando. */
    val downloading: Int? = null,
    val file: SocialPendingFile? = null,
    val message: UiText? = null,
    val error: UiText? = null,
) {
    val busy: Boolean get() = downloading != null
}

/** Un pacchetto preparato: immagini, «Salva nella galleria», «Condividi», didascalia, ZIP (W-SO-06, W-SO-07). */
internal class SocialBatchViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved["subject"]
    val batchId: String = saved["id"] ?: ""
    private val state = MutableStateFlow(SocialBatchUiState())
    val uiState: StateFlow<SocialBatchUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(batch = if (it.batch is Loadable.Ready) it.batch else Loadable.Loading) }
        viewModelScope.launch {
            try {
                val venue = state.value.venue ?: resolveSocialVenue(repository, key)
                if (venue == null || batchId.isBlank()) { state.update { it.copy(batch = Loadable.Empty) }; return@launch }
                val batch = repository.socialApi().batch(venue.id, batchId)
                state.update { it.copy(venue = venue, batch = Loadable.Ready(batch)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(batch = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** Scarica un'immagine del pacchetto nella cache, per salvarla o condividerla. */
    fun image(item: SocialBatchItem, use: SocialFileUse, cacheDir: File) {
        val venue = state.value.venue ?: return
        if (state.value.busy) return
        state.update { it.copy(downloading = item.index, message = null, error = null) }
        viewModelScope.launch {
            try {
                runCatching { ManagementFiles.purge(cacheDir) }
                val file = repository.socialApi().image(venue.id, batchId, item, ManagementFiles.directory(cacheDir))
                state.update { it.copy(file = SocialPendingFile(file, use, item.filename.substringBeforeLast('.'))) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
            finally { state.update { it.copy(downloading = null) } }
        }
    }

    /** Lo ZIP con immagini e didascalie, da condividere o aprire con un'altra app. */
    fun zip(use: SocialFileUse, cacheDir: File) {
        val venue = state.value.venue ?: return
        val batch = state.value.batch.valueOrNull ?: return
        if (state.value.busy) return
        state.update { it.copy(downloading = ZIP, message = null, error = null) }
        viewModelScope.launch {
            try {
                runCatching { ManagementFiles.purge(cacheDir) }
                val file = repository.socialApi().zip(venue.id, batch, ManagementFiles.directory(cacheDir))
                state.update { it.copy(file = SocialPendingFile(file, use, file.file.nameWithoutExtension)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
            finally { state.update { it.copy(downloading = null) } }
        }
    }

    /** L'esito di quello che la schermata ha fatto del file. */
    fun fileHandled(message: UiText?, error: UiText? = null) {
        state.update { it.copy(file = null, message = message, error = error) }
    }

    fun captionCopied() { state.update { it.copy(message = UiText.Res(R.string.social_caption_copied)) } }

    companion object { const val ZIP = -1 }
}
