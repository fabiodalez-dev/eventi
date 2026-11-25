package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.FeedbackMeta
import it.fabiodalez.incitta.data.management.ManagedComment
import it.fabiodalez.incitta.data.management.ManagedCommentEvent
import it.fabiodalez.incitta.data.management.ManagedCommentPage
import it.fabiodalez.incitta.data.management.ManagedCommentResult
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedReview
import it.fabiodalez.incitta.data.management.ManagedReviewPage
import it.fabiodalez.incitta.data.management.ManagedReviewReply
import it.fabiodalez.incitta.data.management.ManagedReviewResult
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementFeedbackSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.ReviewReportResult
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.feedback.CommentsModerationViewModel
import it.fabiodalez.incitta.ui.management.feedback.ReceivedReviewsViewModel
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Commenti e recensioni (P7) con un server finto: stesse azioni e stesse regole delle pagine dei pannelli. */
@OptIn(ExperimentalCoroutinesApi::class)
class FeedbackViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val permissions = mapOf("comments_moderate" to true, "reviews_reply" to true)
    private val subjects = ManagementSubjects(
        venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "editor", permissions = permissions)),
        organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo-portici", name = "Collettivo Portici", role = "member", permissions = permissions)),
    )

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun repository(scope: CoroutineScope, feedback: FakeFeedback) =
        ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), scope, feedback = feedback)

    @Test fun commentsOpenOnTheSubjectOfTheRouteAndKeepTheirFilters() = runTest(dispatcher) {
        val feedback = FakeFeedback()
        val saved = SavedStateHandle(mapOf("kind" to "organizer", "slug" to "collettivo-portici", "event" to 41L))
        val viewModel = CommentsModerationViewModel(repository(TestScope(testScheduler), feedback), saved)
        assertEquals("organizer:3", (viewModel.uiState.value.subject as Loadable.Ready).value.key)
        assertEquals("comments:organizer:3:null::41:1", feedback.calls.last())
        assertEquals("Concerto della sera", viewModel.uiState.value.eventTitle)
        assertEquals(2, viewModel.uiState.value.nextPage)

        viewModel.more()
        assertEquals(listOf(5L, 6L), viewModel.uiState.value.comments.map(ManagedComment::id))
        viewModel.showStatus("hidden")
        viewModel.search("  parcheggio ")
        assertEquals("comments:organizer:3:hidden:parcheggio:41:1", feedback.calls.last())
        viewModel.showAllEvents()
        assertEquals("comments:organizer:3:hidden:parcheggio:null:1", feedback.calls.last())

        /* Dopo la morte del processo la schermata rinasce con gli stessi filtri. */
        val restored = CommentsModerationViewModel(repository(TestScope(testScheduler), feedback), saved)
        assertEquals("hidden", restored.uiState.value.status)
        assertEquals("parcheggio", restored.uiState.value.query)
        assertNull(restored.uiState.value.eventId)
    }

    @Test fun hidingSendsTheRevisionAndTheNoteAndLeavesTheFilteredList() = runTest(dispatcher) {
        val feedback = FakeFeedback()
        val viewModel = CommentsModerationViewModel(repository(TestScope(testScheduler), feedback), SavedStateHandle(mapOf("subject" to "venue:12", "status" to "published")))
        val comment = viewModel.uiState.value.comments.first()
        viewModel.askHide(comment)
        viewModel.hide("  Fuori tema  ")
        assertEquals("hide:venue:12:5:1:Fuori tema", feedback.calls.last())
        assertNull(viewModel.uiState.value.hiding)
        /* Filtro «Pubblicati»: il commento appena nascosto esce dall'elenco. */
        assertFalse(viewModel.uiState.value.comments.any { it.id == 5L })
        assertEquals(UiText.Res(R.string.feedback_comment_hidden), viewModel.uiState.value.message)

        /* Una nota vuota non si manda. */
        viewModel.askHide(viewModel.uiState.value.comments.first())
        viewModel.hide("   ")
        assertEquals("hide:venue:12:6:1:null", feedback.calls.last())
    }

    @Test fun aRevisionConflictClosesTheDialogAndReloads() = runTest(dispatcher) {
        val feedback = FakeFeedback().apply {
            failure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("revision" to listOf("Il commento è cambiato."))))
        }
        val viewModel = CommentsModerationViewModel(repository(TestScope(testScheduler), feedback), SavedStateHandle(mapOf("subject" to "venue:12")))
        viewModel.askRestore(viewModel.uiState.value.comments.first())
        viewModel.restore()
        assertNull(viewModel.uiState.value.restoring)
        assertEquals(UiText.Raw("Il commento è cambiato."), viewModel.uiState.value.message)
        assertEquals("comments:venue:12:null::null:1", feedback.calls.last())
    }

    @Test fun aNoteTooLongStaysUnderTheField() = runTest(dispatcher) {
        val feedback = FakeFeedback().apply {
            failure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("note" to listOf("Al massimo 1000 caratteri."))))
        }
        val viewModel = CommentsModerationViewModel(repository(TestScope(testScheduler), feedback), SavedStateHandle(mapOf("subject" to "venue:12")))
        viewModel.askHide(viewModel.uiState.value.comments.first())
        viewModel.hide("Motivo")
        assertNotNull(viewModel.uiState.value.hiding)
        assertEquals("Al massimo 1000 caratteri.", viewModel.uiState.value.dialogErrors.field("note"))
    }

    @Test fun aSubjectNoLongerManagedShowsTheEmptyState() = runTest(dispatcher) {
        val feedback = FakeFeedback()
        val viewModel = ReceivedReviewsViewModel(repository(TestScope(testScheduler), feedback), SavedStateHandle(mapOf("kind" to "venue", "slug" to "chiuso")))
        assertEquals(Loadable.Empty, viewModel.uiState.value.subject)
        assertTrue(feedback.calls.isEmpty())
    }

    @Test fun reviewsReplyChangeRemoveAndReport() = runTest(dispatcher) {
        val feedback = FakeFeedback()
        val viewModel = ReceivedReviewsViewModel(repository(TestScope(testScheduler), feedback), SavedStateHandle(mapOf("subject" to "venue:12")))
        assertEquals("reviews:venue:12:1", feedback.calls.last())
        val review = viewModel.uiState.value.reviews.single()

        viewModel.askReply(review)
        viewModel.reply("x")
        assertNotEquals("reply:venue:12:7:x", feedback.calls.last())
        viewModel.reply("  Grazie della visita!  ")
        assertEquals("reply:venue:12:7:Grazie della visita!", feedback.calls.last())
        assertEquals("Grazie della visita!", viewModel.uiState.value.reviews.single().givenReply?.body)
        assertEquals(UiText.Res(R.string.feedback_reply_saved), viewModel.uiState.value.message)

        viewModel.askRemove(viewModel.uiState.value.reviews.single())
        viewModel.removeReply()
        assertEquals("remove:venue:12:7", feedback.calls.last())
        assertNull(viewModel.uiState.value.reviews.single().givenReply)

        viewModel.askReport(viewModel.uiState.value.reviews.single())
        viewModel.report("Troppo corto")
        assertEquals("report:venue:12:7:Troppo corto", feedback.calls.last())
        assertNull(viewModel.uiState.value.reporting)
        assertEquals(UiText.Res(R.string.feedback_report_sent), viewModel.uiState.value.message)
    }
}

private class FakeFeedback : ManagementFeedbackSource {
    val calls = mutableListOf<String>()
    var failure: Exception? = null
    private var reply: String? = null
    private val event = ManagedCommentEvent(41, "Concerto della sera", "concerto")

    override suspend fun comments(subject: ManagementSubject, status: String?, query: String?, eventId: Long?, page: Int): ManagedCommentPage {
        calls += "comments:${subject.key}:$status:${query.orEmpty()}:$eventId:$page"
        val ids = if (page == 1) listOf(5L, 6L) else listOf(6L)
        return ManagedCommentPage(ids.map { ManagedComment(id = it, event = event, body = "Testo $it", status = "published", revision = 1, canHide = true) },
            FeedbackMeta(nextPage = if (page == 1) 2 else null, total = 2))
    }
    override suspend fun hideComment(subject: ManagementSubject, commentId: Long, revision: Int, note: String?): ManagedCommentResult {
        calls += "hide:${subject.key}:$commentId:$revision:$note"
        failure?.let { throw it }
        return ManagedCommentResult(ManagedComment(id = commentId, event = event, status = "hidden", revision = revision + 1, canRestore = true))
    }
    override suspend fun restoreComment(subject: ManagementSubject, commentId: Long, revision: Int): ManagedCommentResult {
        calls += "restore:${subject.key}:$commentId:$revision"
        failure?.let { throw it }
        return ManagedCommentResult(ManagedComment(id = commentId, event = event, status = "published", revision = revision + 1, canHide = true))
    }
    override suspend fun reviews(subject: ManagementSubject, page: Int): ManagedReviewPage {
        calls += "reviews:${subject.key}:$page"
        return ManagedReviewPage(listOf(review()), FeedbackMeta(total = 1))
    }
    override suspend fun reply(subject: ManagementSubject, reviewId: Long, text: String): ManagedReviewResult {
        calls += "reply:${subject.key}:$reviewId:$text"
        reply = text
        return ManagedReviewResult(review())
    }
    override suspend fun removeReply(subject: ManagementSubject, reviewId: Long): ManagedReviewResult {
        calls += "remove:${subject.key}:$reviewId"
        reply = null
        return ManagedReviewResult(review())
    }
    override suspend fun report(subject: ManagementSubject, reviewId: Long, body: String): ReviewReportResult {
        calls += "report:${subject.key}:$reviewId:$body"
        return ReviewReportResult()
    }
    private fun review() = ManagedReview(id = 7, author = "Marco", rating = 4, body = "Cena ottima", reply = reply?.let { ManagedReviewReply(it, "Circolo Aurora") },
        canReply = true, canRemoveReply = reply != null, canReport = true)
}
