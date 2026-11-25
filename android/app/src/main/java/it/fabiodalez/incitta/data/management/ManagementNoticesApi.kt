package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import java.net.URLEncoder
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * P12 — Notifiche a chi gestisce e chiusura del giro (W-NT-01, 03, 04, 05,
 * W-CM-05, W-ST-10). Ogni campo ha un valore predefinito: un server più
 * vecchio o più nuovo non deve far fallire la lettura.
 */

/**
 * Dove porta, dentro la gestione, un avviso a chi gestisce: è `management`
 * delle righe di `GET /me/notifications` e i campi `management_*` della push.
 * Il server lo scrive solo se la persona gestisce ancora quel soggetto; senza,
 * l'app apre l'`url` dell'avviso come prima.
 */
@Serializable
data class ManagementNoticeTarget(
    @SerialName("subject_type") val subjectType: String = "",
    @SerialName("subject_id") val subjectId: Long = 0,
    /* `event` (scheda dell'evento), `comments` (commenti dell'evento), `home` (pagina del soggetto). */
    val area: String = "",
    val id: Long? = null,
) {
    /**
     * L'indirizzo interno con cui l'avviso arriva a `MainActivity` e poi a
     * `ManagementDestination.parse`: `incitta://gestione/venue/12/event/34`.
     * `null` se i dati non bastano: allora vale l'`url` dell'avviso.
     */
    fun uri(): String? {
        val kind = SubjectKind.entries.firstOrNull { it.name.equals(subjectType, ignoreCase = true) } ?: return null
        if (subjectId <= 0 || area !in NOTICE_AREAS) return null
        val target = id?.takeIf { it > 0 }
        if (area != NOTICE_HOME && target == null) return null
        return "$NOTICE_SCHEME://$NOTICE_HOST/${kind.name.lowercase()}/$subjectId/$area" + (target?.let { "/$it" } ?: "")
    }

    companion object {
        /** Dai dati piatti di una push FCM (`management_subject_type`, …); `null` se mancano. */
        fun fromPush(data: Map<String, String>): ManagementNoticeTarget? {
            val type = data["management_subject_type"]?.takeIf { it.isNotBlank() } ?: return null
            return ManagementNoticeTarget(
                subjectType = type,
                subjectId = data["management_subject_id"]?.toLongOrNull() ?: return null,
                area = data["management_area"].orEmpty(),
                id = data["management_id"]?.toLongOrNull(),
            )
        }
    }
}

internal const val NOTICE_SCHEME = "incitta"
internal const val NOTICE_HOST = "gestione"
internal const val NOTICE_EVENT = "event"
internal const val NOTICE_COMMENTS = "comments"
internal const val NOTICE_HOME = "home"
private val NOTICE_AREAS = setOf(NOTICE_EVENT, NOTICE_COMMENTS, NOTICE_HOME)

/** Dove si apre un avviso: dentro la gestione se il server l'ha detto, altrimenti il suo `url`. */
internal fun noticeTarget(management: ManagementNoticeTarget?, url: String): String = management?.uri() ?: url

@Serializable
internal data class ManagedNoticeData(
    val title: String = "",
    val body: String = "",
    val url: String = "",
    val management: ManagementNoticeTarget? = null,
)

/** Una riga di `GET S/notifications`: la stessa forma della casella dell'account. */
@Serializable
internal data class ManagedNotice(
    val id: String = "",
    val type: String = "",
    val data: ManagedNoticeData = ManagedNoticeData(),
    val read: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
) {
    val target: String get() = noticeTarget(data.management, data.url)
}

@Serializable
internal data class ManagedNoticeMeta(
    @SerialName("next_cursor") val nextCursor: String? = null,
    @SerialName("has_more") val hasMore: Boolean = false,
    @SerialName("unread_count") val unreadCount: Int = 0,
)

@Serializable
internal data class ManagedNoticePage(val data: List<ManagedNotice> = emptyList(), val meta: ManagedNoticeMeta = ManagedNoticeMeta())

/** Le preferenze che riguardano chi gestisce: il rapporto mensile (W-ST-10). */
@Serializable
internal data class ManagerPreferences(
    @SerialName("venue_report") val venueReport: Boolean = true,
    @SerialName("venue_report_available") val venueReportAvailable: Boolean = false,
)

@Serializable
internal data class ManagedEventRef(val id: Long = 0, val slug: String = "", val title: String = "")

/** Le chiamate dell'area; un'interfaccia perché i test dei ViewModel usino un server finto. */
internal interface ManagementNoticesSource {
    suspend fun notices(subject: ManagementSubject, cursor: String?): ManagedNoticePage
    suspend fun markRead(id: String)
    suspend fun preferences(): ManagerPreferences
    suspend fun setVenueReport(enabled: Boolean): ManagerPreferences
    /** L'id di un evento del soggetto dal suo slug (gli indirizzi del pannello portano lo slug). */
    suspend fun eventBySlug(subject: ManagementSubject, slug: String): ManagedEventRef
}

internal class ManagementNoticesApi(private val api: ApiClient, private val token: String) : ManagementNoticesSource {
    override suspend fun notices(subject: ManagementSubject, cursor: String?): ManagedNoticePage =
        api.get(noticesPath(subject, cursor), token)

    override suspend fun markRead(id: String) {
        api.execute<ApiEnvelope<ManagedNotice>>("me/notifications/${URLEncoder.encode(id, Charsets.UTF_8.name())}/read", "PATCH", "{}", token)
    }

    override suspend fun preferences(): ManagerPreferences =
        api.get<ApiEnvelope<ManagerPreferences>>("me/notification-preferences", token).data

    override suspend fun setVenueReport(enabled: Boolean): ManagerPreferences =
        api.execute<ApiEnvelope<ManagerPreferences>>("me/notification-preferences", "PATCH", buildJsonObject { put("venue_report", enabled) }.toString(), token).data

    override suspend fun eventBySlug(subject: ManagementSubject, slug: String): ManagedEventRef =
        api.get<ApiEnvelope<ManagedEventRef>>("${subject.apiPath}/events/by-slug/${URLEncoder.encode(slug, Charsets.UTF_8.name())}", token).data
}

internal fun noticesPath(subject: ManagementSubject, cursor: String?): String =
    "${subject.apiPath}/notifications" + (cursor?.takeIf { it.isNotBlank() }?.let { "?cursor=" + URLEncoder.encode(it, Charsets.UTF_8.name()) } ?: "")
