package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Poster
import java.io.File
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/*
 * Il riepilogo del soggetto (P11, W-DB-01…08): i riquadri della prima pagina
 * dei pannelli — avviso della mappa, «Il tuo cartellone», «Oggi», «Prossimi
 * eventi», «Ultime visite» — e il programma della settimana in PDF del locale.
 *
 * Il rapporto che nel pannello sta sotto i riquadri è quello delle statistiche
 * (P5); le scorciatoie «Vai a» le compone l'app da `permissions`. Ogni campo
 * ha un valore predefinito, perché un server più vecchio o più nuovo non
 * faccia fallire la lettura.
 */

@Serializable
internal data class DashboardEnvelope(val data: DashboardSummary = DashboardSummary())

@Serializable
internal data class DashboardSubjectRef(val type: String = "", val id: Long = 0, val name: String = "")

/** «Il tuo cartellone»: date di oggi, date da oggi in avanti, eventi in bozza. */
@Serializable
internal data class DashboardCounts(val today: Int = 0, val upcoming: Int = 0, val drafts: Int = 0)

@Serializable
internal data class DashboardStatus(val value: String = "", val label: String = "")

@Serializable
internal data class DashboardEvent(
    val id: Long = 0,
    val slug: String = "",
    val title: String = "",
    val status: DashboardStatus = DashboardStatus(),
    val poster: Poster? = null,
)

/** Una serata: inizio in UTC, luogo già scritto dal server, stato se non è «in programma». */
@Serializable
internal data class DashboardDate(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String = "",
    val place: String = "",
    val status: DashboardStatus = DashboardStatus(),
    val scheduled: Boolean = true,
)

/** I collegamenti che il pannello mette sulla card, detti come permessi. */
@Serializable
internal data class DashboardLinks(
    val edit: Boolean = false,
    @SerialName("tickets_occurrence_id") val ticketsOccurrenceId: Long? = null,
    val statistics: Boolean = false,
    val social: Boolean = false,
    val sponsor: Boolean = false,
)

@Serializable
internal data class DashboardCard(
    val event: DashboardEvent = DashboardEvent(),
    val date: DashboardDate? = null,
    /* Solo in «Ultime visite»: le visite degli ultimi sette giorni. */
    val views: Long? = null,
    val links: DashboardLinks = DashboardLinks(),
)

@Serializable
internal data class DashboardRecentViews(val days: Int = 7, val items: List<DashboardCard> = emptyList())

/** I due pulsanti del programma: i lunedì di questa settimana e della prossima (`AAAA-MM-GG`). */
@Serializable
internal data class DashboardProgramme(
    val available: Boolean = false,
    @SerialName("this_week") val thisWeek: String = "",
    @SerialName("next_week") val nextWeek: String = "",
)

/** `GET S/dashboard`. */
@Serializable
internal data class DashboardSummary(
    val subject: DashboardSubjectRef = DashboardSubjectRef(),
    val timezone: String = "Europe/Rome",
    @SerialName("missing_location") val missingLocation: Boolean = false,
    val counts: DashboardCounts = DashboardCounts(),
    val today: List<DashboardCard> = emptyList(),
    val upcoming: List<DashboardCard> = emptyList(),
    @SerialName("recent_views") val recentViews: DashboardRecentViews = DashboardRecentViews(),
    /* Solo per il locale. */
    val programme: DashboardProgramme? = null,
)

internal interface ManagementDashboardSource {
    suspend fun summary(subject: ManagementSubject): DashboardSummary
    suspend fun programme(subject: ManagementSubject, week: String, directory: File): DownloadedFile
}

/** Il percorso del programma: il lunedì scelto e nient'altro. */
internal fun dashboardProgrammePath(subject: ManagementSubject, week: String): String =
    "${subject.apiPath}/dashboard/programme" + if (week.isNotBlank()) "?week=$week" else ""

/** Il nome del file nella cartella della gestione: «programma-circolo-aurora-2026-11-23.pdf». */
internal fun dashboardProgrammeName(subject: ManagementSubject, week: String): String =
    "programma-${subject.slug.ifBlank { subject.id.toString() }}-${week.ifBlank { "settimana" }}.pdf"

internal class ManagementDashboardApi(private val api: ApiClient, private val token: String) : ManagementDashboardSource {
    override suspend fun summary(subject: ManagementSubject): DashboardSummary =
        api.get<DashboardEnvelope>("${subject.apiPath}/dashboard", token).data

    override suspend fun programme(subject: ManagementSubject, week: String, directory: File): DownloadedFile =
        api.download(dashboardProgrammePath(subject, week), token, File(directory, dashboardProgrammeName(subject, week)))
}
