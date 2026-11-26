package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialComment
import it.fabiodalez.incitta.data.editorial.EditorialReport
import it.fabiodalez.incitta.data.editorial.EditorialReview
import it.fabiodalez.incitta.data.editorial.ModerationLimits
import it.fabiodalez.incitta.data.editorial.ReportSubjectInfo
import it.fabiodalez.incitta.data.editorial.ReviewOwnerReply
import it.fabiodalez.incitta.data.editorial.ReviewSubjectInfo
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.moderation.CommentHideDialog
import it.fabiodalez.incitta.ui.editorial.moderation.CommentsList
import it.fabiodalez.incitta.ui.editorial.moderation.CommentsUiState
import it.fabiodalez.incitta.ui.editorial.moderation.ReportDetail
import it.fabiodalez.incitta.ui.editorial.moderation.ReportDetailUiState
import it.fabiodalez.incitta.ui.editorial.moderation.ReportsList
import it.fabiodalez.incitta.ui.editorial.moderation.ReviewModerateDialog
import it.fabiodalez.incitta.ui.editorial.moderation.ReviewsList
import it.fabiodalez.incitta.ui.editorial.moderation.ReviewsUiState
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Le schermate principali della moderazione (R6) su un'attività vuota:
 * elenco e dettaglio di una segnalazione, recensioni con i pulsanti che le
 * abilità permettono, la nota obbligatoria per rifiutare, i commenti con
 * «Elimina» solo per chi può. I testi si leggono dalle risorse.
 */
class ModerationUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)
    private val zone = ZoneId.of("Europe/Rome")

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    @Test fun theReportsListHasNoSearchAndOpensARow() {
        val opened = CopyOnWriteArrayList<Long>()
        val report = EditorialReport(id = 7, reason = "wrong_info", reasonLabel = "Informazioni sbagliate", status = "pending", statusLabel = "Da esaminare",
            subject = ReportSubjectInfo("event", 3, "evento · Concerto all’alba"), note = "L’orario è sbagliato.", createdAt = "2026-11-20T10:00:00+01:00")
        show {
            ReportsList(PagedState(query = ListQuery(), items = listOf(report), page = 1, hasMore = false, total = 1), null, PaddingValues(), zone,
                onBack = {}, onOpen = { opened += it }, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {})
        }
        compose.onNodeWithText(text(R.string.moderation_reports_title)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_list_search)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.moderation_sort_oldest)).assertExists()
        compose.onNodeWithText("Informazioni sbagliate").assertExists()
        compose.onNodeWithText("evento · Concerto all’alba").performClick()
        compose.runOnIdle { assertEquals(listOf(7L), opened) }
    }

    @Test fun theReportOutcomeIsSavedAndDeleteIsOfferedOnlyWithTheAbility() {
        val saves = CopyOnWriteArrayList<String>()
        val community = CopyOnWriteArrayList<Boolean>()
        val report = EditorialReport(id = 7, reason = "offensive", reasonLabel = "Offensivo", status = "pending",
            subject = ReportSubjectInfo("community_post", 4, "Post · Ciao a tutti", community = true), abilities = abilities("resolve"))
        show {
            ReportDetail(ReportDetailUiState(report = Loadable.Ready(report), status = "pending"), null, PaddingValues(), zone,
                onBack = {}, onCommunity = { community += true }, onRetry = {}, onStatus = { saves += "status:$it" }, onNote = {}, onSave = { saves += "save" }, onDelete = { saves += "delete" })
        }
        compose.onNodeWithText(text(R.string.moderation_report_delete)).assertDoesNotExist()
        compose.onNodeWithText("resolved").performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.moderation_report_save)).performScrollTo().assertIsEnabled().performClick()
        compose.onNodeWithText(text(R.string.moderation_report_community)).performScrollTo().performClick()
        compose.runOnIdle {
            assertEquals(listOf("status:resolved", "save"), saves)
            assertEquals(listOf(true), community)
        }
    }

    @Test fun theDeleteButtonAppearsForWhoCanDelete() {
        val report = EditorialReport(id = 7, status = "resolved", subject = ReportSubjectInfo("event", 1, "evento · Mostra"), abilities = abilities("resolve", "destroy"))
        show {
            ReportDetail(ReportDetailUiState(report = Loadable.Ready(report), status = "resolved"), null, PaddingValues(), zone,
                onBack = {}, onCommunity = {}, onRetry = {}, onStatus = {}, onNote = {}, onSave = {}, onDelete = {})
        }
        compose.onNodeWithText(text(R.string.moderation_report_delete)).performScrollTo().assertIsEnabled()
        compose.onNodeWithText(text(R.string.moderation_report_community)).assertDoesNotExist()
    }

    @Test fun reviewsShowOnlyTheAllowedButtons() {
        val actions = CopyOnWriteArrayList<String>()
        val withReply = EditorialReview(id = 1, subject = ReviewSubjectInfo("venue", "Locale", 2, "Circolo Arci"), author = "Mario Verdi", rating = 4, body = "Cena ottima.",
            status = "approved", statusLabel = "Approvata", reply = ReviewOwnerReply("Grazie!"), abilities = abilities("approve", "reject", "remove_reply"))
        show {
            ReviewsList(PagedState(items = listOf(withReply), page = 1, hasMore = false, total = 1), ReviewsUiState(), null, PaddingValues(), zone,
                onBack = {}, onSearch = {}, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {},
                onApprove = { actions += "approve" }, onReject = { actions += "reject" }, onRemoveReply = { actions += "remove" })
        }
        compose.onNodeWithText(text(R.string.moderation_reviews_search)).assertExists()
        compose.onNodeWithText("Grazie!").assertExists()
        compose.onNodeWithText(text(R.string.moderation_review_reject)).performClick()
        compose.onNodeWithText(text(R.string.moderation_review_remove_reply)).performClick()
        compose.runOnIdle { assertEquals(listOf("reject", "remove"), actions) }
    }

    @Test fun rejectingAReviewNeedsTheReason() {
        val sent = CopyOnWriteArrayList<String>()
        val review = EditorialReview(id = 1, body = "Testo della recensione", revision = 2)
        show { ReviewModerateDialog(review, "rejected", busy = false, errors = FormErrors.None, onConfirm = { sent += it }, onDismiss = {}) }
        val reject = compose.onAllNodesWithText(text(R.string.moderation_review_reject)).filterToOne(hasClickAction())
        reject.assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.moderation_review_note)).performTextInput("Contiene un insulto")
        reject.assertIsEnabled().performClick()
        compose.runOnIdle { assertEquals(listOf("Contiene un insulto"), sent) }
    }

    @Test fun commentsOfferDeleteOnlyToWhoCan() {
        val actions = CopyOnWriteArrayList<String>()
        val published = EditorialComment(id = 1, author = "Giulia Ferrari", body = "Ci sarà posto?", status = "published", statusLabel = "Pubblicato", abilities = abilities("show", "hide"))
        val hidden = EditorialComment(id = 2, author = "Luca Bianchi", body = "Fuori tema", status = "hidden", statusLabel = "Nascosto", abilities = abilities("show", "restore", "destroy"))
        show {
            CommentsList(PagedState(items = listOf(published, hidden), page = 1, hasMore = false, total = 2), CommentsUiState(), PaddingValues(), zone,
                onBack = {}, onSearch = {}, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {},
                onDetails = { actions += "details:${it.id}" }, onHide = { actions += "hide:${it.id}" }, onRestore = { actions += "restore:${it.id}" }, onDelete = { actions += "delete:${it.id}" })
        }
        compose.onAllNodesWithText(text(R.string.moderation_comment_delete)).assertCountEquals(1)
        compose.onNodeWithText(text(R.string.moderation_comment_hide)).performClick()
        compose.onNodeWithText(text(R.string.moderation_comment_restore)).performClick()
        compose.onNodeWithText(text(R.string.moderation_comment_delete)).performClick()
        compose.onAllNodesWithText(text(R.string.moderation_comment_details))[0].performClick()
        compose.runOnIdle { assertEquals(listOf("hide:1", "restore:2", "delete:2", "details:1"), actions) }
    }

    @Test fun hidingACommentTakesAnOptionalNoteWithinTheLimit() {
        val sent = CopyOnWriteArrayList<String>()
        val comment = EditorialComment(id = 1, body = "Ci sarà posto?", revision = 1)
        show { CommentHideDialog(comment, busy = false, errors = FormErrors.None, onHide = { sent += it }, onDismiss = {}) }
        val hide = compose.onAllNodesWithText(text(R.string.moderation_comment_hide)).filterToOne(hasClickAction())
        hide.assertIsEnabled()
        compose.onNodeWithText(text(R.string.moderation_comment_hide_note)).performTextInput("a".repeat(ModerationLimits.COMMENT_NOTE_MAX + 1))
        hide.assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.moderation_comment_hide_note)).performTextClearance()
        compose.onNodeWithText(text(R.string.moderation_comment_hide_note)).performTextInput("Insulti")
        hide.performClick()
        compose.runOnIdle { assertEquals(listOf("Insulti"), sent) }
    }
}
