package it.fabiodalez.incitta.ui.editorial.system

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialImportSource
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.ImportFormLimits
import it.fabiodalez.incitta.data.editorial.ImportPreview
import it.fabiodalez.incitta.data.editorial.ImportSourceFormOptions
import it.fabiodalez.incitta.data.editorial.ImportVenueChoice
import it.fabiodalez.incitta.data.editorial.MappingDraft
import it.fabiodalez.incitta.data.editorial.MappingEntry
import it.fabiodalez.incitta.data.editorial.ScheduledNotificationRow
import it.fabiodalez.incitta.data.editorial.SchedulerOverviewData
import it.fabiodalez.incitta.data.editorial.SystemRef
import it.fabiodalez.incitta.data.editorial.SystemSource
import it.fabiodalez.incitta.data.editorial.mappingDraft
import it.fabiodalez.incitta.data.editorial.mappingJson
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.takeEditorialListTarget
import it.fabiodalez.incitta.ui.editorial.moderation.decodeFilters
import it.fabiodalez.incitta.ui.editorial.moderation.encodeFilters
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/*
 * R12 — Sistema (R-SY-01…07), come il gruppo «Sistema» del pannello:
 * sorgenti di import (elenco, modulo, anteprima, «Esegui adesso», storico),
 * invii previsti (elenco, «Annulla questo invio»), cron e programmazioni
 * (sola lettura). Chi vede quale voce lo dicono i `permissions` del banco;
 * quali pulsanti ha una riga lo dicono le sue `abilities`, calcolate dal
 * server con le policy del sito. Ogni chiamata passa da
 * `repository.call { }`: con un 423 compare la richiesta della password e la
 * chiamata riparte.
 */

// ------------------------------------------------------------------ sezioni

/** Le tre voci del gruppo «Sistema» del pannello portate da R12, con il permesso che le mostra e il contatore del menu. */
internal enum class SystemSection(val permission: String, val badge: String?, val title: Int, val help: Int) {
    Imports("imports_manage", null, R.string.system_imports_title, R.string.system_imports_help),
    Notifications("notifications_view", "scheduled_notifications_due", R.string.system_notifications_title, R.string.system_notifications_help),
    Operations("system_view", null, R.string.system_operations_title, R.string.system_operations_help),
}

/** Le voci che la persona vede: il moderatore ha solo gli invii previsti, come nel pannello. */
internal fun systemSections(can: EditorialPermissions): List<SystemSection> =
    SystemSection.entries.filter { can.allows(it.permission) }

private fun messageOf(text: String?, fallback: Int): UiText = text?.takeIf { it.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(fallback)

private const val KEY_FILTERS = "filters"
private const val KEY_SORT = "sort"
private const val KEY_QUERY = "q"
internal const val KEY_ID = "id"

/**
 * I criteri di partenza di un elenco: quelli salvati, oppure, la prima
 * volta, i filtri con cui un riquadro del riepilogo l'ha aperto
 * (`ListHandoff`: «Import falliti»).
 */
private fun initialQuery(saved: SavedStateHandle, list: String, filterKeys: Set<String>, sorts: Set<String>): ListQuery {
    saved.takeEditorialListTarget(list)?.let { target -> saved[KEY_FILTERS] = encodeFilters(target.filters.filterKeys { it in filterKeys }) }
    return ListQuery(
        q = saved.get<String>(KEY_QUERY).orEmpty(),
        filters = decodeFilters(saved[KEY_FILTERS], filterKeys),
        sort = saved.get<String>(KEY_SORT)?.takeIf { it in sorts },
    )
}

/** Gli elenchi dei moduli del server (`import_source_types`, `notification_types`, …), letti una volta per sessione. */
private suspend fun loadOptions(repository: EditorialRepository, into: MutableStateFlow<ManagementOptions?>) {
    if (into.value == null) into.value = repository.options()
}

// --------------------------------------------------------------------- menu

/** La prima schermata dell'area: le voci permesse con i loro contatori, riletti entrando. */
internal class SystemHubViewModel(private val repository: EditorialRepository) : ViewModel() {
    val desk: StateFlow<EditorialDesk?> = repository.current

    init { refresh() }

    fun refresh() {
        viewModelScope.launch {
            try { repository.loadDesk() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* restano i contatori di prima */ }
        }
    }
}

// ------------------------------------------------------------ sorgenti: elenco

/** Le sorgenti (R-SY-01): filtri tipo, attiva, «Import falliti»; ordine per ultima esecuzione. Il pannello non ha una ricerca qui. */
internal class ImportSourcesViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, initialQuery(saved, LIST, FILTERS, SORTS).copy(q = "")) { query, page ->
        repository.call { source.importSources(query.params(page)) }
    }
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch { loadOptions(repository, optionsState) }
    }

    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_FILTERS] = encodeFilters(filters)
        list.applyFilters(filters)
    }

    fun sortBy(sort: String?) {
        saved[KEY_SORT] = sort
        list.sortBy(sort)
    }

    companion object {
        /* Il nome dell'elenco per il riepilogo (`target.area`). */
        const val LIST = "import_sources"
        const val FILTER_TYPE = "type"
        const val FILTER_ACTIVE = "active"
        const val FILTER_FAILED = "failed"
        val FILTERS = setOf(FILTER_TYPE, FILTER_ACTIVE, FILTER_FAILED)
        /* «Ultima esecuzione» si ordina; il predefinito è dalla più recente. */
        const val SORT_OLDEST = "last_run_at"
        val SORTS = setOf(SORT_OLDEST)
    }
}

// ----------------------------------------------------------- sorgenti: scheda

/** Le conferme della scheda di una sorgente. */
internal enum class ImportConfirm { Run, Delete }

internal data class ImportDetailUiState(
    val source: Loadable<EditorialImportSource> = Loadable.Loading,
    val busy: Boolean = false,
    val message: UiText? = null,
    val error: String? = null,
    val confirm: ImportConfirm? = null,
    val confirmError: String? = null,
    /* Eliminata: la schermata torna all'elenco. */
    val deleted: Boolean = false,
    /* Modificata, eseguita o eliminata: l'elenco, tornando indietro, si ricarica. */
    val changed: Boolean = false,
)

/** Una sorgente (R-SY-01…03): i campi, attiva/disattiva, «Esegui adesso», eliminazione con la password recente. */
internal class ImportSourceDetailViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
    saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow(ImportDetailUiState())
    val uiState: StateFlow<ImportDetailUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(source = if (it.source is Loadable.Ready) it.source else Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val loaded = repository.call { source.importSource(id) }
                state.update { it.copy(source = Loadable.Ready(loaded)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(source = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    /** «Attiva» del modulo del pannello, come interruttore: manda solo `is_active`. */
    fun toggleActive() {
        val current = (state.value.source as? Loadable.Ready)?.value ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, message = null, error = null) }
        viewModelScope.launch {
            try {
                val body = JsonObject(mapOf("is_active" to JsonPrimitive(!current.isActive)))
                val result = repository.call { source.updateImportSource(id, body) }
                state.update { it.copy(busy = false, source = Loadable.Ready(result.data), changed = true,
                    message = UiText.Res(if (result.data.isActive) R.string.system_import_activated else R.string.system_import_deactivated)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, error = requestFailureMessage(e)) }
            }
        }
    }

    fun askRun() = state.update { it.copy(confirm = ImportConfirm.Run, confirmError = null, message = null) }
    fun askDelete() = state.update { it.copy(confirm = ImportConfirm.Delete, confirmError = null, message = null) }
    fun dismiss() = state.update { if (it.busy) it else it.copy(confirm = null, confirmError = null) }

    fun confirm() {
        val action = state.value.confirm ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, confirmError = null) }
        viewModelScope.launch {
            try {
                when (action) {
                    ImportConfirm.Run -> {
                        val result = repository.call { source.runImport(id) }
                        state.update { it.copy(busy = false, confirm = null, changed = true, message = messageOf(result.meta.message, R.string.system_import_queued)) }
                    }
                    ImportConfirm.Delete -> {
                        repository.call { source.deleteImportSource(id) }
                        state.update { it.copy(busy = false, confirm = null, deleted = true, changed = true) }
                    }
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, confirmError = requestFailureMessage(e)) }
            }
        }
    }

    /** Dopo il salvataggio del modulo: rilegge la sorgente. */
    fun reloadAfterEdit() {
        state.update { it.copy(changed = true) }
        load()
    }
}

// ----------------------------------------------------------- sorgenti: modulo

/** I campi del modulo del pannello, con i nomi del server. */
internal object ImportField {
    const val CITY = "city_id"
    const val VENUE = "venue_id"
    const val TYPE = "type"
    const val CATEGORY = "default_category_id"
    const val URL = "url"
    const val MAPPING = "mapping"
    const val ACTIVE = "is_active"
    val all = listOf(CITY, VENUE, TYPE, CATEGORY, URL, MAPPING, ACTIVE, "credentials")
}

/** Quello che si scrive nel modulo, senza le credenziali (solo dal sito). */
internal data class ImportDraft(
    val cityId: Long? = null,
    val venue: SystemRef? = null,
    val type: String = "ics",
    val categoryId: Long? = null,
    val url: String = "",
    val mapping: MappingDraft = MappingDraft(),
    val active: Boolean = true,
) {
    companion object {
        fun of(source: EditorialImportSource) = ImportDraft(
            cityId = source.city?.id,
            venue = source.venue,
            type = source.type,
            categoryId = source.defaultCategory?.id,
            url = source.url.orEmpty(),
            mapping = mappingDraft(source.mapping),
            active = source.isActive,
        )

        fun defaults(options: ImportSourceFormOptions) = ImportDraft(cityId = options.defaults.cityId, type = options.defaults.type, active = options.defaults.isActive)
    }
}

/**
 * Gli errori che il modulo vede prima di mandare, con le stesse regole del
 * server (`ImportSourceRules`): città e tipo obbligatori, categoria
 * predefinita obbligatoria senza locale, i tetti dell'indirizzo e della
 * mappatura. Campo del server → testo.
 */
internal fun importDraftProblems(draft: ImportDraft, limits: ImportFormLimits): Map<String, Int> = buildMap {
    if (draft.cityId == null) put(ImportField.CITY, R.string.system_import_error_city)
    if (draft.type.isBlank()) put(ImportField.TYPE, R.string.system_import_error_type)
    if (draft.venue == null && draft.categoryId == null) put(ImportField.CATEGORY, R.string.system_import_error_category)
    if (draft.url.trim().length > limits.maxUrlLength) put(ImportField.URL, R.string.system_import_error_url_long)
    val entries = draft.mapping.entries.filter { it.key.isNotBlank() }
    val keys = entries.map { it.key.trim() }
    if (entries.size + draft.mapping.preserved.size > limits.maxMappingEntries
        || keys.size != keys.toSet().size
        || entries.any { it.key.trim().length > limits.maxMappingKeyLength || it.value.length > limits.maxMappingValueLength }
    ) put(ImportField.MAPPING, R.string.system_import_error_mapping)
}

/**
 * Il corpo da mandare: per una sorgente nuova tutti i campi; per una
 * modifica solo quelli cambiati, così un indirizzo che non si tocca non
 * viene riverificato e un locale tolto arriva come `null` esplicito.
 */
internal fun importDraftBody(draft: ImportDraft, original: EditorialImportSource?): JsonObject {
    val before = original?.let(ImportDraft::of)
    val fields = linkedMapOf<String, JsonElement>()
    fun put(key: String, value: JsonElement, changed: Boolean) { if (before == null || changed) fields[key] = value }
    fun idOf(value: Long?): JsonElement = value?.let { JsonPrimitive(it) } ?: JsonNull
    put(ImportField.CITY, idOf(draft.cityId), draft.cityId != before?.cityId)
    put(ImportField.VENUE, idOf(draft.venue?.id), draft.venue?.id != before?.venue?.id)
    put(ImportField.TYPE, JsonPrimitive(draft.type), draft.type != before?.type)
    put(ImportField.CATEGORY, idOf(draft.categoryId), draft.categoryId != before?.categoryId)
    val url = draft.url.trim()
    put(ImportField.URL, if (url.isEmpty()) JsonNull else JsonPrimitive(url), url != before?.url?.trim())
    val mapping = mappingJson(draft.mapping)
    put(ImportField.MAPPING, mapping ?: JsonNull, before == null || mapping != mappingJson(before.mapping))
    put(ImportField.ACTIVE, JsonPrimitive(draft.active), draft.active != before?.active)
    return JsonObject(fields)
}

internal data class ImportFormUiState(
    val options: Loadable<ImportSourceFormOptions> = Loadable.Loading,
    /* La sorgente com'era, per una modifica; `null` per una nuova. */
    val original: EditorialImportSource? = null,
    val draft: ImportDraft = ImportDraft(),
    val venueQuery: String = "",
    val venueResults: List<ImportVenueChoice> = emptyList(),
    val venueSearching: Boolean = false,
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    /* Salvata: la schermata torna indietro e la scheda si ricarica. */
    val saved: EditorialImportSource? = null,
) {
    val editing: Boolean get() = original != null
    val limits: ImportFormLimits get() = (options as? Loadable.Ready)?.value?.limits ?: ImportFormLimits()
    val problems: Map<String, Int> get() = importDraftProblems(draft, limits)
    val unchanged: Boolean get() = original != null && importDraftBody(draft, original).isEmpty()
    val canSave: Boolean get() = !saving && options is Loadable.Ready && problems.isEmpty() && !unchanged
}

/** Il modulo di una sorgente (R-SY-01): crea (`id` 0) o modifica. Le credenziali non ci sono: si cambiano dal sito. */
internal class ImportSourceFormViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
    saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow(ImportFormUiState())
    val uiState: StateFlow<ImportFormUiState> = state.asStateFlow()
    private var venueJob: Job? = null

    init { load() }

    fun load() {
        state.update { it.copy(options = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val options = repository.call { source.importForm() }
                val original = if (id > 0) repository.call { source.importSource(id) } else null
                state.update { now ->
                    /* Un modulo già toccato resta com'era (nuovo caricamento dopo un errore). */
                    val touched = now.options is Loadable.Failed && now.draft != ImportDraft()
                    now.copy(options = Loadable.Ready(options), original = original,
                        draft = if (touched) now.draft else original?.let(ImportDraft::of) ?: ImportDraft.defaults(options))
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(options = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    private fun edit(field: String, change: (ImportDraft) -> ImportDraft) =
        state.update { it.copy(draft = change(it.draft), errors = it.errors.copy(fields = it.errors.fields - field)) }

    fun setCity(id: Long) = edit(ImportField.CITY) { it.copy(cityId = id) }
    fun setType(value: String) = edit(ImportField.TYPE) { it.copy(type = value) }
    fun setCategory(id: Long?) = edit(ImportField.CATEGORY) { it.copy(categoryId = id) }
    fun setUrl(value: String) = edit(ImportField.URL) { it.copy(url = value) }
    fun setActive(value: Boolean) = edit(ImportField.ACTIVE) { it.copy(active = value) }

    fun chooseVenue(choice: ImportVenueChoice) {
        edit(ImportField.VENUE) { it.copy(venue = SystemRef(choice.id, choice.name)) }
        state.update { it.copy(venueQuery = "", venueResults = emptyList(), errors = it.errors.copy(fields = it.errors.fields - ImportField.CATEGORY)) }
    }

    fun clearVenue() = edit(ImportField.VENUE) { it.copy(venue = null) }

    /** Cerca i locali per nome (la pausa prima di chiedere la fa la schermata); una ricerca nuova annulla quella in corso. */
    fun searchVenues(query: String) {
        state.update { it.copy(venueQuery = query) }
        venueJob?.cancel()
        if (query.isBlank()) {
            state.update { it.copy(venueResults = emptyList(), venueSearching = false) }
            return
        }
        state.update { it.copy(venueSearching = true) }
        venueJob = viewModelScope.launch {
            try {
                val found = repository.call { source.importVenues(query) }
                state.update { if (it.venueQuery == query) it.copy(venueResults = found, venueSearching = false) else it }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(venueSearching = false, errors = it.errors.copy(general = requestFailureMessage(e))) }
            }
        }
    }

    fun setVenueQuery(query: String) = state.update { it.copy(venueQuery = query) }

    fun addMapping() = edit(ImportField.MAPPING) { it.copy(mapping = it.mapping.copy(entries = it.mapping.entries + MappingEntry())) }
    fun setMapping(index: Int, entry: MappingEntry) = edit(ImportField.MAPPING) { draft ->
        draft.copy(mapping = draft.mapping.copy(entries = draft.mapping.entries.mapIndexed { i, e -> if (i == index) entry else e }))
    }
    fun removeMapping(index: Int) = edit(ImportField.MAPPING) { draft ->
        draft.copy(mapping = draft.mapping.copy(entries = draft.mapping.entries.filterIndexed { i, _ -> i != index }))
    }

    fun save() {
        val now = state.value
        if (!now.canSave) return
        state.update { it.copy(saving = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val body = importDraftBody(now.draft, now.original)
                val result = repository.call { if (now.original == null) source.createImportSource(body) else source.updateImportSource(now.original.id, body) }
                state.update { it.copy(saving = false, saved = result.data, original = result.data, draft = ImportDraft.of(result.data)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(saving = false, errors = FormErrors.from(e, ImportField.all)) }
            }
        }
    }
}

// --------------------------------------------------------- sorgenti: anteprima

/** L'anteprima (R-SY-02): le prime date che entrerebbero, senza scrivere niente. */
internal class ImportPreviewViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
    saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow<Loadable<ImportPreview>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<ImportPreview>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            state.value = try {
                Loadable.Ready(repository.call { source.previewImport(id) })
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
        }
    }
}

// ---------------------------------------------------------- sorgenti: storico

/** Lo storico delle esecuzioni (R-SY-04): solo lettura, filtro «Esito», ordine per data. */
internal class ImportRunsViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    val list = PagedListController(viewModelScope, ListQuery(filters = decodeFilters(saved[KEY_FILTERS], FILTERS), sort = saved.get<String>(KEY_SORT)?.takeIf { it in SORTS })) { query, page ->
        repository.call { source.importRuns(id, query.params(page)) }
    }
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch { loadOptions(repository, optionsState) }
    }

    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_FILTERS] = encodeFilters(filters)
        list.applyFilters(filters)
    }

    fun sortBy(sort: String?) {
        saved[KEY_SORT] = sort
        list.sortBy(sort)
    }

    companion object {
        const val FILTER_STATUS = "status"
        val FILTERS = setOf(FILTER_STATUS)
        const val SORT_OLDEST = "created_at"
        val SORTS = setOf(SORT_OLDEST)
    }
}

// ------------------------------------------------------------ invii previsti

internal data class NotificationsUiState(
    val confirming: ScheduledNotificationRow? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val message: UiText? = null,
)

/**
 * Gli invii previsti (R-SY-05, R-SY-06): filtri stato, tipo, «in scadenza»;
 * ricerca sull'email del destinatario; ordine per orario di invio. «Annulla
 * questo invio» solo dove la riga lo permette (admin e super admin, invio in
 * attesa); la riga resta, con lo stato «annullata».
 */
internal class ScheduledNotificationsViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, initialQuery(saved, LIST, FILTERS, SORTS)) { query, page ->
        repository.call { source.scheduledNotifications(query.params(page)) }
    }
    private val state = MutableStateFlow(NotificationsUiState())
    val uiState: StateFlow<NotificationsUiState> = state.asStateFlow()
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch { loadOptions(repository, optionsState) }
    }

    fun search(q: String) {
        saved[KEY_QUERY] = q
        list.search(q)
    }

    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_FILTERS] = encodeFilters(filters)
        list.applyFilters(filters)
    }

    fun sortBy(sort: String?) {
        saved[KEY_SORT] = sort
        list.sortBy(sort)
    }

    fun askCancel(row: ScheduledNotificationRow) = state.update { it.copy(confirming = row, error = null, message = null) }
    fun dismiss() = state.update { if (it.busy) it else it.copy(confirming = null, error = null) }

    fun cancel() {
        val row = state.value.confirming ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.cancelNotification(row.id) }
                list.replace({ it.id == row.id }, result.data)
                state.update { it.copy(busy = false, confirming = null, message = messageOf(result.meta.message, R.string.system_notification_cancelled)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, error = requestFailureMessage(e)) }
            }
        }
    }

    companion object {
        const val LIST = "scheduled_notifications"
        const val FILTER_STATUS = "status"
        const val FILTER_TYPE = "type"
        const val FILTER_DUE = "due"
        val FILTERS = setOf(FILTER_STATUS, FILTER_TYPE, FILTER_DUE)
        /* «Orario di invio» si ordina; il predefinito è dal più lontano nel futuro. */
        const val SORT_EARLIEST = "send_at"
        val SORTS = setOf(SORT_EARLIEST)
    }
}

// ---------------------------------------------------------------------- cron

/** Cron e programmazioni (R-SY-07): sola lettura. */
internal class ScheduledOperationsViewModel(
    private val repository: EditorialRepository,
    private val source: SystemSource,
) : ViewModel() {
    private val state = MutableStateFlow<Loadable<SchedulerOverviewData>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<SchedulerOverviewData>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            state.value = try {
                Loadable.Ready(repository.call { source.scheduledOperations() })
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
        }
    }
}
