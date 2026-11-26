package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.Poster
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * R6 — Moderazione dei contenuti: le righe dei tre elenchi del gruppo
 * «Moderazione» del pannello, come le manda `/api/v1/editorial/{reports,
 * reviews,comments}`. Ogni campo ha un valore predefinito: un server più
 * nuovo con un campo in più o in meno non rompe la lettura. Le azioni
 * permesse arrivano in `abilities` (vedi `abilitiesOf`).
 */

/** Gli esiti di una segnalazione (`ReportStatus`), nell'ordine dell'enum del sito. */
internal object ReportStatusValue {
    const val PENDING = "pending"
    const val REVIEWING = "reviewing"
    const val RESOLVED = "resolved"
    const val DISMISSED = "dismissed"
    val all = listOf(PENDING, REVIEWING, RESOLVED, DISMISSED)
}

internal object CommentStatusValue {
    const val PUBLISHED = "published"
    const val HIDDEN = "hidden"
}

internal object ReviewStatusValue {
    const val PENDING = "pending"
    const val APPROVED = "approved"
    const val REJECTED = "rejected"
}

/** I tetti dei campi, gli stessi dei moduli del pannello e delle richieste del server. */
internal object ModerationLimits {
    /* Nota della moderazione di una recensione: facoltativa per approvare, obbligatoria per rifiutare. */
    const val REVIEW_NOTE_MIN = 5
    const val REVIEW_NOTE_MAX = 1000
    /* Nota di «Nascondi» su un commento: facoltativa. */
    const val COMMENT_NOTE_MAX = 1000
    /* Nota di risoluzione di una segnalazione: la colonna `text`. */
    const val RESOLUTION_NOTE_MAX = 65535
}

@Serializable
internal data class ReportSubjectInfo(
    val type: String = "",
    val id: Long = 0,
    /* «evento · Concerto all’alba», già tradotto dal server come nel pannello. */
    val label: String = "",
    /* Profilo, post o commento della community: c'è «Modera il soggetto». */
    val community: Boolean = false,
)

@Serializable
internal data class EditorialReport(
    val id: Long = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val reason: String = "",
    @SerialName("reason_label") val reasonLabel: String = "",
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    val subject: ReportSubjectInfo = ReportSubjectInfo(),
    val note: String? = null,
    @SerialName("resolution_note") val resolutionNote: String? = null,
    @SerialName("reviewed_by") val reviewedBy: String? = null,
    @SerialName("reviewed_at") val reviewedAt: String? = null,
    val abilities: JsonElement? = null,
) {
    /** Da esaminare o in esame: il filtro «aperte» del pannello. */
    val open: Boolean get() = status == ReportStatusValue.PENDING || status == ReportStatusValue.REVIEWING
}

@Serializable
internal data class ReviewSubjectInfo(
    val type: String = "",
    @SerialName("type_label") val typeLabel: String = "",
    val id: Long = 0,
    val name: String = "",
)

@Serializable
internal data class ReviewOwnerReply(
    val body: String = "",
    @SerialName("replied_at") val repliedAt: String? = null,
)

@Serializable
internal data class EditorialReview(
    val id: Long = 0,
    val subject: ReviewSubjectInfo = ReviewSubjectInfo(),
    val author: String? = null,
    val rating: Int? = null,
    val body: String? = null,
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("moderated_by") val moderatedBy: String? = null,
    @SerialName("moderated_at") val moderatedAt: String? = null,
    @SerialName("moderation_note") val moderationNote: String? = null,
    val revision: Int = 0,
    val reply: ReviewOwnerReply? = null,
    val abilities: JsonElement? = null,
) {
    /** Un voto da 1 a 5, o `null` (recensione con il solo testo). */
    val stars: Int? get() = rating?.takeIf { it in 1..5 }
}

@Serializable
internal data class CommentEventInfo(
    val id: Long = 0,
    val title: String = "",
    val slug: String = "",
    val poster: Poster? = null,
)

@Serializable
internal data class EditorialComment(
    val id: Long = 0,
    val event: CommentEventInfo? = null,
    val author: String? = null,
    val body: String = "",
    @SerialName("is_reply") val isReply: Boolean = false,
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("reactions_count") val reactionsCount: Int = 0,
    @SerialName("moderated_by") val moderatedBy: String? = null,
    @SerialName("moderated_at") val moderatedAt: String? = null,
    @SerialName("moderation_note") val moderationNote: String? = null,
    val revision: Int = 0,
    val abilities: JsonElement? = null,
) {
    val hidden: Boolean get() = status == CommentStatusValue.HIDDEN
}

@Serializable
internal data class ModerationMeta(val message: String? = null)

/** La risposta di un'azione: la riga aggiornata (con le `abilities` nuove) e il messaggio del server. */
@Serializable
internal data class ModerationResult<T>(val data: T, val meta: ModerationMeta = ModerationMeta())

@Serializable
internal data class DeletedRow(val id: Long = 0, val deleted: Boolean = false)

@Serializable
internal data class ResolveReportBody(
    val status: String,
    @SerialName("resolution_note") val resolutionNote: String?,
)

@Serializable
internal data class ModerateReviewBody(val status: String, val revision: Int, val note: String?)

@Serializable
internal data class ModerateCommentBody(val revision: Int, val note: String? = null)
