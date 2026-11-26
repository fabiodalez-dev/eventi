package it.fabiodalez.incitta.ui.editorial.users

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialPerson
import it.fabiodalez.incitta.data.editorial.EditorialUsersApi
import it.fabiodalez.incitta.data.editorial.EditorialUsersSource
import it.fabiodalez.incitta.data.editorial.NewUserBody
import it.fabiodalez.incitta.data.editorial.UserChange
import it.fabiodalez.incitta.data.editorial.UserCredentialsBody
import it.fabiodalez.incitta.data.editorial.UserUpdateBody
import it.fabiodalez.incitta.data.editorial.UsersOptions
import it.fabiodalez.incitta.data.editorial.WhatsappAttempt
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * R5 — Utenti (R-US-01, 02, 04…06, 08…12). I ViewModel portano le richieste
 * e tengono lo stato (criteri dell'elenco e bozze dei moduli sopravvivono
 * alla rotazione); chi può fare che cosa lo dicono le `abilities` di ogni
 * persona, calcolate dal server con le regole del pannello. Ogni chiamata
 * passa da `repository.call { }`: sui 423 chiede la password e riprova.
 */

/** Le API dell'area con il client e il token della sessione, e la città di lavoro sui percorsi. */
internal fun usersSource(repository: EditorialRepository): EditorialUsersSource =
    EditorialUsersApi(checkNotNull(repository.client) { "client della redazione mancante" }, repository.token) { repository.selectedCity }

/**
 * Le azioni sulla persona del menu «Altre azioni» del pannello. `key` è
 * l'abilità che il server manda; «Modifica» è il tocco sulla riga.
 */
internal enum class UserAction(val key: String, val label: Int, val destructive: Boolean = false) {
    Suspend("suspend", R.string.editorial_users_action_suspend, destructive = true),
    Unsuspend("unsuspend", R.string.editorial_users_action_unsuspend),
    Handle("handle", R.string.editorial_users_action_handle),
    WhatsappRevoke("whatsapp_revoke", R.string.editorial_users_action_whatsapp_revoke, destructive = true),
    WhatsappHistory("whatsapp_history", R.string.editorial_users_action_whatsapp_history),
    Destroy("destroy", R.string.editorial_users_action_destroy, destructive = true),
    Restore("restore", R.string.editorial_users_action_restore);

    /** Quelle che si fanno dopo una conferma semplice (senza campi da scrivere né finestre di sola lettura). */
    val needsConfirmation: Boolean get() = this != Handle && this != WhatsappHistory

    companion object {
        fun of(key: String): UserAction? = entries.firstOrNull { it.key == key }
    }
}

/** Le azioni che la persona permette a chi guarda, nell'ordine del menu del pannello. */
internal fun allowedActions(user: EditorialPerson): List<UserAction> = UserAction.entries.filter { user.allows(it.key) }

/** Esegue un'azione sul server: tutte rispondono con la persona com'è adesso. */
internal suspend fun EditorialUsersSource.perform(action: UserAction, id: Long, handle: String = "", reason: String = ""): UserChange = when (action) {
    UserAction.Suspend -> suspend(id)
    UserAction.Unsuspend -> unsuspend(id)
    UserAction.Handle -> renameHandle(id, handle.trim(), reason.trim())
    UserAction.WhatsappRevoke -> revokeWhatsapp(id)
    UserAction.Destroy -> destroy(id)
    UserAction.Restore -> restore(id)
    UserAction.WhatsappHistory -> error("lo storico si legge, non si esegue")
}

/** Un'azione in attesa di conferma, o la finestra del nome utente, sulla persona `user`. */
internal data class PendingUserAction(val action: UserAction, val user: EditorialPerson)

/** La finestra dello storico WhatsApp aperta su una persona. */
internal data class WhatsappHistoryState(val user: EditorialPerson, val attempts: Loadable<List<WhatsappAttempt>> = Loadable.Loading)

/** Lo stato comune delle azioni: la conferma aperta, la richiesta in corso, l'esito. */
internal data class UserActionsState(
    val pending: PendingUserAction? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val message: UiText? = null,
    val history: WhatsappHistoryState? = null,
)

/**
 * Le azioni sulle persone, uguali nell'elenco e nella scheda: conferma,
 * esecuzione dentro `repository.call { }`, esito, storico WhatsApp.
 * `onDone` riceve la persona aggiornata.
 */
internal class UserActions(
    private val repository: EditorialRepository,
    private val api: EditorialUsersSource,
    private val launch: (suspend () -> Unit) -> Unit,
    private val onDone: (UserAction, EditorialPerson) -> Unit,
) {
    private val state = MutableStateFlow(UserActionsState())
    val current: StateFlow<UserActionsState> = state.asStateFlow()

    /** Dal menu: lo storico si apre subito, le altre chiedono conferma (o il nome utente). */
    fun request(action: UserAction, user: EditorialPerson) {
        if (!user.allows(action.key)) return
        if (action == UserAction.WhatsappHistory) { openHistory(user); return }
        state.update { it.copy(pending = PendingUserAction(action, user), error = null) }
    }

    fun dismiss() = state.update { if (it.busy) it else it.copy(pending = null, error = null) }
    fun clearMessage() = state.update { it.copy(message = null) }
    fun closeHistory() = state.update { it.copy(history = null) }

    /** Conferma l'azione aperta; per il nome utente servono nome e motivo. */
    fun confirm(handle: String = "", reason: String = "") {
        val pending = state.value.pending ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        launch {
            try {
                val change = repository.call { api.perform(pending.action, pending.user.id, handle, reason) }
                state.update { it.copy(busy = false, pending = null, message = change.message?.let(UiText::Raw)) }
                onDone(pending.action, change.user)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                state.update { it.copy(busy = false, error = requestFailureMessage(e)) }
            }
        }
    }

    private fun openHistory(user: EditorialPerson) {
        state.update { it.copy(history = WhatsappHistoryState(user)) }
        launch {
            val result = try {
                val attempts = repository.call { api.whatsappHistory(user.id) }
                if (attempts.isEmpty()) Loadable.Empty else Loadable.Ready(attempts)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
            state.update { now -> if (now.history?.user?.id == user.id) now.copy(history = now.history.copy(attempts = result)) else now }
        }
    }
}

/** Le schede dell'elenco, nell'ordine del pannello (`null` = «Tutti»). */
internal val USER_TABS: List<Pair<String?, Int>> = listOf(
    null to R.string.editorial_users_tab_all,
    "pending" to R.string.editorial_users_tab_pending,
    "admins" to R.string.editorial_users_tab_admins,
    "staff" to R.string.editorial_users_tab_staff,
    "owners" to R.string.editorial_users_tab_owners,
    "editors" to R.string.editorial_users_tab_editors,
    "users" to R.string.editorial_users_tab_users,
    "unlinked" to R.string.editorial_users_tab_unlinked,
)

/** «Utenti»: elenco con schede, filtri e ricerca, azioni di riga. */
internal class UsersListViewModel(
    val repository: EditorialRepository,
    private val api: EditorialUsersSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(
        viewModelScope,
        ListQuery(q = saved[KEY_Q] ?: "", tab = saved[KEY_TAB], filters = savedFilters(saved)),
    ) { query, page -> repository.call { api.list(query.params(page)) } }
    val rows: StateFlow<PagedState<EditorialPerson>> = list.current

    private val optionsState = MutableStateFlow<Loadable<UsersOptions>>(Loadable.Loading)
    val options: StateFlow<Loadable<UsersOptions>> = optionsState.asStateFlow()

    val actions = UserActions(repository, api, { block -> viewModelScope.launch { block() } }) { action, user -> afterAction(action, user) }

    init {
        list.refresh()
        loadOptions()
    }

    fun loadOptions() {
        optionsState.value = Loadable.Loading
        viewModelScope.launch {
            optionsState.value = try {
                Loadable.Ready(repository.call { api.options() })
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
        }
    }

    fun search(q: String) { saved[KEY_Q] = q; list.search(q) }
    fun selectTab(tab: String?) { saved[KEY_TAB] = tab; list.selectTab(tab) }
    fun applyFilters(filters: Map<String, String>) {
        saved[KEY_FILTERS] = ArrayList(filters.filterValues { it.isNotBlank() }.map { "${it.key}=${it.value}" })
        list.applyFilters(filters)
    }

    /** Dopo un'azione: la riga si aggiorna, o esce dall'elenco se con i filtri di adesso non ci sta più. */
    private fun afterAction(action: UserAction, user: EditorialPerson) {
        val trashedFilter = rows.value.query.filters["trashed"]
        val leaves = (action == UserAction.Destroy && trashedFilter == null) || (action == UserAction.Restore && trashedFilter == "only")
        if (leaves) list.remove { it.id == user.id } else list.replace({ it.id == user.id }, user)
    }

    /** Dopo la scheda o la creazione: si rilegge l'elenco con gli stessi criteri. */
    fun refresh() = list.refresh()

    companion object {
        private const val KEY_Q = "users_q"
        private const val KEY_TAB = "users_tab"
        private const val KEY_FILTERS = "users_filters"

        private fun savedFilters(saved: SavedStateHandle): Map<String, String> =
            saved.get<ArrayList<String>>(KEY_FILTERS).orEmpty().mapNotNull { entry ->
                val key = entry.substringBefore('=', "")
                val value = entry.substringAfter('=', "")
                if (key.isBlank() || value.isBlank()) null else key to value
            }.toMap()
    }
}

/** La bozza del modulo «Account» (città, nome, email, password, fuso, lingua). */
internal data class UserDraft(
    val cityId: Long? = null,
    val name: String = "",
    val email: String = "",
    val password: String = "",
    val timezone: String = "Europe/Rome",
    val locale: String = "it",
) {
    /**
     * I problemi che si vedono già sul telefono, con il campo del server:
     * il resto (email già usata, password troppo comune) lo dice il server.
     * `creating` chiede email e password; `credentials` solo email e, se
     * scritta, una password valida.
     */
    fun problems(mode: DraftMode, timezones: List<String> = emptyList()): Map<String, Int> = buildMap {
        if (mode != DraftMode.Credentials && name.isBlank()) put("name", R.string.editorial_users_name_required)
        if (mode != DraftMode.Update && !EMAIL.matches(email.trim())) put("email", R.string.editorial_users_email_invalid)
        if (mode == DraftMode.Create && password.length < MIN_PASSWORD) put("password", R.string.editorial_users_password_short)
        if (mode == DraftMode.Credentials && password.isNotEmpty() && password.length < MIN_PASSWORD) put("password", R.string.editorial_users_password_short)
        if (mode != DraftMode.Credentials && timezones.isNotEmpty() && timezone !in timezones) put("timezone", R.string.editorial_users_timezone_invalid)
    }

    fun updateBody() = UserUpdateBody(cityId, name.trim(), timezone, locale)
    fun createBody(roles: Set<String>) = NewUserBody(name.trim(), email.trim(), password, cityId, timezone, locale, roles.sorted())
    fun credentialsBody() = UserCredentialsBody(email.trim(), password.ifEmpty { null })

    companion object {
        const val MIN_PASSWORD = 8
        private val EMAIL = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

        fun from(user: EditorialPerson): UserDraft {
            val form = user.form
            return UserDraft(form?.cityId, form?.name ?: "", form?.email ?: user.email, "", form?.timezone ?: "Europe/Rome", form?.locale ?: "it")
        }
    }
}

internal enum class DraftMode { Create, Update, Credentials }

/**
 * I ruoli che il modulo mostra: quelli che si possono dare dall'app (con la
 * spunta se la persona li ha) e quelli che la persona ha ma che si cambiano
 * solo dal sito (in sola lettura).
 */
internal data class RoleChoices(val assignable: List<String>, val webOnly: List<String>) {
    companion object {
        fun of(current: List<String>, assignable: List<String>): RoleChoices =
            RoleChoices(assignable, current.filter { it !in assignable })
    }
}

internal data class UserDetailUiState(
    val user: Loadable<EditorialPerson> = Loadable.Loading,
    val options: UsersOptions? = null,
    val draft: UserDraft = UserDraft(),
    val roles: Set<String> = emptySet(),
    val credentials: UserDraft = UserDraft(),
    val errors: FormErrors = FormErrors.None,
    val roleErrors: FormErrors = FormErrors.None,
    val credentialErrors: FormErrors = FormErrors.None,
    val saving: Boolean = false,
    val message: UiText? = null,
    /* Vero dopo il cambio delle proprie credenziali: la sessione dell'app non vale più. */
    val signedOut: Boolean = false,
)

/** La scheda di una persona: lettura, modifica, ruoli, credenziali proprie e azioni. */
internal class UserDetailViewModel(
    val repository: EditorialRepository,
    private val api: EditorialUsersSource,
    private val saved: SavedStateHandle,
    val userId: Long,
) : ViewModel() {
    private val state = MutableStateFlow(UserDetailUiState())
    val uiState: StateFlow<UserDetailUiState> = state.asStateFlow()
    val actions = UserActions(repository, api, { block -> viewModelScope.launch { block() } }) { _, user -> show(user, keepDraft = true) }

    /** Vero quando la scheda è la propria: solo allora email e password si cambiano dall'app. */
    val isSelf: Boolean get() = userId == repository.userId

    init { load() }

    fun load() {
        state.update { it.copy(user = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val user = repository.call { api.show(userId) }
                show(user, keepDraft = saved.contains(KEY_DRAFT_NAME))
                if (state.value.options == null) {
                    val options = runCatching { repository.call { api.options() } }.getOrNull()
                    state.update { it.copy(options = options) }
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                state.update { it.copy(user = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    private fun show(user: EditorialPerson, keepDraft: Boolean) {
        val fresh = UserDraft.from(user)
        val draft = if (keepDraft) restoredDraft(fresh) else fresh
        val roles = if (keepDraft) saved.get<ArrayList<String>>(KEY_ROLES)?.toSet() ?: user.form?.roles.orEmpty().toSet() else user.form?.roles.orEmpty().toSet()
        state.update { it.copy(user = Loadable.Ready(user), draft = draft, roles = roles, credentials = it.credentials.copy(email = it.credentials.email.ifEmpty { user.email })) }
        if (!keepDraft) storeDraft(draft, roles)
    }

    fun editDraft(change: (UserDraft) -> UserDraft) {
        val draft = change(state.value.draft)
        state.update { it.copy(draft = draft, errors = FormErrors.None) }
        storeDraft(draft, state.value.roles)
    }

    fun toggleRole(role: String) {
        val roles = state.value.roles.let { if (role in it) it - role else it + role }
        state.update { it.copy(roles = roles, roleErrors = FormErrors.None) }
        storeDraft(state.value.draft, roles)
    }

    fun editCredentials(change: (UserDraft) -> UserDraft) = state.update { it.copy(credentials = change(it.credentials), credentialErrors = FormErrors.None) }
    fun clearMessage() = state.update { it.copy(message = null) }

    fun save() {
        val now = state.value
        if (now.saving) return
        val problems = now.draft.problems(DraftMode.Update, now.options?.timezones.orEmpty())
        if (problems.isNotEmpty()) { state.update { it.copy(errors = localErrors(problems)) }; return }
        submit(onError = { e -> state.update { it.copy(errors = FormErrors.from(e, UPDATE_FIELDS)) } }) {
            val change = repository.call { api.update(userId, now.draft.updateBody()) }
            saved.remove<String>(KEY_DRAFT_NAME)
            show(change.user, keepDraft = false)
            state.update { it.copy(message = change.message?.let(UiText::Raw)) }
        }
    }

    /** Salva i ruoli che si danno dall'app; quelli solo del sito restano come sono (li conserva il server). */
    fun saveRoles() {
        val now = state.value
        val user = now.user.valueOrNullUser() ?: return
        if (now.saving) return
        val assignable = user.assignableRoles
        submit(onError = { e -> state.update { it.copy(roleErrors = FormErrors.from(e, listOf("roles"))) } }) {
            val change = repository.call { api.roles(userId, now.roles.filter { it in assignable }.sorted()) }
            saved.remove<String>(KEY_DRAFT_NAME)
            show(change.user, keepDraft = false)
            state.update { it.copy(message = change.message?.let(UiText::Raw)) }
        }
    }

    /** Le proprie credenziali: dopo il cambio la sessione dell'app è chiusa dal server. */
    fun saveCredentials() {
        val now = state.value
        if (now.saving || !isSelf) return
        val problems = now.credentials.problems(DraftMode.Credentials)
        if (problems.isNotEmpty()) { state.update { it.copy(credentialErrors = localErrors(problems)) }; return }
        submit(onError = { e -> state.update { it.copy(credentialErrors = FormErrors.from(e, listOf("email", "password"))) } }) {
            val (signedOut, message) = repository.call { api.credentials(userId, now.credentials.credentialsBody()) }
            state.update { it.copy(signedOut = signedOut, credentials = it.credentials.copy(password = ""), message = message?.let(UiText::Raw)) }
        }
    }

    private fun submit(onError: (Exception) -> Unit, block: suspend () -> Unit) {
        state.update { it.copy(saving = true) }
        viewModelScope.launch {
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                onError(e)
            } finally {
                state.update { it.copy(saving = false) }
            }
        }
    }

    private fun restoredDraft(fresh: UserDraft): UserDraft = UserDraft(
        cityId = saved.get<Long>(KEY_DRAFT_CITY)?.takeIf { it > 0 },
        name = saved[KEY_DRAFT_NAME] ?: fresh.name,
        email = fresh.email,
        timezone = saved[KEY_DRAFT_TIMEZONE] ?: fresh.timezone,
        locale = saved[KEY_DRAFT_LOCALE] ?: fresh.locale,
    )

    private fun storeDraft(draft: UserDraft, roles: Set<String>) {
        saved[KEY_DRAFT_CITY] = draft.cityId ?: 0L
        saved[KEY_DRAFT_NAME] = draft.name
        saved[KEY_DRAFT_TIMEZONE] = draft.timezone
        saved[KEY_DRAFT_LOCALE] = draft.locale
        saved[KEY_ROLES] = ArrayList(roles.sorted())
    }

    companion object {
        val UPDATE_FIELDS = listOf("city_id", "name", "timezone", "locale", "email", "password")
        private const val KEY_DRAFT_CITY = "user_draft_city"
        private const val KEY_DRAFT_NAME = "user_draft_name"
        private const val KEY_DRAFT_TIMEZONE = "user_draft_timezone"
        private const val KEY_DRAFT_LOCALE = "user_draft_locale"
        private const val KEY_ROLES = "user_draft_roles"
    }
}

internal data class UserCreateUiState(
    val options: Loadable<UsersOptions> = Loadable.Loading,
    val draft: UserDraft = UserDraft(),
    val roles: Set<String> = emptySet(),
    val errors: FormErrors = FormErrors.None,
    val saving: Boolean = false,
    /* L'id della persona appena creata: la schermata apre la sua scheda. */
    val createdId: Long? = null,
    val message: UiText? = null,
)

/** «Nuovo utente»: i campi del modulo e i ruoli fino a moderatore. */
internal class UserCreateViewModel(
    val repository: EditorialRepository,
    private val api: EditorialUsersSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(
        UserCreateUiState(
            draft = UserDraft(
                cityId = saved.get<Long>(KEY_CITY)?.takeIf { it > 0 },
                name = saved[KEY_NAME] ?: "",
                email = saved[KEY_EMAIL] ?: "",
                timezone = saved[KEY_TIMEZONE] ?: "Europe/Rome",
                locale = saved[KEY_LOCALE] ?: "it",
            ),
            roles = saved.get<ArrayList<String>>(KEY_ROLES)?.toSet() ?: emptySet(),
        ),
    )
    val uiState: StateFlow<UserCreateUiState> = state.asStateFlow()

    init { loadOptions() }

    fun loadOptions() {
        state.update { it.copy(options = Loadable.Loading) }
        viewModelScope.launch {
            val result = try {
                Loadable.Ready(repository.call { api.options() })
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
            state.update { now ->
                val ready = (result as? Loadable.Ready)?.value
                /* Fuso e lingua predefiniti del server, se la bozza non li ha già cambiati. */
                val draft = if (ready != null && saved.get<String>(KEY_TIMEZONE) == null) now.draft.copy(timezone = ready.defaultTimezone, locale = ready.defaultLocale) else now.draft
                now.copy(options = result, draft = draft)
            }
        }
    }

    fun edit(change: (UserDraft) -> UserDraft) {
        val draft = change(state.value.draft)
        state.update { it.copy(draft = draft, errors = FormErrors.None) }
        saved[KEY_CITY] = draft.cityId ?: 0L
        saved[KEY_NAME] = draft.name
        saved[KEY_EMAIL] = draft.email
        saved[KEY_TIMEZONE] = draft.timezone
        saved[KEY_LOCALE] = draft.locale
    }

    fun toggleRole(role: String) {
        val roles = state.value.roles.let { if (role in it) it - role else it + role }
        saved[KEY_ROLES] = ArrayList(roles.sorted())
        state.update { it.copy(roles = roles, errors = FormErrors.None) }
    }

    fun create() {
        val now = state.value
        if (now.saving) return
        val options = (now.options as? Loadable.Ready)?.value
        val problems = now.draft.problems(DraftMode.Create, options?.timezones.orEmpty())
        if (problems.isNotEmpty()) { state.update { it.copy(errors = localErrors(problems)) }; return }
        val roles = now.roles.filter { options == null || it in options.assignableRoles }.toSet()
        state.update { it.copy(saving = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val change = repository.call { api.create(now.draft.createBody(roles)) }
                listOf(KEY_CITY, KEY_NAME, KEY_EMAIL, KEY_TIMEZONE, KEY_LOCALE, KEY_ROLES).forEach { saved.remove<Any>(it) }
                state.update { it.copy(saving = false, createdId = change.user.id, message = change.message?.let(UiText::Raw)) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                state.update { it.copy(saving = false, errors = FormErrors.from(e, CREATE_FIELDS)) }
            }
        }
    }

    fun consumeCreated() = state.update { it.copy(createdId = null) }

    companion object {
        val CREATE_FIELDS = listOf("city_id", "name", "email", "password", "timezone", "locale", "roles")
        private const val KEY_CITY = "new_user_city"
        private const val KEY_NAME = "new_user_name"
        private const val KEY_EMAIL = "new_user_email"
        private const val KEY_TIMEZONE = "new_user_timezone"
        private const val KEY_LOCALE = "new_user_locale"
        private const val KEY_ROLES = "new_user_roles"
    }
}

/** I problemi locali diventano errori del modulo: il testo lo risolve la schermata con `localFieldMessage`. */
private fun localErrors(problems: Map<String, Int>): FormErrors = FormErrors(fields = problems.mapValues { (_, res) -> "$LOCAL_PREFIX$res" })

/** Un errore del modulo può essere il testo del server o il riferimento a una stringa locale (`res:<id>`). */
internal fun localFieldMessage(message: String?, resolve: (Int) -> String): String? =
    message?.let { if (it.startsWith(LOCAL_PREFIX)) it.removePrefix(LOCAL_PREFIX).toIntOrNull()?.let(resolve) ?: it else it }

private const val LOCAL_PREFIX = "res:"

private fun Loadable<EditorialPerson>.valueOrNullUser(): EditorialPerson? = (this as? Loadable.Ready)?.value
