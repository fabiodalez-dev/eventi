package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.management.AnalyticsEnvelope
import it.fabiodalez.incitta.data.management.AnalyticsOption
import it.fabiodalez.incitta.data.management.AnalyticsPeriods
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.analyticsExportName
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import java.io.File
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * R10 — Statistiche, SEO, ricerche (R-ST-01…06), le tre voci del gruppo
 * «Statistiche» del pannello della redazione:
 *
 * - «Statistiche condivisioni» e la pagina di un soggetto: la stessa forma
 *   delle statistiche dell'app di gestione (`AnalyticsReport`,
 *   `AnalyticsTable`), con in più i filtri «Locale» e «Organizzatore»;
 * - «SEO e qualità del catalogo»: il modulo di Search Console e il controllo
 *   degli ultimi duecento eventi pubblici;
 * - «Ricerche senza risultati»: l'elenco, con «Elimina» su una riga o su un
 *   gruppo.
 *
 * Le etichette arrivano dal server, le stesse del pannello. Ogni campo ha un
 * valore predefinito. La città conta solo per il fuso delle statistiche
 * («oggi» è quello della città di lavoro): SEO e ricerche guardano tutte le
 * città, come il pannello.
 */

// ------------------------------------------------------ statistiche

/**
 * I filtri di «Statistiche condivisioni»: periodo (o due date `AAAA-MM-GG`),
 * locale, organizzatore, evento, canale. Nella pagina di un soggetto contano
 * solo periodo e canale: il soggetto è quello della rotta, come nel pannello.
 */
internal data class EditorialReportQuery(
    val period: String = AnalyticsPeriods.DEFAULT,
    val from: String? = null,
    val until: String? = null,
    val venue: Long? = null,
    val organizer: Long? = null,
    val event: Long? = null,
    val channel: String? = null,
) {
    val custom: Boolean get() = period == AnalyticsPeriods.CUSTOM

    fun params(includeEntities: Boolean = true): List<Pair<String, String>> = buildList {
        add("period" to period)
        if (custom) {
            from?.takeIf { it.isNotBlank() }?.let { add("from" to it) }
            until?.takeIf { it.isNotBlank() }?.let { add("until" to it) }
        }
        if (includeEntities) {
            venue?.takeIf { it > 0 }?.let { add("venue" to it.toString()) }
            organizer?.takeIf { it > 0 }?.let { add("organizer" to it.toString()) }
            event?.takeIf { it > 0 }?.let { add("event" to it.toString()) }
        }
        channel?.takeIf { it.isNotBlank() }?.let { add("channel" to it) }
    }

    /** Solo periodo e canale: quello che passa da una pagina all'altra, come `detailUrl` del pannello. */
    fun periodAndChannel(): EditorialReportQuery = EditorialReportQuery(period = period, from = from, until = until, channel = channel)

    /** Quanti filtri di soggetto o canale sono accesi (il numero accanto a «Filtri»). */
    val activeFilters: Int get() = listOf(venue, organizer, event).count { (it ?: 0) > 0 } + (if (channel.isNullOrBlank()) 0 else 1)
}

/** Il percorso di base: `analytics/report` oppure `analytics/subjects/{type}/{id}`. */
internal fun editorialReportBase(target: AnalyticsTarget?): String =
    if (target == null) "analytics/report" else "analytics/subjects/${target.type}/${target.id}"

internal fun editorialReportPath(target: AnalyticsTarget?, query: EditorialReportQuery, city: String?, suffix: String = "", extra: List<Pair<String, String>> = emptyList()): String =
    editorialPath(editorialReportBase(target) + suffix, city, query.params(includeEntities = target == null) + extra)

/** Una voce dei filtri «Locale» e «Organizzatore». */
@Serializable
internal data class AnalyticsProfileChoice(val id: Long = 0, val name: String = "")

// ------------------------------------------------------------------ SEO

@Serializable
internal data class SeoCity(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    @SerialName("is_active") val isActive: Boolean = true,
    val verification: String? = null,
)

@Serializable
internal data class SeoSearchConsole(
    val title: String = "",
    val help: String = "",
    @SerialName("city_label") val cityLabel: String = "",
    @SerialName("code_label") val codeLabel: String = "",
    @SerialName("code_help") val codeHelp: String = "",
    @SerialName("save_label") val saveLabel: String = "",
    val max: Int = SEO_CODE_MAX,
    val pattern: String = SEO_CODE_PATTERN,
    @SerialName("default_city_id") val defaultCityId: Long? = null,
    val cities: List<SeoCity> = emptyList(),
)

@Serializable
internal data class SeoStep(val text: String = "", val code: String? = null)

@Serializable
internal data class SeoSteps(val heading: String = "", val items: List<SeoStep> = emptyList())

@Serializable
internal data class SeoTool(val key: String = "", val label: String = "", val url: String = "")

@Serializable
internal data class SeoTools(val heading: String = "", val description: String = "", val links: List<SeoTool> = emptyList())

@Serializable
internal data class SeoAuditInfo(
    val heading: String = "",
    val help: String = "",
    val empty: String = "",
    val complete: String = "",
    val tabs: List<SeoTab> = emptyList(),
)

@Serializable
internal data class SeoTab(val key: String = "", val label: String = "")

/** `GET E/seo`: la pagina «SEO e qualità del catalogo» senza le righe del controllo. */
@Serializable
internal data class SeoPage(
    val title: String = "",
    @SerialName("search_console") val searchConsole: SeoSearchConsole = SeoSearchConsole(),
    val steps: SeoSteps = SeoSteps(),
    val tools: SeoTools = SeoTools(),
    val audit: SeoAuditInfo = SeoAuditInfo(),
)

/** Una riga del controllo: titolo, indirizzo pubblico, i problemi trovati. */
@Serializable
internal data class SeoAuditRow(
    val key: String = "",
    val title: String = "",
    val url: String = "",
    val issues: List<String> = emptyList(),
    val complete: Boolean = false,
)

@Serializable
internal data class SeoMessage(val message: String? = null)

@Serializable
internal data class SeoVerificationResult(val data: SeoCity = SeoCity(), val meta: SeoMessage = SeoMessage())

@Serializable
internal data class SeoVerificationBody(@SerialName("city_id") val cityId: Long, val verification: String?)

/* Il campo del codice: gli stessi limiti del pannello (`SearchConsoleVerification`). */
internal const val SEO_CODE_MAX = 255
internal const val SEO_CODE_PATTERN = "^[A-Za-z0-9_-]+$"

/** `null` se il codice va bene (vuoto compreso: toglie il codice), altrimenti il motivo. */
internal enum class SeoCodeProblem { TooLong, Invalid }

internal fun seoCodeProblem(code: String, max: Int = SEO_CODE_MAX, pattern: String = SEO_CODE_PATTERN): SeoCodeProblem? {
    val value = code.trim()
    if (value.isEmpty()) return null
    if (value.length > max) return SeoCodeProblem.TooLong
    val regex = runCatching { Regex(pattern) }.getOrElse { Regex(SEO_CODE_PATTERN) }
    return if (regex.matches(value)) null else SeoCodeProblem.Invalid
}

// ------------------------------------------------- ricerche senza risultati

@Serializable
internal data class SearchMissCity(val id: Long = 0, val name: String = "")

@Serializable
internal data class SearchMissRow(
    val id: Long = 0,
    val term: String = "",
    val count: Long = 0,
    @SerialName("last_searched_at") val lastSearchedAt: String? = null,
    @SerialName("last_searched_label") val lastSearchedLabel: String? = null,
    @SerialName("first_searched_at") val firstSearchedAt: String? = null,
    @SerialName("first_searched_label") val firstSearchedLabel: String? = null,
    val city: SearchMissCity? = null,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class SearchMissChoices(val cities: List<AnalyticsOption> = emptyList())

/** `GET E/search-misses`: una pagina dell'elenco e le città del filtro. */
@Serializable
internal data class SearchMissPage(
    val data: List<SearchMissRow> = emptyList(),
    val meta: PageMeta = PageMeta(),
    val choices: SearchMissChoices = SearchMissChoices(),
) {
    fun page(): EditorialPage<SearchMissRow> = EditorialPage(data, meta)
}

@Serializable
internal data class SearchMissRemoved(val data: JsonElement? = null, val meta: SeoMessage = SeoMessage())

@Serializable
internal data class SearchMissBulkBody(val ids: List<Long>)

/* Quante righe al massimo in una sola eliminazione di gruppo, come il server. */
internal const val SEARCH_MISS_BULK_MAX = 200

// ---------------------------------------------------------------- chiamate

/** Le chiamate dell'area; un'interfaccia perché i test dei ViewModel usino un server finto. */
internal interface EditorialAnalyticsSource {
    suspend fun report(target: AnalyticsTarget?, query: EditorialReportQuery, city: String?): AnalyticsReport
    suspend fun table(target: AnalyticsTarget?, query: EditorialReportQuery, dataset: String, page: Int, city: String?): AnalyticsTable
    suspend fun export(target: AnalyticsTarget?, query: EditorialReportQuery, dataset: String, format: String, city: String?, directory: File): DownloadedFile
    suspend fun events(search: String, venue: Long?, organizer: Long?, exclude: Long?, city: String?): EventChoiceGroups
    /** `type` è `venues` oppure `organizers`. */
    suspend fun profiles(type: String, search: String, city: String?): List<AnalyticsProfileChoice>

    suspend fun seo(): SeoPage
    suspend fun seoAudit(params: List<Pair<String, String>>): EditorialPage<SeoAuditRow>
    suspend fun saveVerification(cityId: Long, code: String?): SeoVerificationResult

    suspend fun searchMisses(params: List<Pair<String, String>>): SearchMissPage
    suspend fun removeSearchMiss(id: Long): SearchMissRemoved
    suspend fun removeSearchMisses(ids: List<Long>): SearchMissRemoved
}

internal class EditorialAnalyticsApi(private val api: ApiClient, private val token: String) : EditorialAnalyticsSource {
    override suspend fun report(target: AnalyticsTarget?, query: EditorialReportQuery, city: String?): AnalyticsReport =
        api.get<AnalyticsEnvelope<AnalyticsReport>>(editorialReportPath(target, query, city), token).data

    override suspend fun table(target: AnalyticsTarget?, query: EditorialReportQuery, dataset: String, page: Int, city: String?): AnalyticsTable =
        api.get<AnalyticsEnvelope<AnalyticsTable>>(editorialReportPath(target, query, city, "/table", listOf("dataset" to dataset, "page" to page.toString())), token).data

    override suspend fun export(target: AnalyticsTarget?, query: EditorialReportQuery, dataset: String, format: String, city: String?, directory: File): DownloadedFile {
        val stamp = java.time.LocalDateTime.now().format(java.time.format.DateTimeFormatter.ofPattern("yyyyMMdd-HHmmss"))
        return api.download(editorialReportPath(target, query, city, "/export", listOf("dataset" to dataset, "format" to format)), token, File(directory, analyticsExportName(dataset, format, stamp)))
    }

    override suspend fun events(search: String, venue: Long?, organizer: Long?, exclude: Long?, city: String?): EventChoiceGroups =
        api.get<AnalyticsEnvelope<EventChoiceGroups>>(editorialPath("analytics/events", city, buildList {
            search.trim().takeIf { it.isNotEmpty() }?.let { add("q" to it) }
            venue?.takeIf { it > 0 }?.let { add("venue" to it.toString()) }
            organizer?.takeIf { it > 0 }?.let { add("organizer" to it.toString()) }
            exclude?.takeIf { it > 0 }?.let { add("exclude" to it.toString()) }
        }), token).data

    override suspend fun profiles(type: String, search: String, city: String?): List<AnalyticsProfileChoice> {
        require(type == "venues" || type == "organizers")
        return api.get<AnalyticsEnvelope<List<AnalyticsProfileChoice>>>(editorialPath("analytics/$type", city, search.trim().takeIf { it.isNotEmpty() }?.let { listOf("q" to it) } ?: emptyList()), token).data
    }

    override suspend fun seo(): SeoPage = api.get<AnalyticsEnvelope<SeoPage>>(editorialPath("seo", null), token).data

    override suspend fun seoAudit(params: List<Pair<String, String>>): EditorialPage<SeoAuditRow> = api.get(editorialPath("seo/audit", null, params), token)

    override suspend fun saveVerification(cityId: Long, code: String?): SeoVerificationResult =
        api.execute(editorialPath("seo/verification", null), "PUT", api.json.encodeToString(SeoVerificationBody.serializer(), SeoVerificationBody(cityId, code?.trim()?.takeIf { it.isNotEmpty() })), token)

    override suspend fun searchMisses(params: List<Pair<String, String>>): SearchMissPage = api.get(editorialPath("search-misses", null, params), token)

    override suspend fun removeSearchMiss(id: Long): SearchMissRemoved = api.delete(editorialPath("search-misses/$id", null), token)

    override suspend fun removeSearchMisses(ids: List<Long>): SearchMissRemoved =
        api.post(editorialPath("search-misses/bulk-delete", null), SearchMissBulkBody(ids), token)
}

/** L'API dell'area per la sessione del repository (il client HTTP lo dà `EditorialRepository.create`). */
internal fun EditorialRepository.analyticsApi(): EditorialAnalyticsSource =
    EditorialAnalyticsApi(client ?: throw IllegalStateException("Statistiche non disponibili in questa sessione"), token)
