package it.fabiodalez.incitta.ui.editorial.community

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.editorial.CarpoolSection
import it.fabiodalez.incitta.data.editorial.CaseEvidence
import it.fabiodalez.incitta.data.editorial.CaseOperation
import it.fabiodalez.incitta.data.editorial.CommunityDeskSource
import it.fabiodalez.incitta.data.editorial.CommunitySection
import it.fabiodalez.incitta.data.editorial.DeskCarpoolCase
import it.fabiodalez.incitta.data.editorial.DeskCarpoolItem
import it.fabiodalez.incitta.data.editorial.DeskCommunityItem
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.RestrictionScope
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
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
 * R7 — Community e passaggi (R-CM-01…05, R-CP-01…10, R-CP-12), come le due
 * pagine del pannello: «Profili e post» e «Passaggi in auto». Chi vede quale
 * pagina e quale scheda lo dicono i `permissions` del banco; quali pulsanti
 * ha una riga lo dicono le sue `abilities`, calcolate dal server. Ogni azione
 * chiede il motivo (5–1000 caratteri) nella sua finestra, come il pannello.
 * Ogni chiamata passa da `repository.call { }`: con un 423 compare la
 * richiesta della password e la chiamata riparte.
 */

// ------------------------------------------------------------------ sezioni

/** Le due pagine del gruppo, con il permesso che le apre e il contatore del menu. */
internal enum class CommunityPage(val permission: String, val badge: String?, val title: Int, val help: Int) {
    Community("community_manage", null, R.string.ed_community_page_title, R.string.ed_community_page_help),
    Carpool("cases_manage", "carpool_open", R.string.ed_carpool_page_title, R.string.ed_carpool_page_help),
}

/** Le pagine che la persona vede: tutte e due per moderatore, admin e super admin con i permessi di oggi. */
internal fun communityPages(can: EditorialPermissions): List<CommunityPage> = CommunityPage.entries.filter { can.allows(it.permission) }

/** Le schede della pagina dei passaggi: casi e registro con l'ingresso, le altre anche con `carpool_manage`, come il pannello. */
internal fun carpoolSections(can: EditorialPermissions): List<String> =
    CarpoolSection.all.filter { it !in CarpoolSection.needsCarpoolManage || can.carpoolManage }

/** Il motivo: i tetti di `options.limits`, altrimenti quelli del pannello. */
internal fun reasonRules(options: ManagementOptions?): ReasonRules = ReasonRules(
    min = options?.limit("reason_min")?.toInt() ?: ReasonRules.DEFAULT_MIN,
    max = options?.limit("reason_max")?.toInt() ?: ReasonRules.DEFAULT_MAX,
)

/** Il caso è cambiato nel frattempo (409 del server): si rilegge. */
internal fun isCaseConflict(error: Throwable): Boolean = error is ApiException && error.status == 409

private fun messageOf(text: String?, fallback: Int): UiText = text?.takeIf { it.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(fallback)

private const val KEY_QUERY = "q"
private const val KEY_SECTION = "section"
private const val KEY_STATUS = "status"
private const val FIELD_REASON = "reason"

/** La prima schermata dell'area: le due pagine con i loro contatori, riletti entrando. */
internal class CommunityHubViewModel(private val repository: EditorialRepository) : ViewModel() {
    val desk: StateFlow<EditorialDesk?> = repository.current

    init { refresh() }

    fun refresh() {
        viewModelScope.launch {
            try { repository.loadDesk() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* restano i contatori di prima */ }
        }
    }
}

// --------------------------------------------------------- profili e post

/** Le azioni di una riga di «Profili e post», con la chiave di `abilities` che le permette. */
internal enum class CommunityAction(val ability: String) {
    Feature("feature"),
    Unfeature("feature"),
    Suspend("suspend"),
    Unsuspend("unsuspend"),
    Hide("hide"),
    Restore("restore"),
    RemoveRestriction("remove"),
}

/** Le azioni che la riga mostra, nell'ordine del pannello. */
internal fun communityActions(item: DeskCommunityItem, abilities: Map<String, Boolean>): List<CommunityAction> {
    val candidates = when (item.kind) {
        "profile" -> listOf(if (item.featured) CommunityAction.Unfeature else CommunityAction.Feature, if (item.suspended) CommunityAction.Unsuspend else CommunityAction.Suspend)
        "post", "comment" -> listOf(if (item.hidden) CommunityAction.Restore else CommunityAction.Hide)
        "restriction" -> listOf(CommunityAction.RemoveRestriction)
        else -> emptyList()
    }
    return candidates.filter { abilities[it.ability] == true }
}

internal data class CommunityPendingAction(val item: DeskCommunityItem, val action: CommunityAction)

internal data class CommunityUiState(
    val pending: CommunityPendingAction? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
)

/** «Profili e post» (R-CM-01…05): quattro schede con ricerca e le azioni di ogni riga con il motivo. */
internal class CommunityModerationViewModel(
    private val repository: EditorialRepository,
    private val source: CommunityDeskSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(
        viewModelScope,
        ListQuery(q = saved.get<String>(KEY_QUERY).orEmpty(), tab = saved.get<String>(KEY_SECTION)?.takeIf { it in CommunitySection.all } ?: CommunitySection.PROFILES),
    ) { query, page ->
        val section = query.tab ?: CommunitySection.PROFILES
        repository.call { source.community(section, query.params(page).filterNot { it.first == "tab" }) }
    }
    private val state = MutableStateFlow(CommunityUiState())
    val uiState: StateFlow<CommunityUiState> = state.asStateFlow()
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch { optionsState.value = repository.options() }
    }

    fun selectSection(section: String) {
        if (section !in CommunitySection.all || section == list.current.value.query.tab) return
        saved[KEY_SECTION] = section
        state.update { it.copy(message = null) }
        list.selectTab(section)
    }

    fun search(q: String) {
        saved[KEY_QUERY] = q
        list.search(q)
    }

    fun ask(item: DeskCommunityItem, action: CommunityAction) = state.update { it.copy(pending = CommunityPendingAction(item, action), errors = FormErrors.None, message = null) }
    fun dismiss() = state.update { if (it.busy) it else it.copy(pending = null, errors = FormErrors.None) }

    fun confirm(reason: String) {
        val pending = state.value.pending ?: return
        val text = reason.trim()
        if (state.value.busy || !reasonRules(options.value).accepts(text)) return
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        val item = pending.item
        viewModelScope.launch {
            try {
                val result = repository.call {
                    when (pending.action) {
                        CommunityAction.Feature -> source.featureProfile(item.id, true, text)
                        CommunityAction.Unfeature -> source.featureProfile(item.id, false, text)
                        CommunityAction.Suspend -> source.suspendMember(item.userId, text)
                        CommunityAction.Unsuspend -> source.unsuspendMember(item.userId, text)
                        CommunityAction.Hide -> source.hideContent(contentPath(item.kind), item.id, text)
                        CommunityAction.Restore -> source.restoreContent(contentPath(item.kind), item.id, text)
                        CommunityAction.RemoveRestriction -> source.removeRestriction(item.id, text)
                    }
                }
                val row = result.data
                when {
                    pending.action == CommunityAction.RemoveRestriction -> list.remove { it.key == item.key }
                    row.kind == item.kind && row.id == item.id -> list.replace({ it.key == item.key }, row)
                    /* Una sospensione: il server manda il profilo della persona, se c'è; le altre righe si rileggono. */
                    else -> list.refresh()
                }
                state.update { it.copy(busy = false, pending = null, message = messageOf(result.meta.message, R.string.ed_community_done)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, errors = FormErrors.from(e, listOf(FIELD_REASON))) }
            }
        }
    }

    companion object {
        /** Il percorso del server per pubblicazioni e commenti. */
        fun contentPath(kind: String): String = if (kind == "post") CommunitySection.POSTS else CommunitySection.COMMENTS
    }
}

// --------------------------------------------------------- passaggi in auto

/** Le azioni di una riga dei passaggi (il caso si apre in una schermata sua). */
internal sealed interface CarpoolRowAction {
    val ability: String
    data object CancelRide : CarpoolRowAction { override val ability = "cancel" }
    data object HideReview : CarpoolRowAction { override val ability = "hide" }
    data object RestoreReview : CarpoolRowAction { override val ability = "restore" }
    data class Suspend(val scope: String) : CarpoolRowAction { override val ability get() = "suspend_$scope" }
    data class Restore(val scope: String) : CarpoolRowAction { override val ability get() = "restore_$scope" }
    data object RetryDelivery : CarpoolRowAction { override val ability = "retry" }
}

/** Le azioni che la riga mostra, nell'ordine del pannello. */
internal fun carpoolActions(item: DeskCarpoolItem, abilities: Map<String, Boolean>): List<CarpoolRowAction> {
    val candidates: List<CarpoolRowAction> = when (item.kind) {
        "ride" -> listOf(CarpoolRowAction.CancelRide)
        "review" -> listOf(if (item.hidden) CarpoolRowAction.RestoreReview else CarpoolRowAction.HideReview)
        "user" -> RestrictionScope.all.map { scope ->
            if (item.suspendedIn(scope)) CarpoolRowAction.Restore(scope) else CarpoolRowAction.Suspend(scope)
        }
        "delivery" -> listOf(CarpoolRowAction.RetryDelivery)
        else -> emptyList()
    }
    return candidates.filter { abilities[it.ability] == true }
}

internal data class CarpoolPendingAction(val item: DeskCarpoolItem, val action: CarpoolRowAction)

internal data class CarpoolUiState(
    val pending: CarpoolPendingAction? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
)

/** «Passaggi in auto» (R-CP-01, 06…10): sette schede, ricerca e stato dove il pannello li ha, le azioni delle righe. */
internal class CarpoolViewModel(
    private val repository: EditorialRepository,
    private val source: CommunityDeskSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    /* Le schede permesse: calcolate dal banco, che l'area ha già letto entrando. */
    val sections: List<String> = carpoolSections(repository.current.value?.can ?: EditorialPermissions())

    val list = PagedListController(
        viewModelScope,
        ListQuery(
            q = saved.get<String>(KEY_QUERY).orEmpty(),
            tab = saved.get<String>(KEY_SECTION)?.takeIf { it in sections } ?: CarpoolSection.CASES,
            filters = saved.get<String>(KEY_STATUS)?.takeIf { it.isNotBlank() }?.let { mapOf(FILTER_STATUS to it) } ?: emptyMap(),
        ),
    ) { query, page -> repository.call { source.carpool(query.tab ?: CarpoolSection.CASES, carpoolParams(query, page)) } }
    private val state = MutableStateFlow(CarpoolUiState())
    val uiState: StateFlow<CarpoolUiState> = state.asStateFlow()
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch { optionsState.value = repository.options() }
    }

    /** Cambiando scheda lo stato si azzera, come nel pannello; la ricerca resta. */
    fun selectSection(section: String) {
        if (section !in sections || section == list.current.value.query.tab) return
        saved[KEY_SECTION] = section
        saved[KEY_STATUS] = ""
        state.update { it.copy(message = null) }
        if (list.current.value.query.filters.isNotEmpty()) list.applyFilters(emptyMap())
        list.selectTab(section)
    }

    fun search(q: String) {
        saved[KEY_QUERY] = q
        list.search(q)
    }

    fun applyFilters(filters: Map<String, String>) {
        val status = filters[FILTER_STATUS].orEmpty()
        saved[KEY_STATUS] = status
        list.applyFilters(if (status.isBlank()) emptyMap() else mapOf(FILTER_STATUS to status))
    }

    fun ask(item: DeskCarpoolItem, action: CarpoolRowAction) = state.update { it.copy(pending = CarpoolPendingAction(item, action), errors = FormErrors.None, message = null) }
    fun dismiss() = state.update { if (it.busy) it else it.copy(pending = null, errors = FormErrors.None) }

    fun confirm(reason: String) {
        val pending = state.value.pending ?: return
        val text = reason.trim()
        if (state.value.busy || !reasonRules(options.value).accepts(text)) return
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        val item = pending.item
        viewModelScope.launch {
            try {
                val result = repository.call {
                    when (val action = pending.action) {
                        CarpoolRowAction.CancelRide -> source.cancelRide(item.id, text)
                        CarpoolRowAction.HideReview -> source.moderateRideReview(item.id, false, text)
                        CarpoolRowAction.RestoreReview -> source.moderateRideReview(item.id, true, text)
                        is CarpoolRowAction.Suspend -> source.suspendRider(item.id, action.scope, text)
                        is CarpoolRowAction.Restore -> source.restoreRider(item.id, action.scope, text)
                        CarpoolRowAction.RetryDelivery -> source.retryDelivery(item.id, text)
                    }
                }
                /* Una consegna rimessa in coda esce dalla sezione (tentativi azzerati); le altre righe si aggiornano. */
                if (pending.action == CarpoolRowAction.RetryDelivery) list.remove { it.key == item.key } else list.replace({ it.key == item.key }, result.data)
                state.update { it.copy(busy = false, pending = null, message = messageOf(result.meta.message, R.string.ed_carpool_done)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                if (isCaseConflict(e)) {
                    /* Qualcuno ha già agito: l'elenco si rilegge, come la pagina del pannello. */
                    state.update { it.copy(busy = false, pending = null, message = UiText.Res(R.string.ed_carpool_changed)) }
                    list.refresh()
                } else {
                    state.update { it.copy(busy = false, errors = FormErrors.from(e, listOf(FIELD_REASON))) }
                }
            }
        }
    }

    companion object {
        const val FILTER_STATUS = "status"

        /** I parametri di una scheda: senza `tab` (è nel percorso), senza ricerca e stato dove il pannello non li applica. */
        fun carpoolParams(query: ListQuery, page: Int): List<Pair<String, String>> {
            val section = query.tab ?: CarpoolSection.CASES
            return query.params(page).filterNot { (key, _) ->
                key == "tab" || (key == "q" && section !in CarpoolSection.searchable) || (key == "filter[$FILTER_STATUS]" && section !in CarpoolSection.withStatus)
            }
        }
    }
}

// --------------------------------------------------------------- il caso

/** Le finestre del caso aperto. */
internal sealed interface CaseDialog {
    data class Operation(val op: String) : CaseDialog
    data object Reply : CaseDialog
    data class Retention(val op: String) : CaseDialog
    data class Freeze(val freeze: Boolean) : CaseDialog
    data object Evidence : CaseDialog
}

internal data class CarpoolCaseUiState(
    val case: Loadable<DeskCarpoolCase> = Loadable.Loading,
    val dialog: CaseDialog? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
    /* Le evidenze lette: restano solo in memoria, mai nello stato salvato. */
    val evidence: CaseEvidence? = null,
    /* Cambiato: l'elenco dei casi, tornando indietro, si rilegge. */
    val changed: Boolean = false,
)

/** Il caso aperto (R-CP-01…05, R-CP-12): conversazione, nota, assegna, risolvi, riapri, rispondi, chat, conservazione, evidenze. */
internal class CarpoolCaseViewModel(
    private val repository: EditorialRepository,
    private val source: CommunityDeskSource,
    saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow(CarpoolCaseUiState())
    val uiState: StateFlow<CarpoolCaseUiState> = state.asStateFlow()
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        load()
        viewModelScope.launch { optionsState.value = repository.options() }
    }

    fun load() {
        state.update { it.copy(case = if (it.case is Loadable.Ready) it.case else Loadable.Loading) }
        viewModelScope.launch {
            try {
                val loaded = repository.call { source.carpoolCase(id) }
                state.update { it.copy(case = Loadable.Ready(loaded)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(case = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun ask(dialog: CaseDialog) = state.update { it.copy(dialog = dialog, errors = FormErrors.None, message = null) }
    fun dismiss() = state.update { if (it.busy) it else it.copy(dialog = null, errors = FormErrors.None) }
    fun closeEvidence() = state.update { it.copy(evidence = null) }

    /** Il motivo di un'operazione, di una conservazione, della chat o delle evidenze. */
    fun confirm(reason: String) {
        val dialog = state.value.dialog ?: return
        val case = (state.value.case as? Loadable.Ready)?.value ?: return
        val text = reason.trim()
        if (state.value.busy || dialog == CaseDialog.Reply || !reasonRules(options.value).accepts(text)) return
        if (dialog == CaseDialog.Evidence) {
            perform(FIELD_REASON) {
                val evidence = source.caseEvidence(case.id, text)
                state.update { it.copy(evidence = evidence) }
                null
            }
            return
        }
        perform(FIELD_REASON) {
            when (dialog) {
                is CaseDialog.Operation -> source.manageCase(case.id, dialog.op, text, case.revision)
                is CaseDialog.Retention -> source.holdCase(case.id, dialog.op, text, case.revision)
                is CaseDialog.Freeze -> source.freezeChat(case.id, dialog.freeze, text)
                else -> return@perform null
            }.let { result ->
                state.update { it.copy(case = Loadable.Ready(result.data), changed = true) }
                result.meta.message
            }
        }
    }

    /** «Invia risposta»: una delle persone del caso e il messaggio che riceve. */
    fun reply(recipientId: Long, message: String) {
        val case = (state.value.case as? Loadable.Ready)?.value ?: return
        val text = message.trim()
        if (state.value.busy || state.value.dialog != CaseDialog.Reply || case.people.none { it.id == recipientId } || !reasonRules(options.value).accepts(text)) return
        perform(FIELD_MESSAGE) {
            val result = source.replyCase(case.id, recipientId, text, case.revision)
            state.update { it.copy(case = Loadable.Ready(result.data), changed = true) }
            result.meta.message
        }
    }

    private fun perform(field: String, call: suspend () -> String?) {
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val message = repository.call { call() }
                state.update { it.copy(busy = false, dialog = null, message = if (it.evidence != null && message == null) null else messageOf(message, R.string.ed_carpool_done)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                if (isCaseConflict(e)) {
                    state.update { it.copy(busy = false, dialog = null, message = UiText.Res(R.string.ed_carpool_changed)) }
                    load()
                } else {
                    state.update { it.copy(busy = false, errors = FormErrors.from(e, listOf(field, "recipient_id"))) }
                }
            }
        }
    }

    companion object {
        const val KEY_ID = "id"
        const val FIELD_MESSAGE = "message"
        /** Le operazioni con la revisione che il caso offre, nell'ordine del pannello. */
        fun operations(closed: Boolean): List<String> = listOf(CaseOperation.ASSIGN, CaseOperation.NOTE, if (closed) CaseOperation.REOPEN else CaseOperation.RESOLVE)
    }
}
