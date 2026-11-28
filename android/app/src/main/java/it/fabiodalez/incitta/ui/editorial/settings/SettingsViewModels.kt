package it.fabiodalez.incitta.ui.editorial.settings

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.editorial.CacheSettingsView
import it.fabiodalez.incitta.data.editorial.ConsentCategoryInfo
import it.fabiodalez.incitta.data.editorial.ConsentCategoryValue
import it.fabiodalez.incitta.data.editorial.ConsentScriptRow
import it.fabiodalez.incitta.data.editorial.CookieBody
import it.fabiodalez.incitta.data.editorial.CookieLimits
import it.fabiodalez.incitta.data.editorial.CookieRow
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EmailText
import it.fabiodalez.incitta.data.editorial.EmailTextVariable
import it.fabiodalez.incitta.data.editorial.EmailTexts
import it.fabiodalez.incitta.data.editorial.MailSettingsView
import it.fabiodalez.incitta.data.editorial.MailTestResult
import it.fabiodalez.incitta.data.editorial.NewsletterBody
import it.fabiodalez.incitta.data.editorial.NewsletterFields
import it.fabiodalez.incitta.data.editorial.NewsletterSettingsView
import it.fabiodalez.incitta.data.editorial.RedirectBody
import it.fabiodalez.incitta.data.editorial.RedirectLimits
import it.fabiodalez.incitta.data.editorial.RedirectOptions
import it.fabiodalez.incitta.data.editorial.RedirectRow
import it.fabiodalez.incitta.data.editorial.SettingsSource
import it.fabiodalez.incitta.data.editorial.insertTextVariable
import it.fabiodalez.incitta.data.editorial.isInternalRedirectTarget
import it.fabiodalez.incitta.data.editorial.movedCookieOrder
import it.fabiodalez.incitta.data.editorial.unknownEmailVariables
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * R13 — Configurazione tecnica (R-SY-08…14), come le pagine del gruppo
 * «Sistema» del pannello più la Newsletter. Chi vede quale voce lo dicono i
 * `permissions` del banco; il server ricontrolla ogni chiamata con le stesse
 * regole del pannello. Ogni chiamata passa da `repository.call { }`: con un
 * 423 compare la richiesta della password e la chiamata riparte.
 *
 * Decisione 5 del piano: lettura e azioni operative nell'app; SMTP, script di
 * consenso e impostazioni della cache e di Redis si scrivono solo dal sito
 * («Modifica sul sito» apre la pagina del pannello).
 */

// ------------------------------------------------------------------ sezioni

/** Le voci dell'area, con il permesso del banco che le mostra e la pagina del pannello. */
internal enum class SettingsSection(val permission: String, val title: Int, val help: Int, val webPath: String) {
    Texts("system_manage", R.string.config_texts_title, R.string.config_texts_help, "admin/testi-delle-email"),
    Mail("mail_manage", R.string.config_mail_title, R.string.config_mail_help, "admin/configurazione-posta"),
    Scripts("system_manage", R.string.config_scripts_title, R.string.config_scripts_help, "admin/script-consenso"),
    Cookies("system_manage", R.string.config_cookies_title, R.string.config_cookies_help, "admin/cookie"),
    Redirects("pages_manage", R.string.config_redirects_title, R.string.config_redirects_help, "admin/indirizzi-cambiati"),
    Cache("system_manage", R.string.config_cache_title, R.string.config_cache_help, "admin/cache-performance"),
    Newsletter("mail_manage", R.string.config_newsletter_title, R.string.config_newsletter_help, "admin/newsletter"),
}

/** Le voci che la persona vede: posta e newsletter solo il super admin, come nel pannello. */
internal fun settingsSections(can: EditorialPermissions): List<SettingsSection> =
    SettingsSection.entries.filter { can.allows(it.permission) }

private fun messageOf(text: String?, fallback: Int): UiText = text?.takeIf { it.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(fallback)

/** Vero quando il server rifiuta perché il testo è cambiato nel frattempo (`error.fields.revision`). */
internal fun isSettingsConflict(error: Throwable): Boolean =
    error is ApiException && error.status == 422 && error.problem?.fields?.containsKey("revision") == true

/** La prima schermata dell'area: le voci permesse, rilette entrando. */
internal class SettingsHubViewModel(private val repository: EditorialRepository) : ViewModel() {
    val desk: StateFlow<EditorialDesk?> = repository.current

    init { refresh() }

    fun refresh() {
        viewModelScope.launch {
            try { repository.loadDesk() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* resta il banco di prima */ }
        }
    }
}

// ------------------------------------------------------------ testi email

internal data class EmailTextsUiState(
    val texts: Loadable<EmailTexts> = Loadable.Loading,
    /* La scheda del pannello: un gruppo alla volta. */
    val group: String? = null,
    val confirmingReset: Boolean = false,
    val resetting: Boolean = false,
    val resetError: String? = null,
    val message: UiText? = null,
)

/** I testi delle email per gruppo (R-SY-08), con «Riporta tutto all'originale». */
internal class EmailTextsViewModel(
    private val repository: EditorialRepository,
    private val source: SettingsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(EmailTextsUiState(group = saved[KEY_GROUP]))
    val uiState: StateFlow<EmailTextsUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        if (state.value.texts !is Loadable.Ready) state.update { it.copy(texts = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val texts = repository.call { source.texts() }
                state.update { now -> now.copy(texts = Loadable.Ready(texts), group = now.group?.takeIf { key -> texts.groups.any { it.key == key } } ?: texts.groups.firstOrNull()?.key) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(texts = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun selectGroup(key: String) {
        saved[KEY_GROUP] = key
        state.update { it.copy(group = key, message = null) }
    }

    fun askReset() = state.update { it.copy(confirmingReset = true, resetError = null, message = null) }
    fun dismissReset() = state.update { if (it.resetting) it else it.copy(confirmingReset = false, resetError = null) }

    fun reset() {
        if (state.value.resetting) return
        state.update { it.copy(resetting = true, resetError = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.resetTexts() }
                state.update { it.copy(resetting = false, confirmingReset = false, texts = Loadable.Ready(result.data), message = messageOf(result.meta.message, R.string.config_texts_reset_done)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(resetting = false, resetError = requestFailureMessage(e)) }
            }
        }
    }

    companion object {
        private const val KEY_GROUP = "group"
    }
}

internal data class EmailTextEditUiState(
    val text: Loadable<EmailText> = Loadable.Loading,
    val value: String = "",
    /* L'esempio del server per il testo scritto adesso. */
    val preview: String = "",
    val previewLoading: Boolean = false,
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
    /* Salvato: l'elenco, tornando indietro, si ricarica. */
    val changed: Boolean = false,
) {
    private val current: EmailText? get() = (text as? Loadable.Ready)?.value
    /* I segnaposti che l'originale non ha: il server li rifiuterebbe. */
    val unknown: List<String> get() = current?.let { unknownEmailVariables(value, it.default) } ?: emptyList()
    val dirty: Boolean get() = current != null && value != current?.value
    val canSave: Boolean get() = current != null && !saving && unknown.isEmpty()
}

/**
 * Un testo delle email: il campo semplice della decisione 7, i pulsanti
 * «Inserisci» dei segnaposti, l'esempio reso dal server, l'originale, il
 * salvataggio con la revisione letta. Svuotato o uguale all'originale torna
 * all'originale, come nel pannello.
 */
internal class EmailTextEditViewModel(
    private val repository: EditorialRepository,
    private val source: SettingsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val key: String = saved.get<String>(KEY_KEY).orEmpty()
    private val state = MutableStateFlow(EmailTextEditUiState(value = saved[KEY_DRAFT] ?: ""))
    val uiState: StateFlow<EmailTextEditUiState> = state.asStateFlow()
    private var previewJob: Job? = null

    init { load() }

    fun load() {
        state.update { it.copy(text = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val text = repository.call { source.texts() }.text(key)
                if (text == null) {
                    state.update { it.copy(text = Loadable.Empty) }
                    return@launch
                }
                val draft = saved.get<String>(KEY_DRAFT)
                state.update { it.copy(text = Loadable.Ready(text), value = draft ?: text.value, preview = text.preview) }
                if (draft != null && draft != text.value) schedulePreview(draft, immediate = true)
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(text = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun setValue(value: String) {
        saved[KEY_DRAFT] = value
        state.update { it.copy(value = value, message = null, errors = FormErrors.None) }
        schedulePreview(value)
    }

    fun insert(variable: EmailTextVariable) = setValue(insertTextVariable(state.value.value, variable))

    /** Rimette nel campo il testo di partenza: salvando, la riscrittura sparisce. */
    fun restoreDefault() {
        val text = (state.value.text as? Loadable.Ready)?.value ?: return
        setValue(text.default)
    }

    /** L'esempio si chiede al server dopo una pausa nella scrittura, una richiesta alla volta. */
    private fun schedulePreview(value: String, immediate: Boolean = false) {
        previewJob?.cancel()
        previewJob = viewModelScope.launch {
            if (!immediate) delay(PREVIEW_PAUSE_MILLIS)
            state.update { it.copy(previewLoading = true) }
            try {
                val preview = repository.call { source.previewText(key, value) }
                state.update { now -> if (now.value == value) now.copy(preview = preview.preview, previewLoading = false) else now }
            } catch (e: CancellationException) { throw e } catch (_: Exception) {
                /* L'esempio non è indispensabile: resta quello di prima. */
                state.update { it.copy(previewLoading = false) }
            }
        }
    }

    fun save() {
        val now = state.value
        val text = (now.text as? Loadable.Ready)?.value ?: return
        if (!now.canSave) return
        state.update { it.copy(saving = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.updateText(key, now.value, text.revision) }
                saved.remove<String>(KEY_DRAFT)
                state.update { it.copy(saving = false, text = Loadable.Ready(result.data), value = result.data.value, preview = result.data.preview,
                    message = messageOf(result.meta.message, R.string.config_saved), changed = true) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                if (isSettingsConflict(e)) {
                    /* Qualcuno l'ha riscritto nel frattempo: si rilegge, la bozza resta nel campo. */
                    state.update { it.copy(saving = false, errors = FormErrors(general = null), message = UiText.Res(R.string.config_texts_changed)) }
                    reloadKeepingDraft()
                } else {
                    state.update { it.copy(saving = false, errors = FormErrors.from(e, listOf(FIELD_VALUE))) }
                }
            }
        }
    }

    private fun reloadKeepingDraft() {
        viewModelScope.launch {
            try {
                val text = repository.call { source.texts() }.text(key) ?: return@launch
                state.update { it.copy(text = Loadable.Ready(text), errors = FormErrors.None) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) { /* la prossima prova rilegge */ }
        }
    }

    companion object {
        const val KEY_KEY = "key"
        private const val KEY_DRAFT = "draft"
        const val FIELD_VALUE = "value"
        const val PREVIEW_PAUSE_MILLIS = 600L
    }
}

// ------------------------------------------------------------------ posta

internal data class MailUiState(
    val mail: Loadable<MailSettingsView> = Loadable.Loading,
    val confirmingTest: Boolean = false,
    val testing: Boolean = false,
    val result: MailTestResult? = null,
    val error: String? = null,
)

/** La posta (R-SY-09), solo super admin: lettura e messaggio di prova con la configurazione salvata. */
internal class MailSettingsViewModel(private val repository: EditorialRepository, private val source: SettingsSource) : ViewModel() {
    private val state = MutableStateFlow(MailUiState())
    val uiState: StateFlow<MailUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(mail = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val mail = repository.call { source.mail() }
                state.update { it.copy(mail = Loadable.Ready(mail)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(mail = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun askTest() = state.update { it.copy(confirmingTest = true, error = null) }
    fun dismissTest() = state.update { if (it.testing) it else it.copy(confirmingTest = false) }

    fun sendTest() {
        if (state.value.testing) return
        state.update { it.copy(testing = true, error = null, result = null) }
        viewModelScope.launch {
            try {
                val outcome = repository.call { source.testMail() }.data
                state.update { it.copy(testing = false, confirmingTest = false, result = outcome.result, mail = Loadable.Ready(outcome.mail)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(testing = false, confirmingTest = false, error = FormErrors.from(e, emptyList()).general ?: requestFailureMessage(e)) }
            }
        }
    }
}

// ------------------------------------------------------------- newsletter

/** Il modulo della newsletter com'è scritto adesso. */
internal data class NewsletterDraft(
    val enabled: Boolean = false,
    val weekday: Int = 5,
    val time: String = "",
    val maxItems: String = "",
    val texts: Map<String, String> = emptyMap(),
) {
    fun text(field: String): String = texts[field].orEmpty()

    /** Le stesse regole dei campi del pannello: ora `HH:MM`, eventi da 1 a 30, testi entro i tetti. */
    fun problems(view: NewsletterSettingsView): Set<String> = buildSet {
        if (!NewsletterFields.TIME.matches(time)) add("time")
        val max = maxItems.toIntOrNull()
        if (max == null || max < view.limits.maxItemsMin || max > view.limits.maxItemsMax) add("max_items")
        if (weekday !in 1..7) add("weekday")
        NewsletterFields.texts.forEach { field ->
            val limit = view.texts[field]?.max ?: Int.MAX_VALUE
            val default = view.texts[field]?.default.orEmpty()
            if (text(field).length > limit || unknownEmailVariables(text(field), default).isNotEmpty()) add(field)
        }
    }

    fun body(): NewsletterBody = NewsletterBody(
        enabled = enabled,
        weekday = weekday,
        time = time.trim(),
        maxItems = maxItems.trim().toIntOrNull() ?: 0,
        subject = text(NewsletterFields.SUBJECT).trim().ifEmpty { null },
        heading = text(NewsletterFields.HEADING).trim().ifEmpty { null },
        line = text(NewsletterFields.LINE).trim().ifEmpty { null },
        action = text(NewsletterFields.ACTION).trim().ifEmpty { null },
    )

    companion object {
        fun from(view: NewsletterSettingsView) = NewsletterDraft(
            enabled = view.enabled,
            weekday = view.weekday,
            time = view.time,
            maxItems = view.maxItems.toString(),
            texts = NewsletterFields.texts.associateWith { view.texts[it]?.value.orEmpty() },
        )
    }
}

internal data class NewsletterUiState(
    val view: Loadable<NewsletterSettingsView> = Loadable.Loading,
    val draft: NewsletterDraft = NewsletterDraft(),
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
) {
    private val current: NewsletterSettingsView? get() = (view as? Loadable.Ready)?.value
    val problems: Set<String> get() = current?.let(draft::problems) ?: emptySet()
    val canSave: Boolean get() = current != null && !saving && problems.isEmpty()
}

/** La newsletter (R-SY-10), solo super admin: interruttore, giorno, ora, numero di eventi e i quattro testi. */
internal class NewsletterViewModel(private val repository: EditorialRepository, private val source: SettingsSource) : ViewModel() {
    private val state = MutableStateFlow(NewsletterUiState())
    val uiState: StateFlow<NewsletterUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(view = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val view = repository.call { source.newsletter() }
                state.update { it.copy(view = Loadable.Ready(view), draft = NewsletterDraft.from(view)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(view = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun edit(change: (NewsletterDraft) -> NewsletterDraft) = state.update { it.copy(draft = change(it.draft), message = null) }
    fun setText(field: String, value: String) = edit { it.copy(texts = it.texts + (field to value)) }
    fun insert(field: String, variable: EmailTextVariable) = edit { it.copy(texts = it.texts + (field to insertTextVariable(it.text(field), variable))) }

    fun save() {
        val now = state.value
        if (!now.canSave) return
        state.update { it.copy(saving = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.saveNewsletter(now.draft.body()) }
                state.update { it.copy(saving = false, view = Loadable.Ready(result.data), draft = NewsletterDraft.from(result.data), message = messageOf(result.meta.message, R.string.config_saved)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(saving = false, errors = FormErrors.from(e, FIELDS)) }
            }
        }
    }

    companion object {
        val FIELDS = listOf("enabled", "weekday", "time", "max_items") + NewsletterFields.texts
    }
}

// ----------------------------------------------------- script di consenso

/** Gli script di consenso (R-SY-11), in sola lettura: ricerca sul nome, ordine di esecuzione. */
internal class ScriptsViewModel(
    private val repository: EditorialRepository,
    private val source: SettingsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, ListQuery(q = saved.get<String>(KEY_QUERY).orEmpty())) { query, page ->
        repository.call { source.scripts(query.params(page)) }
    }

    init { list.refresh() }

    fun search(q: String) {
        saved[KEY_QUERY] = q
        list.search(q)
    }

    companion object {
        private const val KEY_QUERY = "q"
    }
}

/** Uno script con il suo codice, da leggere. */
internal class ScriptDetailViewModel(
    private val repository: EditorialRepository,
    private val source: SettingsSource,
    saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow<Loadable<ConsentScriptRow>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<ConsentScriptRow>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            try {
                state.value = Loadable.Ready(repository.call { source.script(id) })
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.value = Loadable.Failed(requestFailureMessage(e))
            }
        }
    }

    companion object {
        const val KEY_ID = "id"
    }
}

// ------------------------------------------------------------------ cookie

/** Il modulo «Aggiungi un cookie». */
internal data class CookieDraft(
    val category: String = ConsentCategoryValue.NECESSARY,
    val name: String = "",
    val provider: String = "",
    val purpose: String = "",
    val duration: String = "",
) {
    val valid: Boolean get() = name.isNotBlank() && purpose.isNotBlank() && name.length <= CookieLimits.NAME_MAX &&
        provider.length <= CookieLimits.PROVIDER_MAX && purpose.length <= CookieLimits.PURPOSE_MAX && duration.length <= CookieLimits.DURATION_MAX

    fun body() = CookieBody(category, name.trim(), provider.trim().ifEmpty { null }, purpose.trim(), duration.trim().ifEmpty { null })
}

internal data class CookiesUiState(
    val categories: List<ConsentCategoryInfo> = emptyList(),
    /* Il modulo aperto, con la finalità della scheda già scelta; `null` se chiuso. */
    val draft: CookieDraft? = null,
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val reordering: Boolean = false,
    val message: UiText? = null,
    val error: String? = null,
)

/**
 * Il registro dei cookie (R-SY-12): una scheda per finalità con i conteggi,
 * ricerca, «Aggiungi un cookie», riordino della scheda (su e giù al posto del
 * trascinamento). Il pannello non modifica né elimina queste righe.
 */
internal class CookiesViewModel(
    private val repository: EditorialRepository,
    private val source: SettingsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(CookiesUiState())
    val uiState: StateFlow<CookiesUiState> = state.asStateFlow()

    val list = PagedListController(
        viewModelScope,
        ListQuery(q = saved.get<String>(KEY_QUERY).orEmpty(), tab = saved.get<String>(KEY_TAB) ?: ConsentCategoryValue.NECESSARY, perPage = ListQuery.MAX_PER_PAGE),
    ) { query, page ->
        val result = repository.call { source.cookies(query.params(page)) }
        if (result.meta.categories.isNotEmpty()) state.update { it.copy(categories = result.meta.categories) }
        EditorialPage(result.data, result.meta.page())
    }

    init { list.refresh() }

    fun search(q: String) {
        saved[KEY_QUERY] = q
        list.search(q)
    }

    fun selectTab(tab: String?) {
        val chosen = tab ?: return
        saved[KEY_TAB] = chosen
        list.selectTab(chosen)
    }

    fun openAdd() = state.update { it.copy(draft = CookieDraft(category = list.current.value.query.tab ?: ConsentCategoryValue.NECESSARY), errors = FormErrors.None, message = null) }
    fun editDraft(change: (CookieDraft) -> CookieDraft) = state.update { now -> now.draft?.let { now.copy(draft = change(it)) } ?: now }
    fun dismissAdd() = state.update { if (it.saving) it else it.copy(draft = null, errors = FormErrors.None) }

    fun add() {
        val draft = state.value.draft ?: return
        if (state.value.saving || !draft.valid) return
        state.update { it.copy(saving = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.addCookie(draft.body()) }
                state.update { it.copy(saving = false, draft = null, message = messageOf(result.meta.message, R.string.config_cookies_added)) }
                list.refresh()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(saving = false, errors = FormErrors.from(e, COOKIE_FIELDS)) }
            }
        }
    }

    fun move(row: CookieRow, delta: Int) {
        val now = list.current.value
        if (state.value.reordering || !cookiesReorderable(now)) return
        val order = movedCookieOrder(now.items.map { it.id }, row.id, delta) ?: return
        val category = now.query.tab ?: row.category
        state.update { it.copy(reordering = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.reorderCookies(category, order) }
                state.update { it.copy(reordering = false, message = messageOf(result.meta.message, R.string.config_cookies_reordered)) }
                list.refresh()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(reordering = false, error = requestFailureMessage(e)) }
            }
        }
    }

    companion object {
        private const val KEY_QUERY = "q"
        private const val KEY_TAB = "tab"
        val COOKIE_FIELDS = listOf("category", "name", "provider", "purpose", "duration")
    }
}

/** Si riordina solo con tutta la scheda in vista e senza ricerca, come il riordino del pannello. */
internal fun cookiesReorderable(state: PagedState<CookieRow>): Boolean =
    state.query.q.isBlank() && state.page > 0 && !state.hasMore && !state.loading && state.items.size > 1

// ------------------------------------------------------ indirizzi cambiati

/** Gli indirizzi cambiati (R-SY-13): ricerca sui due indirizzi e gli ordinamenti delle colonne del pannello. */
internal class RedirectsViewModel(
    private val repository: EditorialRepository,
    private val source: SettingsSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, ListQuery(q = saved.get<String>(KEY_QUERY).orEmpty(), sort = saved.get<String>(KEY_SORT)?.takeIf { it in SORTS })) { query, page ->
        repository.call { source.redirects(query.params(page)) }
    }

    init { list.refresh() }

    fun search(q: String) {
        saved[KEY_QUERY] = q
        list.search(q)
    }

    fun sortBy(sort: String?) {
        saved[KEY_SORT] = sort
        list.sortBy(sort)
    }

    companion object {
        private const val KEY_QUERY = "q"
        private const val KEY_SORT = "sort"
        /* `null` è l'ordine del pannello: dal passaggio più recente. */
        const val SORT_LAST_HIT_OLDEST = "last_hit_at"
        const val SORT_HITS_MOST = "-hits"
        const val SORT_HITS_LEAST = "hits"
        val SORTS = setOf(SORT_LAST_HIT_OLDEST, SORT_HITS_MOST, SORT_HITS_LEAST)
    }
}

/** Il modulo «Aggiungi un indirizzo». */
internal data class RedirectDraft(
    val fromPath: String = "",
    val toPath: String = "",
    val cityId: Long? = null,
    val status: Int = 301,
    val wildcard: Boolean = false,
) {
    val targetValid: Boolean get() = toPath.isBlank() || isInternalRedirectTarget(toPath.trim())
    val valid: Boolean get() = fromPath.isNotBlank() && toPath.isNotBlank() && fromPath.length <= RedirectLimits.PATH_MAX &&
        toPath.length <= RedirectLimits.PATH_MAX && targetValid && status in setOf(301, 302)

    fun body() = RedirectBody(fromPath.trim(), toPath.trim(), cityId, status, wildcard)
}

internal data class RedirectNewUiState(
    val options: Loadable<RedirectOptions> = Loadable.Loading,
    val draft: RedirectDraft = RedirectDraft(),
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    /* Aggiunto: la schermata torna all'elenco, che si ricarica. */
    val created: RedirectRow? = null,
)

internal class RedirectNewViewModel(private val repository: EditorialRepository, private val source: SettingsSource) : ViewModel() {
    private val state = MutableStateFlow(RedirectNewUiState())
    val uiState: StateFlow<RedirectNewUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(options = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val options = repository.call { source.redirectOptions() }
                state.update { it.copy(options = Loadable.Ready(options), draft = it.draft.copy(status = options.defaultStatus)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(options = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun edit(change: (RedirectDraft) -> RedirectDraft) = state.update { it.copy(draft = change(it.draft)) }

    fun save() {
        val now = state.value
        if (now.saving || !now.draft.valid) return
        state.update { it.copy(saving = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.addRedirect(now.draft.body()) }
                state.update { it.copy(saving = false, created = result.data) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(saving = false, errors = FormErrors.from(e, FIELDS)) }
            }
        }
    }

    companion object {
        val FIELDS = listOf("from_path", "to_path", "city_id", "status", "is_wildcard")
    }
}

// ------------------------------------------------------------------- cache

internal data class CacheUiState(
    val cache: Loadable<CacheSettingsView> = Loadable.Loading,
    val confirmingClear: Boolean = false,
    val busy: Boolean = false,
    val message: UiText? = null,
    /* L'esito della verifica di Redis: vero se risponde. */
    val redisOk: Boolean? = null,
    val error: String? = null,
)

/** «Cache e prestazioni» (R-SY-14): lettura, «Pulisci tutte le cache del sito», «Verifica Redis». */
internal class CacheViewModel(private val repository: EditorialRepository, private val source: SettingsSource) : ViewModel() {
    private val state = MutableStateFlow(CacheUiState())
    val uiState: StateFlow<CacheUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(cache = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val cache = repository.call { source.cache() }
                state.update { it.copy(cache = Loadable.Ready(cache)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(cache = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun askClear() = state.update { it.copy(confirmingClear = true, error = null) }
    fun dismissClear() = state.update { if (it.busy) it else it.copy(confirmingClear = false) }

    fun clear() {
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null, redisOk = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.clearCache() }
                state.update { it.copy(busy = false, confirmingClear = false, message = messageOf(result.meta.message, R.string.config_cache_cleared)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, confirmingClear = false, error = requestFailureMessage(e)) }
            }
        }
    }

    fun verify() {
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null, redisOk = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.verifyRedis() }
                state.update { it.copy(busy = false, redisOk = result.data.ok, message = messageOf(result.meta.message, if (result.data.ok) R.string.config_cache_redis_ok else R.string.config_cache_redis_failed)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, error = requestFailureMessage(e)) }
            }
        }
    }
}
