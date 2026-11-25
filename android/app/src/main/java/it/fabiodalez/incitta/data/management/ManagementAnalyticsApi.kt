package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import java.io.File
import java.net.URLEncoder
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.put

/*
 * Statistiche del soggetto (P5): le pagine «Statistiche», «Statistiche in
 * dettaglio» e la pagina di un evento, locale o organizzatore dei pannelli,
 * più le date di un evento, i link brevi e i canali propri del locale.
 *
 * Etichette e frasi sotto i numeri arrivano dal server, le stesse del
 * pannello: l'app non riscrive il nome di una misura. Ogni campo ha un
 * valore predefinito, perché un server più vecchio o più nuovo non faccia
 * fallire la lettura.
 */

@Serializable
internal data class AnalyticsEnvelope<T>(val data: T)

/** Un riquadro con il numero e la frase che il pannello gli scrive sotto. */
@Serializable
internal data class AnalyticsCard(val key: String = "", val label: String = "", val value: Long = 0, val hint: String = "")

@Serializable
internal data class AnalyticsOption(val value: String = "", val label: String = "")

@Serializable
internal data class AnalyticsValue(val key: String = "", val label: String = "", val value: Long = 0)

@Serializable
internal data class AnalyticsPageMeta(val page: Int = 1, @SerialName("next_page") val nextPage: Int? = null, val total: Int = 0)

// ---------------------------------------------------------------- «Statistiche»

@Serializable
internal data class SummaryPeriod(val value: String = "month", val days: Int = 30, val from: String = "", val until: String = "")

@Serializable
internal data class SummaryDay(
    val date: String = "",
    val views: Long = 0,
    @SerialName("profile_views") val profileViews: Long = 0,
    val clicks: Long = 0,
)

@Serializable
internal data class PaidChannel(val label: String = "", val clicks: Long = 0, val share: String = "")

@Serializable
internal data class PaidBreakdown(val channel: String = "", val placement: String = "", val page: String = "", val clicks: Long = 0)

@Serializable
internal data class PaidSummary(
    val impressions: Long = 0,
    val clicks: Long = 0,
    val ctr: String? = null,
    val channels: List<PaidChannel> = emptyList(),
    val breakdown: List<PaidBreakdown> = emptyList(),
)

@Serializable
internal data class AnalyticsColumn(val key: String = "", val label: String = "", val numeric: Boolean = false)

@Serializable
internal data class SummaryEventRow(val id: Long = 0, val title: String = "", val values: Map<String, Long> = emptyMap())

@Serializable
internal data class SummaryEvents(
    val columns: List<AnalyticsColumn> = emptyList(),
    val data: List<SummaryEventRow> = emptyList(),
    val meta: AnalyticsPageMeta = AnalyticsPageMeta(),
)

@Serializable
internal data class ShareRow(
    val title: String = "",
    val occurrence: String? = null,
    val channel: String = "",
    val url: String = "",
    val shares: Long = 0,
    val clicks: Long = 0,
)

@Serializable
internal data class SummaryShares(val data: List<ShareRow> = emptyList(), val meta: AnalyticsPageMeta = AnalyticsPageMeta())

/** `GET S/analytics/summary`: la pagina «Statistiche». */
@Serializable
internal data class AnalyticsSummary(
    val period: SummaryPeriod = SummaryPeriod(),
    val periods: List<AnalyticsOption> = emptyList(),
    val cards: List<AnalyticsCard> = emptyList(),
    val empty: Boolean = false,
    val series: List<SummaryDay> = emptyList(),
    val interactions: List<AnalyticsValue> = emptyList(),
    val profile: List<AnalyticsValue> = emptyList(),
    val details: List<AnalyticsValue> = emptyList(),
    val paid: PaidSummary = PaidSummary(),
    val events: SummaryEvents = SummaryEvents(),
    val shares: SummaryShares = SummaryShares(),
)

// --------------------------------------------------- «Statistiche in dettaglio»

@Serializable
internal data class ReportFilters(val event: Long? = null, val channel: String? = null)

@Serializable
internal data class ActiveFilter(val key: String = "", val label: String = "", val value: String = "")

@Serializable
internal data class AnalyticsFact(val label: String = "", val value: String = "")

@Serializable
internal data class SelectedEvent(val id: Long = 0, val title: String = "", val subtitle: String = "", val facts: List<AnalyticsFact> = emptyList())

@Serializable
internal data class ReportPoint(
    val date: String = "",
    val views: Long = 0,
    @SerialName("short_shares") val shortShares: Long = 0,
    @SerialName("short_clicks") val shortClicks: Long = 0,
)

@Serializable
internal data class ReportSeries(val granularity: String = "day", val points: List<ReportPoint> = emptyList())

/** Una barra di un grafico: nome, numero principale e, se c'è, il secondo numero scritto accanto. */
@Serializable
internal data class ChartRow(val id: Long? = null, val name: String = "", val value: Long = 0, val secondary: Long? = null)

/**
 * Un grafico a barre di «Statistiche in dettaglio». `key` dice quale (titolo
 * e frase li ha l'app); `link` il tipo del soggetto che si apre toccando una
 * barra (`event`, `venue`, `organizer`) o nessuno.
 */
@Serializable
internal data class ReportChart(
    val key: String = "",
    val link: String? = null,
    @SerialName("metric_label") val metricLabel: String? = null,
    @SerialName("secondary_label") val secondaryLabel: String? = null,
    val rows: List<ChartRow> = emptyList(),
    val totals: List<AnalyticsValue> = emptyList(),
)

@Serializable
internal data class ReportDataset(val key: String = "", val label: String = "", val count: Int = 0)

/** Un collegamento alla pagina di un soggetto: `event`, `venue` o `organizer`. */
@Serializable
internal data class SubjectLink(val type: String = "", val id: Long = 0, val label: String = "")

@Serializable
internal data class SubjectInfo(val label: String = "", val value: String = "", val link: SubjectLink? = null)

/** Una misura del report del profilo: `null` vuol dire «n.d.», non zero. */
@Serializable
internal data class ProfileMetric(val key: String = "", val label: String = "", val value: Double? = null)

@Serializable
internal data class AnalyticsSubjectHeader(
    val type: String = "",
    val id: Long = 0,
    val name: String = "",
    val title: String = "",
    val event: EventChoice? = null,
    val related: List<SubjectLink> = emptyList(),
    val information: List<SubjectInfo> = emptyList(),
    val description: String? = null,
    @SerialName("profile_report") val profileReport: List<ProfileMetric> = emptyList(),
)

/** `GET S/analytics/report` e `GET S/analytics/subjects/{type}/{id}`. */
@Serializable
internal data class AnalyticsReport(
    val period: String = "month",
    val from: String? = null,
    val until: String = "",
    val periods: List<AnalyticsOption> = emptyList(),
    val filters: ReportFilters = ReportFilters(),
    @SerialName("active_filters") val activeFilters: List<ActiveFilter> = emptyList(),
    val channels: List<AnalyticsOption> = emptyList(),
    val context: String = "",
    val cards: List<AnalyticsCard> = emptyList(),
    val empty: Boolean = false,
    @SerialName("selected_event") val selectedEvent: SelectedEvent? = null,
    val series: ReportSeries = ReportSeries(),
    @SerialName("series_labels") val seriesLabels: Map<String, String> = emptyMap(),
    val charts: List<ReportChart> = emptyList(),
    val datasets: List<ReportDataset> = emptyList(),
    val subject: AnalyticsSubjectHeader? = null,
)

@Serializable
internal data class AnalyticsTableRow(val cells: List<JsonElement> = emptyList(), val links: Map<String, SubjectLink> = emptyMap())

/** Una pagina di una tabella di «Tutti i dati». */
@Serializable
internal data class AnalyticsTable(
    val dataset: String = "",
    val label: String = "",
    val columns: List<AnalyticsColumn> = emptyList(),
    val rows: List<AnalyticsTableRow> = emptyList(),
    val unavailable: String = "n.d.",
    val meta: AnalyticsPageMeta = AnalyticsPageMeta(),
)

// ------------------------------------------------------ le date di un evento

@Serializable
internal data class AnalyticsStatus(val value: String = "", val label: String = "")

@Serializable
internal data class DateAnalyticsRow(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String = "",
    val timezone: String? = null,
    val status: AnalyticsStatus = AnalyticsStatus(),
    val views: Long = 0,
    val clicks: Long = 0,
    val saves: Long = 0,
    @SerialName("can_view_report") val canViewReport: Boolean = false,
)

@Serializable
internal data class AnalyticsEventRef(val id: Long = 0, val title: String = "")

@Serializable
internal data class OccurrenceDay(val date: String = "", val views: Long = 0, val clicks: Long = 0)

/** Il pannello laterale «Statistiche» di una data. */
@Serializable
internal data class OccurrenceAnalyticsReport(
    val id: Long = 0,
    val event: AnalyticsEventRef = AnalyticsEventRef(),
    @SerialName("starts_at") val startsAt: String = "",
    val timezone: String? = null,
    val note: String = "",
    val cards: List<AnalyticsCard> = emptyList(),
    val series: List<OccurrenceDay> = emptyList(),
)

@Serializable
internal data class ShareLinkRow(val channel: String = "", val label: String = "", val url: String = "")

/** I link brevi tracciati di un evento (W-PB-05). */
@Serializable
internal data class EventShareLinks(val available: Boolean = false, val links: List<ShareLinkRow> = emptyList())

// ------------------------------------------------------------ canali propri

@Serializable
internal data class ChannelRow(
    val code: String = "",
    val channel: String = "",
    val target: String = "",
    val url: String = "",
    val shares: Long = 0,
    val clicks: Long = 0,
)

@Serializable
internal data class ChannelsMeta(val max: Int = 12, val custom: Int = 0)

@Serializable
internal data class ChannelsList(
    val data: List<ChannelRow> = emptyList(),
    val meta: ChannelsMeta = ChannelsMeta(),
    val created: String? = null,
    val message: String? = null,
)

// --------------------------------------------------------------- richieste

/** Su che cosa si chiede il rapporto: `null` = tutti gli eventi; altrimenti un evento, un locale o un organizzatore. */
internal data class AnalyticsTarget(val type: String, val id: Long)

/** I periodi di «Statistiche in dettaglio», con le date scelte a mano (`custom`). */
internal object AnalyticsPeriods {
    const val CUSTOM = "custom"
    const val DEFAULT = "month"
}

/**
 * I filtri di «Statistiche in dettaglio»: periodo (o due date), evento e
 * canale. Nella pagina di un soggetto l'evento non si manda: il soggetto è
 * quello della rotta, come nel pannello.
 */
internal data class ReportQuery(
    val period: String = AnalyticsPeriods.DEFAULT,
    val from: String? = null,
    val until: String? = null,
    val event: Long? = null,
    val channel: String? = null,
) {
    val custom: Boolean get() = period == AnalyticsPeriods.CUSTOM

    fun params(includeEvent: Boolean = true): List<String> = buildList {
        add("period=" + encodeAnalytics(period))
        if (custom) {
            from?.takeIf { it.isNotBlank() }?.let { add("from=" + encodeAnalytics(it)) }
            until?.takeIf { it.isNotBlank() }?.let { add("until=" + encodeAnalytics(it)) }
        }
        if (includeEvent) event?.takeIf { it > 0 }?.let { add("event=$it") }
        channel?.takeIf { it.isNotBlank() }?.let { add("channel=" + encodeAnalytics(it)) }
    }
}

/** Il percorso di base del rapporto: `S/analytics/report` oppure `S/analytics/subjects/{type}/{id}`. */
internal fun reportBase(subject: ManagementSubject, target: AnalyticsTarget?): String =
    if (target == null) "${subject.apiPath}/analytics/report" else "${subject.apiPath}/analytics/subjects/${target.type}/${target.id}"

internal fun reportPath(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery): String =
    reportBase(subject, target) + "?" + query.params(includeEvent = target == null).joinToString("&")

internal fun reportTablePath(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, page: Int): String =
    reportBase(subject, target) + "/table?" + (query.params(includeEvent = target == null) + listOf("dataset=" + encodeAnalytics(dataset), "page=$page")).joinToString("&")

internal fun reportExportPath(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, format: String): String =
    reportBase(subject, target) + "/export?" + (query.params(includeEvent = target == null) + listOf("dataset=" + encodeAnalytics(dataset), "format=" + encodeAnalytics(format))).joinToString("&")

internal fun summaryPath(subject: ManagementSubject, period: String, page: Int, sharePage: Int): String =
    "${subject.apiPath}/analytics/summary?period=${encodeAnalytics(period)}&page=$page&share_page=$sharePage"

internal fun eventChoicesPath(subject: ManagementSubject, search: String): String =
    "${subject.apiPath}/analytics/events" + search.trim().takeIf { it.isNotEmpty() }?.let { "?q=" + encodeAnalytics(it) }.orEmpty()

/** Il nome del file scaricato: insieme di dati, giorno e formato, mai dati personali. */
internal fun analyticsExportName(dataset: String, format: String, stamp: String): String =
    "statistiche-${dataset.filter { it.isLetterOrDigit() || it == '-' }}-$stamp.$format"

private fun encodeAnalytics(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name()).replace("+", "%20")

/** Il testo di una cella: numeri con il punto delle migliaia, `null` come «n.d.». */
internal fun analyticsCellText(cell: JsonElement, unavailable: String): String {
    if (cell is JsonNull) return unavailable
    val primitive = cell as? JsonPrimitive ?: return cell.toString()
    if (primitive.isString) return primitive.content
    primitive.longOrNull?.let { return formatCount(it) }
    primitive.doubleOrNull?.let { return String.format(java.util.Locale.ITALIAN, "%.2f", it) }
    return primitive.content
}

/** «1.234»: il numero come lo scrive il pannello. */
internal fun formatCount(value: Long): String = String.format(java.util.Locale.ITALIAN, "%,d", value)

/** Una misura che può mancare: «n.d.» se manca, intera se lo è, con due decimali altrimenti (il voto medio). */
internal fun formatMetric(value: Double?, unavailable: String): String = when {
    value == null -> unavailable
    value % 1.0 == 0.0 -> formatCount(value.toLong())
    else -> String.format(java.util.Locale.ITALIAN, "%.2f", value)
}

// ---------------------------------------------------------------- chiamate

/** Le chiamate dell'area; un'interfaccia perché i test dei ViewModel usino un server finto. */
internal interface ManagementAnalyticsSource {
    suspend fun summary(subject: ManagementSubject, period: String, page: Int, sharePage: Int): AnalyticsSummary
    suspend fun report(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery): AnalyticsReport
    suspend fun table(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, page: Int): AnalyticsTable
    suspend fun export(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, format: String, directory: File): DownloadedFile
    suspend fun events(subject: ManagementSubject, search: String): EventChoiceGroups
    suspend fun dates(subject: ManagementSubject, eventId: Long): List<DateAnalyticsRow>
    suspend fun occurrence(subject: ManagementSubject, eventId: Long, occurrenceId: Long): OccurrenceAnalyticsReport
    suspend fun shareLinks(subject: ManagementSubject, eventId: Long): EventShareLinks
    suspend fun channels(subject: ManagementSubject): ChannelsList
    suspend fun addChannel(subject: ManagementSubject, label: String, eventId: Long?): ChannelsList
}

internal class ManagementAnalyticsApi(private val api: ApiClient, private val token: String) : ManagementAnalyticsSource {
    override suspend fun summary(subject: ManagementSubject, period: String, page: Int, sharePage: Int): AnalyticsSummary =
        api.get<AnalyticsEnvelope<AnalyticsSummary>>(summaryPath(subject, period, page, sharePage), token).data

    override suspend fun report(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery): AnalyticsReport =
        api.get<AnalyticsEnvelope<AnalyticsReport>>(reportPath(subject, target, query), token).data

    override suspend fun table(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, page: Int): AnalyticsTable =
        api.get<AnalyticsEnvelope<AnalyticsTable>>(reportTablePath(subject, target, query, dataset, page), token).data

    override suspend fun export(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, format: String, directory: File): DownloadedFile {
        val stamp = java.time.LocalDateTime.now().format(java.time.format.DateTimeFormatter.ofPattern("yyyyMMdd-HHmmss"))
        return api.download(reportExportPath(subject, target, query, dataset, format), token, File(directory, analyticsExportName(dataset, format, stamp)))
    }

    override suspend fun events(subject: ManagementSubject, search: String): EventChoiceGroups =
        api.get<AnalyticsEnvelope<EventChoiceGroups>>(eventChoicesPath(subject, search), token).data

    override suspend fun dates(subject: ManagementSubject, eventId: Long): List<DateAnalyticsRow> =
        api.get<AnalyticsEnvelope<List<DateAnalyticsRow>>>("${subject.apiPath}/events/$eventId/dates/analytics", token).data

    override suspend fun occurrence(subject: ManagementSubject, eventId: Long, occurrenceId: Long): OccurrenceAnalyticsReport =
        api.get<AnalyticsEnvelope<OccurrenceAnalyticsReport>>("${subject.apiPath}/events/$eventId/dates/$occurrenceId/analytics", token).data

    override suspend fun shareLinks(subject: ManagementSubject, eventId: Long): EventShareLinks =
        api.get<AnalyticsEnvelope<EventShareLinks>>("${subject.apiPath}/events/$eventId/share-links", token).data

    override suspend fun channels(subject: ManagementSubject): ChannelsList =
        api.get("${subject.apiPath}/analytics/channels", token)

    override suspend fun addChannel(subject: ManagementSubject, label: String, eventId: Long?): ChannelsList {
        val body = buildJsonObject { put("label", label.trim()); eventId?.let { put("event", it) } }
        return api.execute("${subject.apiPath}/analytics/channels", "POST", body.toString(), token)
    }
}

/* Il nome di un canale proprio: lo stesso tetto del campo del pannello. */
internal const val CHANNEL_LABEL_MAX = 38
