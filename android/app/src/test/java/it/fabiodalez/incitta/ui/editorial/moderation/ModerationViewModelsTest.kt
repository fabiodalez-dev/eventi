package it.fabiodalez.incitta.ui.editorial.moderation

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.DeletedRow
import it.fabiodalez.incitta.data.editorial.EditorialComment
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialLockReason
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialReport
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialReview
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.ModerationMeta
import it.fabiodalez.incitta.data.editorial.ModerationResult
import it.fabiodalez.incitta.data.editorial.ModerationSource
import it.fabiodalez.incitta.data.editorial.ReviewOwnerReply
import it.fabiodalez.incitta.data.editorial.editorialPath
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R6 con un server finto: le voci per ruolo, le regole delle note, gli elenchi con i filtri, le azioni con revisione e password. */
@OptIn(ExperimentalCoroutinesApi::class)
class ModerationViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private class FakeModeration : ModerationSource {
        val calls = mutableListOf<String>()
        var reports = listOf(EditorialReport(id = 1, status = "pending", reason = "spam"))
        var reviews = listOf<EditorialReview>()
        var comments = listOf<EditorialComment>()
        var failNext: Exception? = null

        private fun maybeFail() { failNext?.let { failNext = null; throw it } }
        private fun <T> page(rows: List<T>) = EditorialPage(rows, PageMeta(currentPage = 1, lastPage = 1, total = rows.size))

        override suspend fun reports(params: List<Pair<String, String>>): EditorialPage<EditorialReport> { calls += "reports:" + params.joinToString("&") { "${it.first}=${it.second}" }; return page(reports) }
        override suspend fun report(id: Long): EditorialReport { calls += "report:$id"; return reports.first { it.id == id } }
        override suspend fun resolveReport(id: Long, status: String, resolutionNote: String?): ModerationResult<EditorialReport> {
            calls += "resolve:$id:$status:$resolutionNote"
            maybeFail()
            return ModerationResult(reports.first { it.id == id }.copy(status = status, resolutionNote = resolutionNote, reviewedBy = "Ada"), ModerationMeta("Esito salvato."))
        }
        override suspend fun deleteReport(id: Long): ModerationResult<DeletedRow> { calls += "delete-report:$id"; maybeFail(); return ModerationResult(DeletedRow(id, true)) }
        override suspend fun reviews(params: List<Pair<String, String>>): EditorialPage<EditorialReview> { calls += "reviews:" + params.joinToString("&") { "${it.first}=${it.second}" }; return page(reviews) }
        override suspend fun moderateReview(id: Long, status: String, revision: Int, note: String?): ModerationResult<EditorialReview> {
            calls += "moderate:$id:$status:$revision:$note"
            maybeFail()
            return ModerationResult(reviews.first { it.id == id }.copy(status = status, revision = revision + 1, moderationNote = note))
        }
        override suspend fun removeReply(id: Long): ModerationResult<EditorialReview> {
            calls += "remove-reply:$id"
            return ModerationResult(reviews.first { it.id == id }.copy(reply = null, abilities = null), ModerationMeta("Risposta tolta."))
        }
        override suspend fun comments(params: List<Pair<String, String>>): EditorialPage<EditorialComment> { calls += "comments:" + params.joinToString("&") { "${it.first}=${it.second}" }; return page(comments) }
        override suspend fun comment(id: Long): EditorialComment { calls += "comment:$id"; return comments.first { it.id == id }.copy(moderationNote = "Fuori tema") }
        override suspend fun hideComment(id: Long, revision: Int, note: String?): ModerationResult<EditorialComment> {
            calls += "hide:$id:$revision:$note"
            maybeFail()
            return ModerationResult(comments.first { it.id == id }.copy(status = "hidden", revision = revision + 1, moderationNote = note))
        }
        override suspend fun restoreComment(id: Long, revision: Int): ModerationResult<EditorialComment> {
            calls += "restore:$id:$revision"
            return ModerationResult(comments.first { it.id == id }.copy(status = "published", revision = revision + 1))
        }
        override suspend fun deleteComment(id: Long): ModerationResult<DeletedRow> { calls += "delete-comment:$id"; maybeFail(); return ModerationResult(DeletedRow(id, true)) }
    }

    private val desk = FakeDesk()
    private val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))

    private fun conflict() = ApiException(422, ApiProblem(message = "x", code = "VALIDATION_FAILED", fields = mapOf("revision" to listOf("Il commento è cambiato."))))
    private fun passwordRequired() = ApiException(423, ApiProblem(message = "Password", code = "PASSWORD_REQUIRED"))

    @Test fun theModeratorDoesNotSeeTheReviewsLikeInThePanel() {
        val moderator = EditorialPermissions(mapOf("reports_resolve" to true, "comments_moderate" to true, "reviews_moderate" to false))
        val admin = EditorialPermissions(mapOf("reports_resolve" to true, "comments_moderate" to true, "reviews_moderate" to true))
        assertEquals(listOf(ModerationSection.Reports, ModerationSection.Comments), moderationSections(moderator))
        assertEquals(ModerationSection.entries, moderationSections(admin))
        assertEquals(emptyList<ModerationSection>(), moderationSections(EditorialPermissions()))
        assertEquals("catalog_reviews_pending", ModerationSection.Reviews.badge)
    }

    @Test fun theReviewNoteFollowsThePanelForm() {
        assertTrue(reviewNoteAccepted("approved", ""))
        assertFalse(reviewNoteAccepted("approved", " ok "))
        assertTrue(reviewNoteAccepted("approved", "Va bene così"))
        assertFalse(reviewNoteAccepted("rejected", "   "))
        assertFalse(reviewNoteAccepted("rejected", "no"))
        assertTrue(reviewNoteAccepted("rejected", "Contiene un insulto"))
        assertFalse(reviewNoteAccepted("rejected", "a".repeat(1001)))
    }

    @Test fun filtersSurviveInTheSavedStateAndUnknownKeysAreDropped() {
        assertEquals("open=1;status=pending", encodeFilters(mapOf("status" to "pending", "open" to "1")))
        assertEquals(mapOf("status" to "pending", "open" to "1"), decodeFilters("open=1;status=pending;city=padova;reason=", setOf("status", "reason", "open")))
        assertEquals(emptyMap<String, String>(), decodeFilters(null, setOf("status")))
    }

    @Test fun thePathsAreTheOnesOfTheServer() {
        assertEquals("editorial/reports?page=2&per_page=20&filter[status]=pending", editorialPath("reports", null, listOf("page" to "2", "per_page" to "20", "filter[status]" to "pending")))
        assertEquals("editorial/reviews/7/moderate", editorialPath("reviews/7/moderate", null))
    }

    @Test fun theServerRowsAreReadWithTheirAbilities() {
        val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
        val report = json.decodeFromString<EditorialReport>(
            """{"id":3,"reason":"spam","reason_label":"Spam","status":"reviewing","status_label":"In esame","subject":{"type":"community_post","id":9,"label":"Post · Ciao","community":true},"abilities":{"resolve":true,"destroy":false},"extra":1}""",
        )
        assertTrue(report.open)
        assertTrue(report.subject.community)
        assertEquals(mapOf("resolve" to true, "destroy" to false), abilitiesOf(report.abilities))
        val review = json.decodeFromString<EditorialReview>("""{"id":4,"rating":7,"reply":{"body":"Grazie"},"abilities":[]}""")
        assertNull(review.stars)
        assertEquals("Grazie", review.reply?.body)
        assertEquals(emptyMap<String, Boolean>(), abilitiesOf(review.abilities))
        val comment = json.decodeFromString<EditorialComment>("""{"id":5,"status":"hidden","event":{"id":1,"title":"Concerto","poster":{"thumb":"https://x/p.webp"}},"revision":2}""")
        assertTrue(comment.hidden)
        assertEquals("https://x/p.webp", comment.event?.poster?.thumb)
    }

    @Test fun reportsLoadWithTheSavedFiltersAndSortWithoutSearch() {
        val source = FakeModeration()
        val saved = SavedStateHandle(mapOf("filters" to "open=1", "sort" to "created_at", "q" to "ignorata"))
        val viewModel = ReportsViewModel(repository, source, saved)
        assertEquals(listOf(1L), viewModel.list.current.value.items.map { it.id })
        assertEquals("reports:page=1&per_page=20&filter[open]=1&sort=created_at", source.calls.first())
        viewModel.applyFilters(mapOf("status" to "resolved"))
        assertEquals("status=resolved", saved.get<String>("filters"))
        assertEquals("reports:page=1&per_page=20&filter[status]=resolved&sort=created_at", source.calls.last())
        viewModel.sortBy(null)
        assertEquals("reports:page=1&per_page=20&filter[status]=resolved", source.calls.last())
    }

    @Test fun theOpenReportsTileOpensTheListWithItsFilterOnceAndThenTheChosenOnesWin() {
        val source = FakeModeration()
        /* Gli argomenti di `ModerationReportsRoute(filter = "open:1")`, la rotta di «Segnalazioni aperte» del riepilogo. */
        val saved = SavedStateHandle(mapOf("tab" to "", "filter" to "open:1"))
        val viewModel = ReportsViewModel(repository, source, saved)
        assertEquals("reports:page=1&per_page=20&filter[open]=1", source.calls.first())
        assertEquals(mapOf("open" to "1"), viewModel.list.current.value.query.filters)
        viewModel.applyFilters(mapOf("status" to "resolved"))
        /* Il sistema riapre l'app: restano i filtri scelti, non quelli del riquadro. */
        ReportsViewModel(repository, source, saved)
        assertEquals("reports:page=1&per_page=20&filter[status]=resolved", source.calls.last())
        /* Dal menu dell'area, senza argomenti: nessun filtro. */
        ReportsViewModel(repository, source, SavedStateHandle(mapOf("tab" to "", "filter" to "")))
        assertEquals("reports:page=1&per_page=20", source.calls.last())
    }

    @Test fun commentsAndReviewsTakeOnlyTheirOwnFiltersFromTheRoute() {
        val source = FakeModeration()
        CommentsViewModel(repository, source, SavedStateHandle(mapOf("filter" to "status:hidden,sconosciuto:1")))
        assertTrue(source.calls.last(), source.calls.last().startsWith("comments:") && source.calls.last().contains("filter[status]=hidden") && !source.calls.last().contains("sconosciuto"))
        ReviewsViewModel(repository, source, SavedStateHandle(mapOf("filter" to "status:pending,type:venue")))
        assertTrue(source.calls.last(), source.calls.last().startsWith("reviews:") && source.calls.last().contains("filter[status]=pending") && source.calls.last().contains("filter[type]=venue"))
    }

    @Test fun aReportIsResolvedWithStatusAndNoteAndTheListIsToldToReload() {
        val source = FakeModeration().apply { reports = listOf(EditorialReport(id = 8, status = "pending", resolutionNote = null, abilities = abilities("resolve", "destroy"))) }
        val viewModel = ReportDetailViewModel(repository, source, SavedStateHandle(mapOf("id" to 8L)))
        assertTrue(viewModel.uiState.value.report is Loadable.Ready)
        assertEquals("pending", viewModel.uiState.value.status)
        viewModel.setStatus("resolved")
        viewModel.setNote("Orario corretto con il locale.")
        viewModel.save()
        assertEquals("resolve:8:resolved:Orario corretto con il locale.", source.calls.last())
        val state = viewModel.uiState.value
        assertEquals("Ada", (state.report as Loadable.Ready).value.reviewedBy)
        assertEquals(UiText.Raw("Esito salvato."), state.message)
        assertTrue(state.changed)
        assertFalse(state.deleted)
        /* Uno stato che non esiste non parte. */
        viewModel.setStatus("chiusa")
        assertFalse(viewModel.uiState.value.canSave)
    }

    @Test fun deletingAReportAsksThePasswordAndThenRetries() {
        val source = FakeModeration().apply { reports = listOf(EditorialReport(id = 8, status = "pending")); failNext = passwordRequired() }
        val viewModel = ReportDetailViewModel(repository, source, SavedStateHandle(mapOf("id" to 8L)))
        viewModel.askDelete()
        viewModel.delete()
        assertEquals(EditorialLockReason.PasswordRequired, repository.guard.prompt.value?.reason)
        repository.guard.submit("segreta")
        assertEquals(listOf("delete-report:8", "delete-report:8"), source.calls.filter { it.startsWith("delete") })
        assertTrue(desk.calls.contains("unlock:segreta"))
        assertTrue(viewModel.uiState.value.deleted)
    }

    @Test fun closingThePasswordPromptLeavesTheReportWithTheError() {
        val source = FakeModeration().apply { reports = listOf(EditorialReport(id = 8, status = "pending")); failNext = passwordRequired() }
        val viewModel = ReportDetailViewModel(repository, source, SavedStateHandle(mapOf("id" to 8L)))
        viewModel.askDelete()
        viewModel.delete()
        repository.guard.dismiss()
        val state = viewModel.uiState.value
        assertFalse(state.deleted)
        assertTrue(state.confirmingDelete)
        assertNotNull(state.deleteError)
    }

    @Test fun aReviewIsModeratedWithItsRevisionAndAConflictReloadsTheList() {
        val review = EditorialReview(id = 4, status = "pending", revision = 3, abilities = abilities("approve", "reject"))
        val source = FakeModeration().apply { reviews = listOf(review) }
        val viewModel = ReviewsViewModel(repository, source, SavedStateHandle())
        viewModel.askReject(review)
        viewModel.moderate("no")
        assertFalse(source.calls.any { it.startsWith("moderate") })
        viewModel.moderate("  Contiene un insulto ")
        assertEquals("moderate:4:rejected:3:Contiene un insulto", source.calls.last())
        assertEquals("rejected", viewModel.list.current.value.items.single().status)
        assertNull(viewModel.uiState.value.dialog)

        source.failNext = conflict()
        viewModel.askApprove(review)
        viewModel.moderate("")
        assertEquals("moderate:4:approved:3:null", source.calls[source.calls.size - 2])
        assertTrue(source.calls.last().startsWith("reviews:"))
        assertEquals(UiText.Raw("Il commento è cambiato."), viewModel.uiState.value.message)
    }

    @Test fun removingTheOwnerReplyReplacesTheRow() {
        val review = EditorialReview(id = 4, reply = ReviewOwnerReply("Grazie"), abilities = abilities("remove_reply"))
        val source = FakeModeration().apply { reviews = listOf(review) }
        val viewModel = ReviewsViewModel(repository, source, SavedStateHandle())
        viewModel.askRemoveReply(review)
        viewModel.removeReply()
        assertNull(viewModel.list.current.value.items.single().reply)
        assertEquals(UiText.Raw("Risposta tolta."), viewModel.uiState.value.message)
    }

    @Test fun commentsAreHiddenRestoredShownAndDeleted() {
        val first = EditorialComment(id = 1, body = "Fuori tema", status = "published", revision = 1)
        val second = EditorialComment(id = 2, body = "Domanda", status = "hidden", revision = 2)
        val source = FakeModeration().apply { comments = listOf(first, second) }
        val viewModel = CommentsViewModel(repository, source, SavedStateHandle())
        viewModel.search("tema")
        assertEquals("comments:page=1&per_page=20&q=tema", source.calls.last())

        viewModel.askHide(first)
        viewModel.hide("a".repeat(1001))
        assertFalse(source.calls.any { it.startsWith("hide") })
        viewModel.hide(" Insulti ")
        assertEquals("hide:1:1:Insulti", source.calls.last())
        assertEquals("hidden", viewModel.list.current.value.items.first { it.id == 1L }.status)

        viewModel.askRestore(second)
        viewModel.restore()
        assertEquals("restore:2:2", source.calls.last())

        viewModel.showDetails(second)
        val details = viewModel.uiState.value.dialog as CommentDialog.Details
        assertEquals("Fuori tema", (details.loaded as Loadable.Ready).value.moderationNote)
        viewModel.dismiss()

        viewModel.askDelete(first)
        viewModel.delete()
        assertEquals(listOf(2L), viewModel.list.current.value.items.map { it.id })
    }

    @Test fun aCommentDecidedByOthersReloadsInsteadOfOverwriting() {
        val first = EditorialComment(id = 1, status = "published", revision = 1)
        val source = FakeModeration().apply { comments = listOf(first); failNext = conflict() }
        val viewModel = CommentsViewModel(repository, source, SavedStateHandle())
        val before = source.calls.count { it.startsWith("comments:") }
        viewModel.askHide(first)
        viewModel.hide("")
        assertEquals("hide:1:1:null", source.calls[source.calls.size - 2])
        assertEquals(before + 1, source.calls.count { it.startsWith("comments:") })
        assertNull(viewModel.uiState.value.dialog)
        assertEquals("published", viewModel.list.current.value.items.single().status)
    }
}
