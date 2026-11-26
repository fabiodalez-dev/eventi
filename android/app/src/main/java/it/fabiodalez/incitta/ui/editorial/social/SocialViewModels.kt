package it.fabiodalez.incitta.ui.editorial.social

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialSocialBatch
import it.fabiodalez.incitta.data.editorial.EditorialSocialSource
import it.fabiodalez.incitta.data.editorial.SocialPublicationRow
import it.fabiodalez.incitta.data.editorial.SocialResult
import it.fabiodalez.incitta.data.editorial.SocialSettings
import it.fabiodalez.incitta.data.editorial.SocialSettingsValues
import it.fabiodalez.incitta.data.editorial.SocialToday
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import it.fabiodalez.incitta.ui.management.social.SocialFileUse
import it.fabiodalez.incitta.ui.management.social.SocialPendingFile
import java.io.File
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * R9 — le altre schermate del Social della redazione: un pacchetto con la
 * pubblicazione (R-SO-03, R-SO-04), lo storico delle pubblicazioni, le
 * impostazioni (R-SO-05…07) e «Social di oggi» (R-DB-10). Ogni chiamata
 * passa da `repository.call { }`.
 */

/** La data e ora della città nel formato del campo della pagina (`Y-m-dTH:i`). */
internal fun socialLocalParam(value: LocalDateTime): String = value.format(LOCAL_PARAM)

private val LOCAL_PARAM: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm")

private fun messageOf(text: String?): UiText? = text?.takeIf { it.isNotBlank() }?.let(UiText::Raw)

// ---------------------------------------------------------------- un pacchetto

internal data class EditorialBatchUiState(
    val batch: Loadable<EditorialSocialBatch> = Loadable.Loading,
    /* L'immagine (o lo ZIP, -1) che si sta scaricando. */
    val downloading: Int? = null,
    val file: SocialPendingFile? = null,
    val publishing: Boolean = false,
    /* La data e ora scelte per «Programma», nell'ora della città. */
    val scheduledAt: LocalDateTime? = null,
    val history: List<SocialPublicationRow> = emptyList(),
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
    val error: UiText? = null,
) {
    val busy: Boolean get() = downloading != null || publishing
}

/**
 * Un pacchetto preparato: immagini, «Salva nella galleria», «Condividi»,
 * didascalia, ZIP, e per la redazione il testo automatico, «Pubblica sui
 * canali collegati» e «Programma».
 */
internal class EditorialSocialBatchViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialSocialSource,
    saved: SavedStateHandle,
) : ViewModel() {
    val batchId: String = saved["id"] ?: ""
    private val state = MutableStateFlow(EditorialBatchUiState())
    val uiState: StateFlow<EditorialBatchUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        if (batchId.isBlank()) { state.update { it.copy(batch = Loadable.Empty) }; return }
        state.update { it.copy(batch = if (it.batch is Loadable.Ready) it.batch else Loadable.Loading) }
        viewModelScope.launch {
            try {
                val batch = repository.call { source.batch(batchId) }
                state.update { it.copy(batch = Loadable.Ready(batch)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(batch = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** Scarica un'immagine del pacchetto nella cache, per salvarla o condividerla. */
    fun image(item: SocialBatchItem, use: SocialFileUse, cacheDir: File) {
        if (state.value.busy) return
        state.update { it.copy(downloading = item.index, message = null, error = null) }
        viewModelScope.launch {
            try {
                runCatching { ManagementFiles.purge(cacheDir) }
                val file = repository.call { source.image(batchId, item, ManagementFiles.directory(cacheDir)) }
                state.update { it.copy(file = SocialPendingFile(file, use, item.filename.substringBeforeLast('.'))) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
            finally { state.update { it.copy(downloading = null) } }
        }
    }

    /** Lo ZIP con immagini e didascalie, da condividere o aprire con un'altra app. */
    fun zip(use: SocialFileUse, cacheDir: File) {
        val batch = state.value.batch.valueOrNull ?: return
        if (state.value.busy) return
        state.update { it.copy(downloading = ZIP, message = null, error = null) }
        viewModelScope.launch {
            try {
                runCatching { ManagementFiles.purge(cacheDir) }
                val file = repository.call { source.zip(batch, ManagementFiles.directory(cacheDir)) }
                state.update { it.copy(file = SocialPendingFile(file, use, file.file.nameWithoutExtension)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
            finally { state.update { it.copy(downloading = null) } }
        }
    }

    fun fileHandled(message: UiText?, error: UiText? = null) {
        state.update { it.copy(file = null, message = message, error = error) }
    }

    fun captionCopied() { state.update { it.copy(message = UiText.Res(R.string.social_caption_copied)) } }

    fun setScheduledAt(value: LocalDateTime?) {
        state.update { it.copy(scheduledAt = value, errors = it.errors.copy(fields = it.errors.fields - "scheduled_at")) }
    }

    /** «Pubblica sui canali collegati», dopo la conferma della schermata. */
    fun publish() = send(FIELD_PUBLISH) { source.publish(batchId) }

    /** «Programma» con la data e ora scelte. */
    fun schedule() {
        val at = state.value.scheduledAt
        if (at == null) {
            state.update { it.copy(errors = FormErrors(fields = mapOf(FIELD_SCHEDULE to SCHEDULE_REQUIRED))) }
            return
        }
        send(FIELD_SCHEDULE) { source.schedule(batchId, socialLocalParam(at)) }
    }

    private fun send(field: String, call: suspend () -> SocialResult<List<SocialPublicationRow>>) {
        if (state.value.busy) return
        state.update { it.copy(publishing = true, errors = FormErrors.None, message = null, error = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { call() }
                state.update {
                    it.copy(
                        history = result.data,
                        message = messageOf(result.meta.message),
                        batch = (it.batch as? Loadable.Ready)?.let { ready -> Loadable.Ready(ready.value.copy(inHistory = true)) } ?: it.batch,
                    )
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, listOf(field))) } }
            finally { state.update { it.copy(publishing = false) } }
        }
    }

    companion object {
        const val ZIP = -1
        const val FIELD_PUBLISH = "publish"
        const val FIELD_SCHEDULE = "scheduled_at"
        const val SCHEDULE_REQUIRED = "Scegli la data e l'ora della pubblicazione."
    }
}

// ---------------------------------------------------------------- storico

/** La data e ora che si sta scegliendo per «Usa la nuova data e ora» di una riga. */
internal data class RescheduleDraft(val row: SocialPublicationRow, val value: LocalDateTime?)

internal data class PublicationsUiState(
    val busyId: Long? = null,
    val reschedule: RescheduleDraft? = null,
    val retryConfirm: SocialPublicationRow? = null,
    val message: UiText? = null,
    val error: UiText? = null,
)

/**
 * Lo storico delle pubblicazioni della città (la sezione «Storico
 * pubblicazioni» della pagina), con riprogramma, annulla e riprova secondo
 * le `abilities` della riga.
 */
internal class SocialPublicationsViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialSocialSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, ListQuery(filters = initialFilters(saved), perPage = PER_PAGE)) { query, page ->
        repository.call { source.publications(repository.selectedCity, query.params(page)) }
    }
    private val state = MutableStateFlow(PublicationsUiState())
    val uiState: StateFlow<PublicationsUiState> = state.asStateFlow()

    init { list.refresh() }

    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_STATUS] = filters["status"]
        list.applyFilters(filters)
    }

    fun startReschedule(row: SocialPublicationRow, initial: LocalDateTime?) { state.update { it.copy(reschedule = RescheduleDraft(row, initial), error = null, message = null) } }
    fun setReschedule(value: LocalDateTime?) { state.update { it.copy(reschedule = it.reschedule?.copy(value = value)) } }
    fun dismissReschedule() { state.update { it.copy(reschedule = null) } }

    fun confirmReschedule() {
        val draft = state.value.reschedule ?: return
        val value = draft.value ?: return
        state.update { it.copy(reschedule = null) }
        act(draft.row.id) { source.reschedule(draft.row.id, socialLocalParam(value)) }
    }

    fun cancel(row: SocialPublicationRow) = act(row.id) { source.cancel(row.id) }

    fun askRetry(row: SocialPublicationRow) { state.update { it.copy(retryConfirm = row) } }
    fun dismissRetry() { state.update { it.copy(retryConfirm = null) } }

    fun confirmRetry() {
        val row = state.value.retryConfirm ?: return
        state.update { it.copy(retryConfirm = null) }
        act(row.id) { source.retry(row.id) }
    }

    private fun act(id: Long, call: suspend () -> SocialResult<SocialPublicationRow>) {
        if (state.value.busyId != null) return
        state.update { it.copy(busyId = id, message = null, error = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { call() }
                list.replace({ it.id == id }, result.data)
                state.update { it.copy(message = messageOf(result.meta.message)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val errors = FormErrors.from(e, emptyList())
                state.update { it.copy(error = UiText.Raw(errors.general ?: requestFailureMessage(e))) }
            }
            finally { state.update { it.copy(busyId = null) } }
        }
    }

    companion object {
        const val PER_PAGE = 30
        const val KEY_STATUS = "publications_status"
        val STATUSES = listOf("scheduled", "cancelled", "queued", "processing", "published", "failed", "uncertain")

        private fun initialFilters(saved: SavedStateHandle): Map<String, String> =
            saved.get<String>(KEY_STATUS)?.takeIf { it in STATUSES }?.let { mapOf("status" to it) } ?: emptyMap()
    }
}

// ---------------------------------------------------------------- impostazioni

internal data class SettingsUiState(
    val settings: Loadable<SocialSettings> = Loadable.Loading,
    /* Il modulo come lo si sta scrivendo; `null` finché non arrivano i valori salvati. */
    val draft: SocialSettingsValues? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
    val warning: UiText? = null,
    /* L'indirizzo del collegamento Meta da aprire nel browser: la schermata lo apre e poi lo consuma. */
    val openUrl: String? = null,
) {
    val dirty: Boolean get() = draft != null && draft != settings.valueOrNull?.values
}

/**
 * «Account e pubblicazione social». Come nella pagina, «Verifica
 * collegamento», «Verifica Telegram» e «Collega con Meta» salvano prima il
 * modulo; i segreti non si scrivono dall'app.
 */
internal class SocialSettingsViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialSocialSource,
) : ViewModel() {
    private val state = MutableStateFlow(SettingsUiState())
    val uiState: StateFlow<SettingsUiState> = state.asStateFlow()

    init { load() }

    /** Rilegge le impostazioni; il modulo resta com'è se ci sono modifiche non salvate (ritorno dal browser). */
    fun load() {
        viewModelScope.launch {
            try {
                val settings = repository.call { source.settings() }
                state.update { it.copy(settings = Loadable.Ready(settings), draft = if (it.dirty) it.draft else settings.values) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                state.update { it.copy(settings = if (it.settings is Loadable.Ready) it.settings else Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun edit(values: SocialSettingsValues) {
        state.update { it.copy(draft = values, errors = FormErrors.None, message = null) }
    }

    fun save() = run(alsoSave = true) { null }

    fun verifyMeta() = run(alsoSave = true) { source.verifyMeta() }

    fun verifyTelegram() = run(alsoSave = true) { source.verifyTelegram() }

    fun disconnectMeta() = run(alsoSave = false) { source.disconnectMeta() }

    /** «Collega con Meta»: salva, poi chiede l'indirizzo del sito da aprire nel browser. */
    fun connectMeta() {
        if (state.value.busy) return
        val draft = state.value.draft ?: return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null, warning = null) }
        viewModelScope.launch {
            try {
                val saved = repository.call { source.saveSettings(draft) }
                applySaved(saved.data, saved.meta.message, saved.meta.warning)
                val link = repository.call { source.metaLink() }
                state.update { it.copy(openUrl = link.url.takeIf(String::isNotBlank)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    fun consumeOpenUrl() { state.update { it.copy(openUrl = null) } }

    /**
     * Salva il modulo (se `alsoSave`) e poi fa l'azione; ogni passo che non
     * riesce ferma i successivi, come `$this->save()` in testa alle azioni
     * della pagina.
     */
    private fun run(alsoSave: Boolean, action: suspend () -> SocialResult<SocialSettings>?) {
        if (state.value.busy) return
        val draft = state.value.draft
        if (alsoSave && draft == null) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null, warning = null) }
        viewModelScope.launch {
            try {
                if (alsoSave && draft != null) {
                    val saved = repository.call { source.saveSettings(draft) }
                    applySaved(saved.data, saved.meta.message, saved.meta.warning)
                }
                val result = repository.call { action() }
                if (result != null) applySaved(result.data, result.meta.message, result.meta.warning)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun applySaved(settings: SocialSettings, message: String?, warning: String?) {
        state.update {
            it.copy(
                settings = Loadable.Ready(settings),
                draft = settings.values,
                message = messageOf(message),
                warning = messageOf(warning) ?: it.warning,
            )
        }
    }

    companion object {
        /* I campi che il modulo mostra con l'errore sotto; `verify`, `telegram` e `meta` sono le tre azioni. */
        val FIELDS = listOf(
            "app_id", "city_id", "page_id", "instagram_id", "graph_version", "telegram_chat_id", "publish_time", "caption", "graphic_options",
            "verify", "telegram", "meta",
        )
    }
}

// ---------------------------------------------------------------- social di oggi

internal data class TodayUiState(
    val today: Loadable<SocialToday> = Loadable.Loading,
    val preparing: Boolean = false,
    val error: String? = null,
    val prepared: String? = null,
)

/** «Social di oggi» del riepilogo: le serate di oggi e «Scarica le grafiche di oggi». */
internal class SocialTodayViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialSocialSource,
) : ViewModel() {
    private val state = MutableStateFlow(TodayUiState())
    val uiState: StateFlow<TodayUiState> = state.asStateFlow()
    val desk: StateFlow<EditorialDesk?> = repository.current

    init { load() }

    fun load() {
        state.update { it.copy(today = if (it.today is Loadable.Ready) it.today else Loadable.Loading) }
        viewModelScope.launch {
            try {
                val today = repository.call { source.today(repository.selectedCity) }
                state.update { it.copy(today = if (today.data.isEmpty()) Loadable.Empty else Loadable.Ready(today)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(today = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun selectCity(slug: String) {
        if (slug == repository.selectedCity) return
        viewModelScope.launch {
            try { repository.selectCity(slug) } catch (e: CancellationException) { throw e } catch (_: Exception) { /* resta la città di prima */ }
            state.update { it.copy(today = Loadable.Loading) }
            load()
        }
    }

    /** Prepara il pacchetto verticale con tutte le serate di oggi; la schermata lo apre per lo ZIP. */
    fun prepare() {
        if (state.value.preparing) return
        state.update { it.copy(preparing = true, error = null) }
        viewModelScope.launch {
            try {
                val batch = repository.call { source.prepareToday(repository.selectedCity) }
                state.update { it.copy(prepared = batch.id) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val errors = FormErrors.from(e, listOf("generate"))
                state.update { it.copy(error = errors.field("generate") ?: errors.general ?: requestFailureMessage(e)) }
            }
            finally { state.update { it.copy(preparing = false) } }
        }
    }

    fun consumePrepared() { state.update { it.copy(prepared = null) } }
}
