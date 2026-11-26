package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage

/**
 * Le chiamate della moderazione (R6). Le schermate le fanno dentro
 * `repository.call { }`, che si occupa dei 423 (sblocco e password recente
 * per le eliminazioni). La città non conta: il pannello vede tutte le città
 * in queste tre pagine, e il server anche.
 */
internal interface ModerationSource {
    suspend fun reports(params: List<Pair<String, String>>): EditorialPage<EditorialReport>
    suspend fun report(id: Long): EditorialReport
    suspend fun resolveReport(id: Long, status: String, resolutionNote: String?): ModerationResult<EditorialReport>
    /** Pericolosa: 423 `PASSWORD_REQUIRED` senza la password degli ultimi 15 minuti. */
    suspend fun deleteReport(id: Long): ModerationResult<DeletedRow>

    suspend fun reviews(params: List<Pair<String, String>>): EditorialPage<EditorialReview>
    suspend fun moderateReview(id: Long, status: String, revision: Int, note: String?): ModerationResult<EditorialReview>
    suspend fun removeReply(id: Long): ModerationResult<EditorialReview>

    suspend fun comments(params: List<Pair<String, String>>): EditorialPage<EditorialComment>
    suspend fun comment(id: Long): EditorialComment
    suspend fun hideComment(id: Long, revision: Int, note: String?): ModerationResult<EditorialComment>
    suspend fun restoreComment(id: Long, revision: Int): ModerationResult<EditorialComment>
    /** Pericolosa, come `deleteReport`. */
    suspend fun deleteComment(id: Long): ModerationResult<DeletedRow>
}

internal class ModerationApi(private val api: ApiClient, private val token: String) : ModerationSource {
    override suspend fun reports(params: List<Pair<String, String>>): EditorialPage<EditorialReport> =
        api.get(editorialPath("reports", null, params), token)

    override suspend fun report(id: Long): EditorialReport =
        api.get<ApiEnvelope<EditorialReport>>(editorialPath("reports/$id", null), token).data

    override suspend fun resolveReport(id: Long, status: String, resolutionNote: String?): ModerationResult<EditorialReport> =
        api.execute(editorialPath("reports/$id", null), "PATCH", api.json.encodeToString(ResolveReportBody.serializer(), ResolveReportBody(status, resolutionNote)), token)

    override suspend fun deleteReport(id: Long): ModerationResult<DeletedRow> =
        api.delete(editorialPath("reports/$id", null), token)

    override suspend fun reviews(params: List<Pair<String, String>>): EditorialPage<EditorialReview> =
        api.get(editorialPath("reviews", null, params), token)

    override suspend fun moderateReview(id: Long, status: String, revision: Int, note: String?): ModerationResult<EditorialReview> =
        api.post(editorialPath("reviews/$id/moderate", null), ModerateReviewBody(status, revision, note), token)

    override suspend fun removeReply(id: Long): ModerationResult<EditorialReview> =
        api.delete(editorialPath("reviews/$id/reply", null), token)

    override suspend fun comments(params: List<Pair<String, String>>): EditorialPage<EditorialComment> =
        api.get(editorialPath("comments", null, params), token)

    override suspend fun comment(id: Long): EditorialComment =
        api.get<ApiEnvelope<EditorialComment>>(editorialPath("comments/$id", null), token).data

    override suspend fun hideComment(id: Long, revision: Int, note: String?): ModerationResult<EditorialComment> =
        api.post(editorialPath("comments/$id/hide", null), ModerateCommentBody(revision, note), token)

    override suspend fun restoreComment(id: Long, revision: Int): ModerationResult<EditorialComment> =
        api.post(editorialPath("comments/$id/restore", null), ModerateCommentBody(revision), token)

    override suspend fun deleteComment(id: Long): ModerationResult<DeletedRow> =
        api.delete(editorialPath("comments/$id", null), token)
}

/** L'API della moderazione per la sessione del repository (il client HTTP lo dà `EditorialRepository.create`). */
internal fun EditorialRepository.moderationApi(): ModerationSource =
    ModerationApi(client ?: throw IllegalStateException("Moderazione non disponibile in questa sessione"), token)
