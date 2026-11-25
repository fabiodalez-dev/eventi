package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import java.net.URLEncoder
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * Commenti e recensioni del soggetto (P7): le pagine «Commenti agli eventi»
 * e «Recensioni ricevute» dei pannelli. Ogni campo ha un valore predefinito:
 * un server più vecchio o più nuovo non deve far fallire la lettura.
 */

/** I due stati di un commento, con i valori del server. */
internal object CommentStatus {
    const val PUBLISHED = "published"
    const val HIDDEN = "hidden"
}

@Serializable
internal data class ManagedCommentEvent(val id: Long = 0, val title: String = "", val slug: String = "")

/** Una riga di `GET S/comments`. `canHide` e `canRestore` sono i pulsanti che il pannello mostrerebbe. */
@Serializable
internal data class ManagedComment(
    val id: Long = 0,
    val event: ManagedCommentEvent? = null,
    val author: String = "",
    val body: String = "",
    @SerialName("is_reply") val isReply: Boolean = false,
    @SerialName("reactions_count") val reactionsCount: Int = 0,
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("moderated_by") val moderatedBy: String? = null,
    @SerialName("moderated_at") val moderatedAt: String? = null,
    val revision: Int = 0,
    @SerialName("can_hide") val canHide: Boolean = false,
    @SerialName("can_restore") val canRestore: Boolean = false,
) {
    val hidden: Boolean get() = status == CommentStatus.HIDDEN
}

@Serializable
internal data class ManagedReviewReply(
    val body: String = "",
    val author: String = "",
    @SerialName("replied_at") val repliedAt: String? = null,
)

/** Una riga di `GET S/reviews`: solo recensioni pubblicate. */
@Serializable
internal data class ManagedReview(
    val id: Long = 0,
    val author: String = "",
    val rating: Int? = null,
    val body: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("published_at") val publishedAt: String? = null,
    val reply: ManagedReviewReply? = null,
    @SerialName("can_reply") val canReply: Boolean = false,
    @SerialName("can_remove_reply") val canRemoveReply: Boolean = false,
    @SerialName("can_report") val canReport: Boolean = false,
) {
    /** Il voto da 1 a 5, o nessuno. */
    val stars: Int? get() = rating?.takeIf { it in 1..5 }
    /** La risposta pubblica, se c'è davvero. */
    val givenReply: ManagedReviewReply? get() = reply?.takeIf { it.body.isNotBlank() }
}

@Serializable
internal data class FeedbackMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    val total: Int = 0,
    @SerialName("can_report") val canReport: Boolean = false,
    val message: String? = null,
)

@Serializable
internal data class ManagedCommentPage(val data: List<ManagedComment> = emptyList(), val meta: FeedbackMeta = FeedbackMeta())

@Serializable
internal data class ManagedCommentResult(val data: ManagedComment = ManagedComment(), val meta: FeedbackMeta = FeedbackMeta())

@Serializable
internal data class ManagedReviewPage(val data: List<ManagedReview> = emptyList(), val meta: FeedbackMeta = FeedbackMeta())

@Serializable
internal data class ManagedReviewResult(val data: ManagedReview = ManagedReview(), val meta: FeedbackMeta = FeedbackMeta())

@Serializable
internal data class ReviewReportData(@SerialName("case_id") val caseId: Long = 0, val message: String = "")

@Serializable
internal data class ReviewReportResult(val data: ReviewReportData = ReviewReportData())

/** Le chiamate dell'area; un'interfaccia perché i test dei ViewModel usino un server finto. */
internal interface ManagementFeedbackSource {
    suspend fun comments(subject: ManagementSubject, status: String?, query: String?, eventId: Long?, page: Int): ManagedCommentPage
    suspend fun hideComment(subject: ManagementSubject, commentId: Long, revision: Int, note: String?): ManagedCommentResult
    suspend fun restoreComment(subject: ManagementSubject, commentId: Long, revision: Int): ManagedCommentResult
    suspend fun reviews(subject: ManagementSubject, page: Int): ManagedReviewPage
    suspend fun reply(subject: ManagementSubject, reviewId: Long, text: String): ManagedReviewResult
    suspend fun removeReply(subject: ManagementSubject, reviewId: Long): ManagedReviewResult
    suspend fun report(subject: ManagementSubject, reviewId: Long, body: String): ReviewReportResult
}

internal class ManagementFeedbackApi(private val api: ApiClient, private val token: String) : ManagementFeedbackSource {
    override suspend fun comments(subject: ManagementSubject, status: String?, query: String?, eventId: Long?, page: Int): ManagedCommentPage =
        api.get(commentsPath(subject, status, query, eventId, page), token)

    override suspend fun hideComment(subject: ManagementSubject, commentId: Long, revision: Int, note: String?): ManagedCommentResult {
        val body = buildJsonObject { put("revision", revision); note?.takeIf { it.isNotBlank() }?.let { put("note", it) } }
        return api.execute("${subject.apiPath}/comments/$commentId/hide", "POST", body.toString(), token)
    }

    override suspend fun restoreComment(subject: ManagementSubject, commentId: Long, revision: Int): ManagedCommentResult =
        api.execute("${subject.apiPath}/comments/$commentId/restore", "POST", buildJsonObject { put("revision", revision) }.toString(), token)

    override suspend fun reviews(subject: ManagementSubject, page: Int): ManagedReviewPage =
        api.get("${subject.apiPath}/reviews?page=$page", token)

    override suspend fun reply(subject: ManagementSubject, reviewId: Long, text: String): ManagedReviewResult =
        api.execute("${subject.apiPath}/reviews/$reviewId/reply", "PUT", buildJsonObject { put("reply", JsonPrimitive(text)) }.toString(), token)

    override suspend fun removeReply(subject: ManagementSubject, reviewId: Long): ManagedReviewResult =
        api.delete("${subject.apiPath}/reviews/$reviewId/reply", token)

    override suspend fun report(subject: ManagementSubject, reviewId: Long, body: String): ReviewReportResult =
        api.execute("${subject.apiPath}/reviews/$reviewId/report", "POST", buildJsonObject { put("body", JsonPrimitive(body)) }.toString(), token)
}

/** `S/comments` con i filtri del pannello; quelli vuoti non si mandano. */
internal fun commentsPath(subject: ManagementSubject, status: String?, query: String?, eventId: Long?, page: Int): String {
    val params = buildList {
        status?.takeIf { it.isNotBlank() }?.let { add("status=" + encode(it)) }
        query?.trim()?.takeIf { it.isNotEmpty() }?.let { add("q=" + encode(it)) }
        eventId?.takeIf { it > 0 }?.let { add("event=$it") }
        add("page=$page")
    }
    return "${subject.apiPath}/comments?" + params.joinToString("&")
}

private fun encode(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name()).replace("+", "%20")

/* I limiti dei campi, gli stessi del server: si controllano anche qui per non mandare richieste già sbagliate. */
internal const val COMMENT_NOTE_MAX = 1000
internal const val REVIEW_REPLY_MIN = 2
internal const val REVIEW_REPLY_MAX = 2000
internal const val REVIEW_REPORT_MIN = 10
internal const val REVIEW_REPORT_MAX = 3000
