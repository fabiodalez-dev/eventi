package it.fabiodalez.incitta.ui.management.feedback

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.management.COMMENT_NOTE_MAX
import it.fabiodalez.incitta.data.management.CommentStatus
import it.fabiodalez.incitta.data.management.ManagedComment
import it.fabiodalez.incitta.data.management.ManagedReview
import it.fabiodalez.incitta.data.management.ManagementFeedbackSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.REVIEW_REPLY_MAX
import it.fabiodalez.incitta.data.management.REVIEW_REPLY_MIN
import it.fabiodalez.incitta.data.management.REVIEW_REPORT_MAX
import it.fabiodalez.incitta.data.management.REVIEW_REPORT_MIN
import it.fabiodalez.incitta.data.requestFailureMessage
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

/*
 * Commenti e recensioni (P7), come le pagine «Commenti agli eventi» e
 * «Recensioni ricevute» dei pannelli. Il soggetto arriva dalla rotta: per
 * chiave (`venue:12`) quando si entra dal menu, per tipo e slug quando arriva
 * da un avviso o dalla scheda pubblica (`/gestione/{slug}/event-comments`).
 * I permessi delle singole righe (`can_hide`, `can_reply`, …) li decide il
 * server; qui si mostrano solo i pulsanti che lui concede.
 */

/** Il soggetto della schermata; `null` se la persona non lo gestisce (più). */
internal suspend fun resolveFeedbackSubject(repository: ManagementRepository, key: String?, kind: String?, slug: String?): ManagementSubject? {
    val byRoute: (ManagementSubjects) -> ManagementSubject? = { list ->
        list.find(key?.takeIf { it.isNotBlank() })
            ?: list.all().firstOrNull { !kind.isNullOrBlank() && !slug.isNullOrBlank() && it.kind.name.equals(kind, ignoreCase = true) && it.slug == slug }
            ?: if (key.isNullOrBlank() && slug.isNullOrBlank()) list.find(repository.selectedSubjectKey) ?: list.all().firstOrNull() else null
    }
    /* L'elenco in memoria basta quasi sempre; un soggetto appena aggiunto si cerca rileggendolo una volta. */
    val found = repository.subjects.value?.let(byRoute) ?: byRoute(repository.loadSubjects())
    found?.let { repository.selectedSubjectKey = it.key }
    return found
}

private fun ManagementRepository.feedbackApi(): ManagementFeedbackSource =
    feedback ?: throw IllegalStateException("Commenti e recensioni non disponibili in questa sessione")

/** Vero quando il server rifiuta perché qualcun altro ha deciso nel frattempo (`error.fields.revision`). */
internal fun isRevisionConflict(error: Throwable): Boolean =
    error is ApiException && error.status == 422 && error.problem?.fields?.containsKey("revision") == true

private fun conflictMessage(error: Throwable): UiText =
    (error as? ApiException)?.problem?.fields?.get("revision")?.firstOrNull()?.takeIf { it.isNotBlank() }?.let(UiText::Raw)
        ?: UiText.Res(R.string.feedback_changed)

// ------------------------------------------------------------------- menu

internal data class FeedbackHubUiState(val subject: Loadable<ManagementSubject> = Loadable.Loading)

/** La prima schermata dell'area: le due voci del gruppo «Community» del pannello. */
internal class FeedbackHubViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(FeedbackHubUiState())
    val uiState: StateFlow<FeedbackHubUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(subject = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val subject = resolveFeedbackSubject(repository, key, null, null)
                state.update { it.copy(subject = subject?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(subject = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    companion object { const val KEY_SUBJECT = "subject" }
}

// ---------------------------------------------------------------- commenti

internal data class CommentsUiState(
    val subject: Loadable<ManagementSubject> = Loadable.Loading,
    /* `null` = tutti, come il filtro vuoto del pannello. */
    val status: String? = null,
    val query: String = "",
    val eventId: Long? = null,
    val eventTitle: String? = null,
    val comments: List<ManagedComment> = emptyList(),
    val total: Int = 0,
    val nextPage: Int? = null,
    val loaded: Boolean = false,
    val busy: Boolean = false,
    val error: String? = null,
    val message: UiText? = null,
    val hiding: ManagedComment? = null,
    val restoring: ManagedComment? = null,
    val dialogBusy: Boolean = false,
    val dialogErrors: FormErrors = FormErrors.None,
)

internal class CommentsModerationViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val kind: String? = saved[KEY_KIND]
    private val slug: String? = saved[KEY_SLUG]
    private val state = MutableStateFlow(CommentsUiState(
        status = saved.get<String>(KEY_STATUS)?.takeIf { it == CommentStatus.PUBLISHED || it == CommentStatus.HIDDEN },
        query = saved[KEY_QUERY] ?: "",
        eventId = saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 },
    ))
    val uiState: StateFlow<CommentsUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { start() }

    /** Trova il soggetto e carica la prima pagina; «Riprova» riparte da qui. */
    fun start() {
        state.update { it.copy(subject = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val subject = resolveFeedbackSubject(repository, key, kind, slug)
                state.update { it.copy(subject = subject?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
                if (subject != null) load(page = 1, clear = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(subject = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun showStatus(status: String?) {
        if (state.value.status == status) return
        saved[KEY_STATUS] = status
        state.update { it.copy(status = status) }
        load(page = 1, clear = true)
    }

    fun search(query: String) {
        val clean = query.trim().take(QUERY_MAX)
        saved[KEY_QUERY] = clean
        state.update { it.copy(query = clean) }
        load(page = 1, clear = true)
    }

    /** Toglie il filtro dell'evento con cui si è arrivati dalla scheda pubblica. */
    fun showAllEvents() {
        saved[KEY_EVENT] = 0L
        state.update { it.copy(eventId = null, eventTitle = null) }
        load(page = 1, clear = true)
    }

    fun refresh() { load(page = 1, clear = false) }
    fun more() { val page = state.value.nextPage ?: return; if (!state.value.busy) load(page, clear = false) }
    fun consumeMessage() { state.update { it.copy(message = null) } }

    fun askHide(comment: ManagedComment) { state.update { it.copy(hiding = comment, restoring = null, dialogErrors = FormErrors.None) } }
    fun askRestore(comment: ManagedComment) { state.update { it.copy(restoring = comment, hiding = null, dialogErrors = FormErrors.None) } }
    fun dismissDialog() { if (!state.value.dialogBusy) state.update { it.copy(hiding = null, restoring = null, dialogErrors = FormErrors.None) } }

    /** «Nascondi», con la nota facoltativa (al massimo mille caratteri) e la revisione letta. */
    fun hide(note: String) {
        val comment = state.value.hiding ?: return
        val subject = state.value.subject.ready() ?: return
        val clean = note.trim()
        /* Il pulsante è spento oltre il limite: questa riga ferma solo una chiamata già sbagliata. */
        if (clean.length > COMMENT_NOTE_MAX) return
        moderate(R.string.feedback_comment_hidden) { repository.feedbackApi().hideComment(subject, comment.id, comment.revision, clean.ifBlank { null }).data }
    }

    /** «Rimetti in vista». */
    fun restore() {
        val comment = state.value.restoring ?: return
        val subject = state.value.subject.ready() ?: return
        moderate(R.string.feedback_comment_restored) { repository.feedbackApi().restoreComment(subject, comment.id, comment.revision).data }
    }

    private fun moderate(done: Int, call: suspend () -> ManagedComment) {
        if (state.value.dialogBusy) return
        state.update { it.copy(dialogBusy = true, dialogErrors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val updated = call()
                state.update { current ->
                    /* Con un filtro di stato la riga che non lo rispetta più esce dall'elenco, come nel pannello. */
                    val keep = current.status == null || current.status == updated.status
                    val list = if (keep) current.comments.map { if (it.id == updated.id) updated else it } else current.comments.filterNot { it.id == updated.id }
                    current.copy(comments = list, total = if (keep) current.total else (current.total - 1).coerceAtLeast(0),
                        hiding = null, restoring = null, message = UiText.Res(done))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                if (isRevisionConflict(e)) {
                    /* Qualcun altro ha deciso: si chiude, si avvisa e si rilegge. */
                    state.update { it.copy(hiding = null, restoring = null, message = conflictMessage(e)) }
                    load(page = 1, clear = false)
                } else {
                    state.update { it.copy(dialogErrors = FormErrors.from(e, listOf(FIELD_NOTE))) }
                }
            } finally { state.update { it.copy(dialogBusy = false) } }
        }
    }

    private fun load(page: Int, clear: Boolean) {
        val subject = state.value.subject.ready() ?: return
        loading?.cancel()
        state.update { if (clear) it.copy(busy = true, error = null, comments = emptyList(), nextPage = null, loaded = false) else it.copy(busy = true, error = null) }
        val current = state.value
        loading = viewModelScope.launch {
            try {
                val response = repository.feedbackApi().comments(subject, current.status, current.query, current.eventId, page)
                state.update {
                    val list = if (page == 1) response.data else (it.comments + response.data).distinctBy(ManagedComment::id)
                    it.copy(comments = list, total = response.meta.total, nextPage = response.meta.nextPage, loaded = true,
                        eventTitle = if (it.eventId != null) response.data.firstOrNull()?.event?.title ?: it.eventTitle else null)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_SUBJECT = "subject"
        const val KEY_KIND = "kind"
        const val KEY_SLUG = "slug"
        const val KEY_EVENT = "event"
        const val KEY_STATUS = "status"
        const val KEY_QUERY = "q"
        const val FIELD_NOTE = "note"
        const val QUERY_MAX = 100
    }
}

// --------------------------------------------------------------- recensioni

internal data class ReviewsUiState(
    val subject: Loadable<ManagementSubject> = Loadable.Loading,
    val reviews: List<ManagedReview> = emptyList(),
    val total: Int = 0,
    val nextPage: Int? = null,
    val loaded: Boolean = false,
    val busy: Boolean = false,
    val error: String? = null,
    val message: UiText? = null,
    val replying: ManagedReview? = null,
    val removing: ManagedReview? = null,
    val reporting: ManagedReview? = null,
    val dialogBusy: Boolean = false,
    val dialogErrors: FormErrors = FormErrors.None,
)

internal class ReceivedReviewsViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val kind: String? = saved[KEY_KIND]
    private val slug: String? = saved[KEY_SLUG]
    private val state = MutableStateFlow(ReviewsUiState())
    val uiState: StateFlow<ReviewsUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { start() }

    fun start() {
        state.update { it.copy(subject = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val subject = resolveFeedbackSubject(repository, key, kind, slug)
                state.update { it.copy(subject = subject?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
                if (subject != null) load(page = 1, clear = true)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(subject = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun refresh() { load(page = 1, clear = false) }
    fun more() { val page = state.value.nextPage ?: return; if (!state.value.busy) load(page, clear = false) }
    fun consumeMessage() { state.update { it.copy(message = null) } }

    fun askReply(review: ManagedReview) { state.update { it.copy(replying = review, removing = null, reporting = null, dialogErrors = FormErrors.None) } }
    fun askRemove(review: ManagedReview) { state.update { it.copy(removing = review, replying = null, reporting = null, dialogErrors = FormErrors.None) } }
    fun askReport(review: ManagedReview) { state.update { it.copy(reporting = review, replying = null, removing = null, dialogErrors = FormErrors.None) } }
    fun dismissDialog() { if (!state.value.dialogBusy) state.update { it.copy(replying = null, removing = null, reporting = null, dialogErrors = FormErrors.None) } }

    /** Scrive o cambia la risposta pubblica: da 2 a 2.000 caratteri, come il campo del pannello. */
    fun reply(text: String) {
        val review = state.value.replying ?: return
        val subject = state.value.subject.ready() ?: return
        val clean = text.trim()
        if (clean.length !in REVIEW_REPLY_MIN..REVIEW_REPLY_MAX) return
        write(R.string.feedback_reply_saved) { repository.feedbackApi().reply(subject, review.id, clean).data }
    }

    /** «Togli la risposta», dopo la conferma. */
    fun removeReply() {
        val review = state.value.removing ?: return
        val subject = state.value.subject.ready() ?: return
        write(R.string.feedback_reply_removed) { repository.feedbackApi().removeReply(subject, review.id).data }
    }

    /** Segnala la recensione alla redazione: da 10 a 3.000 caratteri, come dalla scheda pubblica. */
    fun report(body: String) {
        val review = state.value.reporting ?: return
        val subject = state.value.subject.ready() ?: return
        val clean = body.trim()
        if (clean.length !in REVIEW_REPORT_MIN..REVIEW_REPORT_MAX) return
        if (state.value.dialogBusy) return
        state.update { it.copy(dialogBusy = true, dialogErrors = FormErrors.None) }
        viewModelScope.launch {
            try {
                repository.feedbackApi().report(subject, review.id, clean)
                state.update { it.copy(reporting = null, message = UiText.Res(R.string.feedback_report_sent)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(dialogErrors = FormErrors.from(e, listOf(FIELD_BODY))) } }
            finally { state.update { it.copy(dialogBusy = false) } }
        }
    }

    private fun write(done: Int, call: suspend () -> ManagedReview) {
        if (state.value.dialogBusy) return
        state.update { it.copy(dialogBusy = true, dialogErrors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val updated = call()
                state.update { current ->
                    current.copy(reviews = current.reviews.map { if (it.id == updated.id) updated else it },
                        replying = null, removing = null, message = UiText.Res(done))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                /* Una recensione tolta nel frattempo (404) o un permesso cambiato: si chiude e si rilegge. */
                if (e is ApiException && (e.status == 404 || e.status == 403)) {
                    state.update { it.copy(replying = null, removing = null, message = UiText.Raw(requestFailureMessage(e))) }
                    load(page = 1, clear = false)
                } else {
                    state.update { it.copy(dialogErrors = FormErrors.from(e, listOf(FIELD_REPLY))) }
                }
            } finally { state.update { it.copy(dialogBusy = false) } }
        }
    }

    private fun load(page: Int, clear: Boolean) {
        val subject = state.value.subject.ready() ?: return
        loading?.cancel()
        state.update { if (clear) it.copy(busy = true, error = null, reviews = emptyList(), nextPage = null, loaded = false) else it.copy(busy = true, error = null) }
        loading = viewModelScope.launch {
            try {
                val response = repository.feedbackApi().reviews(subject, page)
                state.update {
                    val list = if (page == 1) response.data else (it.reviews + response.data).distinctBy(ManagedReview::id)
                    it.copy(reviews = list, total = response.meta.total, nextPage = response.meta.nextPage, loaded = true)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_SUBJECT = "subject"
        const val KEY_KIND = "kind"
        const val KEY_SLUG = "slug"
        const val FIELD_REPLY = "reply"
        const val FIELD_BODY = "body"
    }
}

private fun Loadable<ManagementSubject>.ready(): ManagementSubject? = (this as? Loadable.Ready)?.value
