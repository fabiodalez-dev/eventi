package it.fabiodalez.incitta.ui.editorial.moderation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.editorial.EditorialComment
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialReport
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialReview
import it.fabiodalez.incitta.data.editorial.ModerationLimits
import it.fabiodalez.incitta.data.editorial.ModerationSource
import it.fabiodalez.incitta.data.editorial.ReportStatusValue
import it.fabiodalez.incitta.data.editorial.ReviewStatusValue
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.takeEditorialListTarget
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
 * R6 — Moderazione dei contenuti (R-MD-01…12), come le tre risorse del
 * gruppo «Moderazione» del pannello: segnalazioni, recensioni, commenti agli
 * eventi. Chi vede quale elenco lo dicono i `permissions` del banco; quali
 * pulsanti ha una riga lo dicono le sue `abilities`, calcolate dal server
 * con le policy del sito. Ogni chiamata passa da `repository.call { }`: con
 * un 423 compare la richiesta della password e la chiamata riparte.
 */

// ------------------------------------------------------------------ sezioni

/** Le tre voci del gruppo «Moderazione» del pannello, con il permesso che le mostra e il contatore del menu. */
internal enum class ModerationSection(val permission: String, val badge: String, val title: Int, val help: Int) {
    Reports("reports_resolve", "reports_open", R.string.moderation_reports_title, R.string.moderation_reports_help),
    Reviews("reviews_moderate", "catalog_reviews_pending", R.string.moderation_reviews_title, R.string.moderation_reviews_help),
    Comments("comments_moderate", "event_comments_hidden", R.string.moderation_comments_title, R.string.moderation_comments_help),
}

/** Le voci che la persona vede: il moderatore non ha le recensioni, come nel pannello. */
internal fun moderationSections(can: EditorialPermissions): List<ModerationSection> =
    ModerationSection.entries.filter { can.allows(it.permission) }

/** Vero quando il server rifiuta perché qualcun altro ha deciso nel frattempo (`error.fields.revision`). */
internal fun isModerationConflict(error: Throwable): Boolean =
    error is ApiException && error.status == 422 && error.problem?.fields?.containsKey("revision") == true

private fun conflictText(error: Throwable): UiText =
    (error as? ApiException)?.problem?.fields?.get("revision")?.firstOrNull()?.takeIf { it.isNotBlank() }?.let(UiText::Raw)
        ?: UiText.Res(R.string.moderation_changed)

private fun messageOf(text: String?, fallback: Int): UiText = text?.takeIf { it.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(fallback)

/** I filtri salvati nella voce di navigazione (`chiave=valore;…`), per ritrovarli dopo la rotazione o il ritorno dal dettaglio. */
internal fun encodeFilters(filters: Map<String, String>): String = filters.toSortedMap().entries.joinToString(";") { "${it.key}=${it.value}" }

internal fun decodeFilters(raw: String?, allowed: Set<String>): Map<String, String> = raw.orEmpty().split(';').mapNotNull { part ->
    val key = part.substringBefore('=', "").trim()
    val value = part.substringAfter('=', "").trim()
    if (key in allowed && value.isNotEmpty()) key to value else null
}.toMap()

/**
 * I criteri di partenza di un elenco: quelli salvati, oppure, la prima
 * volta, i filtri della destinazione con cui un riquadro del riepilogo l'ha
 * aperto (`ListHandoff`), che da lì diventano i criteri salvati.
 */
private fun initialQuery(saved: SavedStateHandle, list: String, filterKeys: Set<String>, sorts: Set<String>): ListQuery {
    saved.takeEditorialListTarget(list)?.let { target -> saved[KEY_FILTERS] = encodeFilters(target.filters.filterKeys { it in filterKeys }) }
    return ListQuery(
        q = saved.get<String>(KEY_QUERY).orEmpty(),
        filters = decodeFilters(saved[KEY_FILTERS], filterKeys),
        sort = saved.get<String>(KEY_SORT)?.takeIf { it in sorts },
    )
}

private const val KEY_QUERY = "q"
private const val KEY_FILTERS = "filters"
private const val KEY_SORT = "sort"

/** Gli elenchi dei moduli del server (`report_statuses`, `review_statuses`, …), letti una volta per sessione. */
private suspend fun loadOptions(repository: EditorialRepository, into: MutableStateFlow<ManagementOptions?>) {
    if (into.value == null) into.value = repository.options()
}

// --------------------------------------------------------------------- menu

/** La prima schermata dell'area: le voci permesse con i loro contatori, riletti entrando. */
internal class ModerationHubViewModel(private val repository: EditorialRepository) : ViewModel() {
    val desk: StateFlow<EditorialDesk?> = repository.current

    init { refresh() }

    fun refresh() {
        viewModelScope.launch {
            try { repository.loadDesk() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* restano i contatori di prima */ }
        }
    }
}

// ------------------------------------------------------------- segnalazioni

/** Elenco delle segnalazioni (R-MD-01): filtri stato, motivo, «aperte»; ordine per arrivo. Il pannello non ha una ricerca qui. */
internal class ReportsViewModel(
    private val repository: EditorialRepository,
    private val source: ModerationSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    /* Senza ricerca: la tabella del pannello non ne ha una. */
    val list = PagedListController(viewModelScope, initialQuery(saved, LIST, FILTERS, SORTS).copy(q = "")) { query, page ->
        repository.call { source.reports(query.params(page)) }
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
        const val LIST = "reports"
        const val FILTER_STATUS = "status"
        const val FILTER_REASON = "reason"
        const val FILTER_OPEN = "open"
        val FILTERS = setOf(FILTER_STATUS, FILTER_REASON, FILTER_OPEN)
        /* La colonna «Arrivata» si ordina; il predefinito è dalla più recente. */
        const val SORT_OLDEST = "created_at"
        val SORTS = setOf(SORT_OLDEST)
    }
}

internal data class ReportDetailUiState(
    val report: Loadable<EditorialReport> = Loadable.Loading,
    /* Il modulo «Modifica» del pannello: stato e nota di risoluzione. */
    val status: String = "",
    val note: String = "",
    val saving: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
    val confirmingDelete: Boolean = false,
    val deleting: Boolean = false,
    val deleteError: String? = null,
    /* Eliminata: la schermata torna all'elenco. */
    val deleted: Boolean = false,
    /* Salvata o eliminata: l'elenco, tornando indietro, si ricarica. */
    val changed: Boolean = false,
) {
    val busy: Boolean get() = saving || deleting
    val noteTooLong: Boolean get() = note.length > ModerationLimits.RESOLUTION_NOTE_MAX
    val canSave: Boolean get() = !busy && status in ReportStatusValue.all && !noteTooLong
}

/** Una segnalazione (R-MD-02…04): esito con stato e nota, eliminazione (password recente), «Modera il soggetto» per la community. */
internal class ReportDetailViewModel(
    private val repository: EditorialRepository,
    private val source: ModerationSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow(ReportDetailUiState(status = saved[KEY_STATUS] ?: "", note = saved[KEY_NOTE] ?: ""))
    val uiState: StateFlow<ReportDetailUiState> = state.asStateFlow()
    private val optionsState = MutableStateFlow<ManagementOptions?>(null)
    val options: StateFlow<ManagementOptions?> = optionsState.asStateFlow()

    init {
        load()
        viewModelScope.launch { loadOptions(repository, optionsState) }
    }

    fun load() {
        state.update { it.copy(report = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val report = repository.call { source.report(id) }
                /* Un modulo già toccato (rotazione) resta com'era; altrimenti parte dall'esito salvato. */
                val draftStatus = saved.get<String>(KEY_STATUS)
                val draftNote = saved.get<String>(KEY_NOTE)
                state.update { it.copy(report = Loadable.Ready(report), status = draftStatus ?: report.status, note = draftNote ?: report.resolutionNote.orEmpty()) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(report = Loadable.Failed(requestFailureMessage(e))) }
            }
        }
    }

    fun setStatus(value: String) {
        saved[KEY_STATUS] = value
        state.update { it.copy(status = value, message = null, errors = it.errors.copy(fields = it.errors.fields - FIELD_STATUS)) }
    }

    fun setNote(value: String) {
        saved[KEY_NOTE] = value
        state.update { it.copy(note = value, message = null, errors = it.errors.copy(fields = it.errors.fields - FIELD_NOTE)) }
    }

    fun save() {
        val now = state.value
        if (!now.canSave) return
        state.update { it.copy(saving = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val note = now.note.takeIf { it.isNotBlank() }
                val result = repository.call { source.resolveReport(id, now.status, note) }
                saved.remove<String>(KEY_STATUS)
                saved.remove<String>(KEY_NOTE)
                state.update { it.copy(saving = false, report = Loadable.Ready(result.data), status = result.data.status, note = result.data.resolutionNote.orEmpty(),
                    message = messageOf(result.meta.message, R.string.moderation_report_saved)) }
                markChanged()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(saving = false, errors = FormErrors.from(e, listOf(FIELD_STATUS, FIELD_NOTE))) }
            }
        }
    }

    fun askDelete() = state.update { it.copy(confirmingDelete = true, deleteError = null) }
    fun dismissDelete() = state.update { if (it.deleting) it else it.copy(confirmingDelete = false, deleteError = null) }

    fun delete() {
        if (state.value.deleting) return
        state.update { it.copy(deleting = true, deleteError = null) }
        viewModelScope.launch {
            try {
                repository.call { source.deleteReport(id) }
                state.update { it.copy(deleting = false, confirmingDelete = false, deleted = true) }
                markChanged()
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(deleting = false, deleteError = requestFailureMessage(e)) }
            }
        }
    }

    private fun markChanged() = state.update { it.copy(changed = true) }

    companion object {
        const val KEY_ID = "id"
        private const val KEY_STATUS = "draft_status"
        private const val KEY_NOTE = "draft_note"
        const val FIELD_STATUS = "status"
        const val FIELD_NOTE = "resolution_note"
    }
}

// --------------------------------------------------------------- recensioni

/** Una finestra aperta sulla riga: approvare o rifiutare (con nota), togliere la risposta. */
internal sealed interface ReviewDialog {
    val review: EditorialReview
    data class Moderate(override val review: EditorialReview, val status: String) : ReviewDialog
    data class RemoveReply(override val review: EditorialReview) : ReviewDialog
}

internal data class ReviewsUiState(
    val dialog: ReviewDialog? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
)

/** Recensioni (R-MD-05…07): solo admin e super admin. Filtri tipo e stato, ricerca sull'autore, approva/rifiuta con revisione, togli la risposta. */
internal class ReviewsViewModel(
    private val repository: EditorialRepository,
    private val source: ModerationSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, initialQuery(saved, LIST, FILTERS, SORTS)) { query, page ->
        repository.call { source.reviews(query.params(page)) }
    }
    private val state = MutableStateFlow(ReviewsUiState())
    val uiState: StateFlow<ReviewsUiState> = state.asStateFlow()
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

    fun askApprove(review: EditorialReview) = open(ReviewDialog.Moderate(review, ReviewStatusValue.APPROVED))
    fun askReject(review: EditorialReview) = open(ReviewDialog.Moderate(review, ReviewStatusValue.REJECTED))
    fun askRemoveReply(review: EditorialReview) = open(ReviewDialog.RemoveReply(review))
    fun dismiss() = state.update { if (it.busy) it else it.copy(dialog = null, errors = FormErrors.None) }

    private fun open(dialog: ReviewDialog) = state.update { it.copy(dialog = dialog, errors = FormErrors.None, message = null) }

    /** La nota: facoltativa per approvare (ma da 5 caratteri, se c'è), obbligatoria per rifiutare. */
    fun moderate(note: String) {
        val dialog = state.value.dialog as? ReviewDialog.Moderate ?: return
        if (state.value.busy || !reviewNoteAccepted(dialog.status, note)) return
        decide { source.moderateReview(dialog.review.id, dialog.status, dialog.review.revision, note.trim().takeIf { it.isNotEmpty() }).let { it.data to it.meta.message } }
    }

    fun removeReply() {
        val dialog = state.value.dialog as? ReviewDialog.RemoveReply ?: return
        if (state.value.busy) return
        decide { source.removeReply(dialog.review.id).let { it.data to it.meta.message } }
    }

    private fun decide(call: suspend () -> Pair<EditorialReview, String?>) {
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val (row, message) = repository.call { call() }
                list.replace({ it.id == row.id }, row)
                state.update { it.copy(busy = false, dialog = null, message = messageOf(message, R.string.moderation_done)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                if (isModerationConflict(e)) {
                    state.update { it.copy(busy = false, dialog = null, message = conflictText(e)) }
                    list.refresh()
                } else {
                    state.update { it.copy(busy = false, errors = FormErrors.from(e, listOf(FIELD_NOTE))) }
                }
            }
        }
    }

    companion object {
        const val LIST = "reviews"
        const val FIELD_NOTE = "note"
        const val FILTER_TYPE = "type"
        const val FILTER_STATUS = "status"
        val FILTERS = setOf(FILTER_TYPE, FILTER_STATUS)
        const val SORT_NEWEST = "-created_at"
        const val SORT_OLDEST = "created_at"
        val SORTS = setOf(SORT_NEWEST, SORT_OLDEST)
    }
}

/** La regola della nota di una recensione, come il modulo del pannello e `ModerateReviewRequest`. */
internal fun reviewNoteAccepted(status: String, note: String): Boolean {
    val length = note.trim().length
    return when {
        length == 0 -> status != ReviewStatusValue.REJECTED
        else -> length in ModerationLimits.REVIEW_NOTE_MIN..ModerationLimits.REVIEW_NOTE_MAX
    }
}

// ----------------------------------------------------------------- commenti

/** Una finestra aperta sulla riga di un commento. */
internal sealed interface CommentDialog {
    val comment: EditorialComment
    data class Details(override val comment: EditorialComment, val loaded: Loadable<EditorialComment> = Loadable.Loading) : CommentDialog
    data class Hide(override val comment: EditorialComment) : CommentDialog
    data class Restore(override val comment: EditorialComment) : CommentDialog
    data class Delete(override val comment: EditorialComment) : CommentDialog
}

internal data class CommentsUiState(
    val dialog: CommentDialog? = null,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    val message: UiText? = null,
)

/** Commenti agli eventi (R-MD-08…12): filtro di stato, ricerca, dettagli, nascondi con nota, ripristina, elimina (admin, password recente). */
internal class CommentsViewModel(
    private val repository: EditorialRepository,
    private val source: ModerationSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, initialQuery(saved, LIST, FILTERS, SORTS)) { query, page ->
        repository.call { source.comments(query.params(page)) }
    }
    private val state = MutableStateFlow(CommentsUiState())
    val uiState: StateFlow<CommentsUiState> = state.asStateFlow()
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

    /** «Dettagli»: la riga si rilegge dal server, con nota e data della moderazione aggiornate. */
    fun showDetails(comment: EditorialComment) {
        state.update { it.copy(dialog = CommentDialog.Details(comment), errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            val loaded: Loadable<EditorialComment> = try {
                Loadable.Ready(repository.call { source.comment(comment.id) })
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
            state.update { now -> val open = now.dialog; if (open is CommentDialog.Details && open.comment.id == comment.id) now.copy(dialog = open.copy(loaded = loaded)) else now }
        }
    }

    fun askHide(comment: EditorialComment) = open(CommentDialog.Hide(comment))
    fun askRestore(comment: EditorialComment) = open(CommentDialog.Restore(comment))
    fun askDelete(comment: EditorialComment) = open(CommentDialog.Delete(comment))
    fun dismiss() = state.update { if (it.busy) it else it.copy(dialog = null, errors = FormErrors.None) }

    private fun open(dialog: CommentDialog) = state.update { it.copy(dialog = dialog, errors = FormErrors.None, message = null) }

    fun hide(note: String) {
        val dialog = state.value.dialog as? CommentDialog.Hide ?: return
        if (state.value.busy || note.trim().length > ModerationLimits.COMMENT_NOTE_MAX) return
        act { source.hideComment(dialog.comment.id, dialog.comment.revision, note.trim().takeIf { it.isNotEmpty() }).let { result -> list.replace({ it.id == result.data.id }, result.data); result.meta.message } }
    }

    fun restore() {
        val dialog = state.value.dialog as? CommentDialog.Restore ?: return
        if (state.value.busy) return
        act { source.restoreComment(dialog.comment.id, dialog.comment.revision).let { result -> list.replace({ it.id == result.data.id }, result.data); result.meta.message } }
    }

    fun delete() {
        val dialog = state.value.dialog as? CommentDialog.Delete ?: return
        if (state.value.busy) return
        act { source.deleteComment(dialog.comment.id).let { result -> list.remove { it.id == dialog.comment.id }; result.meta.message } }
    }

    private fun act(call: suspend () -> String?) {
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val message = repository.call { call() }
                state.update { it.copy(busy = false, dialog = null, message = messageOf(message, R.string.moderation_done)) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                if (isModerationConflict(e)) {
                    state.update { it.copy(busy = false, dialog = null, message = conflictText(e)) }
                    list.refresh()
                } else {
                    state.update { it.copy(busy = false, errors = FormErrors.from(e, listOf(FIELD_NOTE))) }
                }
            }
        }
    }

    companion object {
        const val LIST = "comments"
        const val FIELD_NOTE = "note"
        const val FILTER_STATUS = "status"
        val FILTERS = setOf(FILTER_STATUS)
        /* «Mi piace» è la colonna ordinabile della tabella; il predefinito è dal più recente. */
        const val SORT_MOST_LIKED = "-reactions_count"
        val SORTS = setOf(SORT_MOST_LIKED)
    }
}
