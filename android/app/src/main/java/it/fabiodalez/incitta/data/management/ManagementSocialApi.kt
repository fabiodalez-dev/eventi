package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Poster
import java.io.File
import java.net.URLEncoder
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.add
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray

/*
 * P10 — le grafiche social del locale (W-SO-01…09) e l'anteprima della
 * grafica di una data (W-DT-12). Le forme sono quelle di
 * `SocialStudioController`; ogni campo ha un valore predefinito, così un
 * server più nuovo non rompe la lettura.
 *
 * Decisione 3 del piano: la scelta delle serate e le opzioni sono dell'app,
 * le immagini le disegna il server. Quelle di un pacchetto si guardano con
 * l'indirizzo firmato del sito (`url`, sette giorni) e si scaricano, per
 * salvarle o condividerle, dall'API con il token.
 */

@Serializable
internal data class SocialLabel(val value: String = "", val label: String = "")

/** Una serata dello studio, come una card della pagina. */
@Serializable
internal data class SocialCard(
    val id: Long = 0,
    @SerialName("event_id") val eventId: Long = 0,
    val title: String = "",
    /* ISO in UTC; si mostra nel fuso della città (`timezone`). */
    @SerialName("starts_at") val startsAt: String? = null,
    val timezone: String? = null,
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    /* Il giorno della serata («serata» fino alle 6 del mattino dopo), `Y-m-d`. */
    @SerialName("business_date") val businessDate: String = "",
    val status: SocialLabel = SocialLabel(),
    /* Solo le serate in programma si preparano; le altre restano visibili per capire perché. */
    val selectable: Boolean = false,
    val demo: Boolean = false,
    val poster: Poster? = null,
    val venue: String? = null,
)

@Serializable
internal data class SocialEventRef(val id: Long = 0, val title: String = "")

@Serializable
internal data class SocialCatalogMeta(
    val period: String = "next30",
    val from: String = "",
    val until: String = "",
    val today: String = "",
    val timezone: String? = null,
    val total: Int = 0,
    val limit: Int = SOCIAL_PAGE_SIZE,
    @SerialName("page_size") val pageSize: Int = SOCIAL_PAGE_SIZE,
    @SerialName("max_cards") val maxCards: Int = SOCIAL_MAX_CARDS,
    val event: SocialEventRef? = null,
)

/** `GET V/social/catalog`: le serate del periodo a card (W-SO-01). */
@Serializable
internal data class SocialCatalogPage(val data: List<SocialCard> = emptyList(), val meta: SocialCatalogMeta = SocialCatalogMeta()) {
    val hasMore: Boolean get() = meta.total > data.size && meta.limit < meta.maxCards
}

@Serializable
internal data class SocialCalendarDay(
    val date: String = "",
    val day: Int = 0,
    val count: Int = 0,
    @SerialName("in_month") val inMonth: Boolean = false,
    val today: Boolean = false,
    val selected: Boolean = false,
)

@Serializable
internal data class SocialCalendarMeta(
    val month: String = "",
    val label: String = "",
    val previous: String = "",
    val next: String = "",
    val today: String = "",
)

/** `GET V/social/calendar`: sei settimane da lunedì con il numero di serate (W-SO-02). */
@Serializable
internal data class SocialCalendar(val data: List<SocialCalendarDay> = emptyList(), val meta: SocialCalendarMeta = SocialCalendarMeta())

@Serializable
internal data class SocialDayMeta(
    val date: String = "",
    val label: String = "",
    val today: String = "",
    val timezone: String? = null,
    @SerialName("max_selection") val maxSelection: Int = SOCIAL_MAX_SELECTION,
    @SerialName("title_max") val titleMax: Int = SOCIAL_TITLE_MAX,
    val event: SocialEventRef? = null,
)

/** `GET V/social/day`: le serate che si possono preparare in un giorno (W-SO-03). */
@Serializable
internal data class SocialDay(val data: List<SocialCard> = emptyList(), val meta: SocialDayMeta = SocialDayMeta())

@Serializable
internal data class SocialFormatInfo(val value: String = "portrait", val label: String = "", val width: Int = 1080, val height: Int = 1350)

/** Un pacchetto fra i recenti (W-SO-07). */
@Serializable
internal data class SocialBatchSummary(
    val id: String = "",
    val date: String = "",
    val format: SocialFormatInfo = SocialFormatInfo(),
    val count: Int = 0,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable
internal data class SocialBatchItem(
    val index: Int = 0,
    val title: String = "",
    val filename: String = "",
    @SerialName("occurrence_id") val occurrenceId: Long = 0,
    @SerialName("event_id") val eventId: Long = 0,
    val time: String? = null,
    val venue: String? = null,
    /* L'immagine sul sito, con la firma: si guarda senza token. */
    val url: String = "",
)

/** Un pacchetto intero: le immagini nell'ordine dello ZIP e la didascalia del post (W-SO-06). */
@Serializable
internal data class SocialBatch(
    val id: String = "",
    val date: String = "",
    val format: SocialFormatInfo = SocialFormatInfo(),
    val count: Int = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val timezone: String? = null,
    val caption: String = "",
    val items: List<SocialBatchItem> = emptyList(),
)

@Serializable private data class SocialEnvelope<T>(val data: T)
@Serializable private data class SocialRecentEnvelope(val data: List<SocialBatchSummary> = emptyList())

/** I tre formati della pagina, con i valori del server. */
internal enum class SocialFormatChoice(val value: String) {
    Portrait("portrait"), Square("square"), Story("story");

    companion object {
        fun from(value: String?): SocialFormatChoice = entries.firstOrNull { it.value == value } ?: Portrait
    }
}

/** Le opzioni della grafica (W-SO-04), con i valori con cui si apre la pagina. */
internal data class SocialOptions(
    val format: SocialFormatChoice = SocialFormatChoice.Portrait,
    val monochrome: Boolean = true,
    val showAddress: Boolean = true,
    val showPrice: Boolean = true,
    /* `contain` = immagine intera, `cover` = ritagliata. */
    val cover: Boolean = false,
    val title: String = "",
)

/** Le domande dell'elenco a card: periodo, dal/al, ricerca, quante card, evento. */
internal data class SocialCatalogQuery(
    val period: String = "next30",
    val from: String = "",
    val until: String = "",
    val search: String = "",
    val limit: Int = SOCIAL_PAGE_SIZE,
    val eventId: Long? = null,
)

internal const val SOCIAL_PAGE_SIZE = 24
internal const val SOCIAL_MAX_CARDS = 240
internal const val SOCIAL_MAX_SELECTION = 100
internal const val SOCIAL_TITLE_MAX = 300

/** Le chiamate di P10. È un'interfaccia perché i ViewModel si provano con un finto, senza rete. */
internal interface ManagementSocialSource {
    suspend fun catalog(venueId: Long, query: SocialCatalogQuery): SocialCatalogPage
    suspend fun calendar(venueId: Long, month: String, date: String, eventId: Long?, search: String): SocialCalendar
    suspend fun day(venueId: Long, date: String?, eventId: Long?): SocialDay
    suspend fun events(venueId: Long, search: String): EventChoiceGroups
    suspend fun recent(venueId: Long): List<SocialBatchSummary>
    suspend fun batch(venueId: Long, id: String): SocialBatch
    suspend fun prepare(venueId: Long, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): SocialBatch
    suspend fun image(venueId: Long, batchId: String, item: SocialBatchItem, directory: File): DownloadedFile
    suspend fun zip(venueId: Long, batch: SocialBatch, directory: File): DownloadedFile
    suspend fun preview(subject: ManagementSubject, eventId: Long, dateId: Long, directory: File): DownloadedFile
}

internal class ManagementSocialApi(private val api: ApiClient, private val token: String) : ManagementSocialSource {
    override suspend fun catalog(venueId: Long, query: SocialCatalogQuery): SocialCatalogPage =
        api.get(socialCatalogPath(venueId, query), token)

    override suspend fun calendar(venueId: Long, month: String, date: String, eventId: Long?, search: String): SocialCalendar =
        api.get(socialPath(venueId, "calendar", listOf("month" to month, "date" to date, "search" to search.trim(), "event" to eventId?.toString())), token)

    override suspend fun day(venueId: Long, date: String?, eventId: Long?): SocialDay =
        api.get(socialPath(venueId, "day", listOf("date" to date, "event" to eventId?.toString())), token)

    override suspend fun events(venueId: Long, search: String): EventChoiceGroups =
        api.get<SocialEnvelope<EventChoiceGroups>>(socialPath(venueId, "events", listOf("q" to search.trim())), token).data

    override suspend fun recent(venueId: Long): List<SocialBatchSummary> =
        api.get<SocialRecentEnvelope>(socialPath(venueId, "batches"), token).data

    override suspend fun batch(venueId: Long, id: String): SocialBatch =
        api.get<SocialEnvelope<SocialBatch>>(socialPath(venueId, "batches/$id"), token).data

    override suspend fun prepare(venueId: Long, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): SocialBatch =
        api.post<SocialEnvelope<SocialBatch>, JsonObject>(socialPath(venueId, "batches"), socialPrepareBody(date, occurrences, options, eventId), token).data

    override suspend fun image(venueId: Long, batchId: String, item: SocialBatchItem, directory: File): DownloadedFile =
        api.download(socialPath(venueId, "batches/$batchId/images/${item.index}"), token, File(directory, socialFileName(item.filename, "jpg")))

    override suspend fun zip(venueId: Long, batch: SocialBatch, directory: File): DownloadedFile =
        api.download(socialPath(venueId, "batches/${batch.id}/zip"), token, File(directory, "incitta-${batch.date}-${batch.format.value}.zip"))

    override suspend fun preview(subject: ManagementSubject, eventId: Long, dateId: Long, directory: File): DownloadedFile =
        api.download(socialPreviewPath(subject, eventId, dateId), token, File(directory, "grafica-$eventId-$dateId.jpg"))
}

/** Il percorso di una lettura dello studio, con i parametri non vuoti. */
internal fun socialPath(venueId: Long, tail: String, params: List<Pair<String, String?>> = emptyList()): String {
    val query = params.filter { !it.second.isNullOrBlank() }.joinToString("&") { (key, value) -> key + "=" + encodeSocial(value.orEmpty()) }
    return "management/${SubjectKind.Venue.segment}/$venueId/social/$tail" + if (query.isEmpty()) "" else "?$query"
}

internal fun socialCatalogPath(venueId: Long, query: SocialCatalogQuery): String = socialPath(venueId, "catalog", listOf(
    "period" to query.period,
    "from" to query.from.takeIf { query.period == "custom" },
    "until" to query.until.takeIf { query.period == "custom" },
    "search" to query.search.trim(),
    "limit" to query.limit.takeIf { it != SOCIAL_PAGE_SIZE }?.toString(),
    "event" to query.eventId?.toString(),
))

/** L'anteprima JPEG della grafica di una data: vale per il locale e per l'organizzazione. */
internal fun socialPreviewPath(subject: ManagementSubject, eventId: Long, dateId: Long): String =
    "${subject.apiPath}/events/$eventId/dates/$dateId/social-preview"

/** Il corpo di «Prepara le grafiche selezionate». */
internal fun socialPrepareBody(date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): JsonObject = buildJsonObject {
    put("date", date)
    putJsonArray("occurrences") { occurrences.forEach { add(it) } }
    put("format", options.format.value)
    put("monochrome", options.monochrome)
    put("show_address", options.showAddress)
    put("show_price", options.showPrice)
    put("image_fit", if (options.cover) "cover" else "contain")
    options.title.trim().takeIf { it.isNotEmpty() }?.let { put("title", it) }
    eventId?.let { put("event", it) }
}

/** Un nome di file sicuro per la cache: solo lettere, cifre, trattini e punti. */
internal fun socialFileName(name: String, extension: String): String {
    val clean = name.substringAfterLast('/').map { if (it.isLetterOrDigit() || it == '-' || it == '.' || it == '_') it else '-' }.joinToString("").trim('.', '-')
    val base = clean.ifBlank { "grafica" }.removeSuffix(".$extension").removeSuffix(".${extension.uppercase()}").take(70).trim('.', '-').ifBlank { "grafica" }
    return "$base.$extension"
}

private fun encodeSocial(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name()).replace("+", "%20")
