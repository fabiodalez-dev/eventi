package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/*
 * R14 — il calendario della redazione (R-EV-27), la campanella (R-DB-11) e
 * gli indirizzi del pannello con uno slug (`/admin/events/{slug}`) tradotti
 * nell'id della scheda nativa. Le schermate chiamano tutto dentro
 * `repository.call { }`, che si occupa dei 423.
 */

/** Lo stato dell'evento o della data, con l'etichetta del server. */
@Serializable
internal data class CalendarLabel(val value: String = "", val label: String = "")

@Serializable
internal data class CalendarCity(val id: Long = 0, val name: String = "", val timezone: String = "Europe/Rome")

@Serializable
internal data class CalendarAbilities(val open: Boolean = false, val edit: Boolean = false)

/**
 * Una data del calendario: `title` è quello del pannello («Evento · Locale»),
 * `date` il giorno nel fuso della città dell'evento, `color` il colore dello
 * stato dell'evento (`#7a9900`, …).
 */
@Serializable
internal data class CalendarItem(
    val id: Long = 0,
    @SerialName("event_id") val eventId: Long = 0,
    val title: String = "",
    @SerialName("event_title") val eventTitle: String = "",
    val venue: String? = null,
    val city: CalendarCity = CalendarCity(),
    val date: String = "",
    @SerialName("starts_at") val startsAt: String = "",
    @SerialName("ends_at") val endsAt: String = "",
    @SerialName("all_day") val allDay: Boolean = false,
    @SerialName("event_status") val eventStatus: CalendarLabel = CalendarLabel(),
    val status: CalendarLabel = CalendarLabel(),
    val color: String = "#6b6b66",
    val abilities: CalendarAbilities = CalendarAbilities(),
)

@Serializable
internal data class CalendarLegendEntry(val value: String = "", val label: String = "", val color: String = "#6b6b66")

@Serializable
internal data class CalendarMeta(
    val from: String = "",
    val to: String = "",
    val timezone: String = "Europe/Rome",
    val total: Int = 0,
    val truncated: Boolean = false,
    val legend: List<CalendarLegendEntry> = emptyList(),
)

@Serializable
internal data class CalendarWindow(val data: List<CalendarItem> = emptyList(), val meta: CalendarMeta = CalendarMeta())

@Serializable
internal data class BellAction(val label: String = "", val url: String = "")

/** Una notifica della campanella, come la disegna il pannello. `url` è un indirizzo del pannello (`/admin/…`). */
@Serializable
internal data class BellItem(
    val id: String = "",
    val title: String = "",
    val body: String? = null,
    val status: String? = null,
    val category: String? = null,
    val url: String? = null,
    val actions: List<BellAction> = emptyList(),
    val read: Boolean = false,
    @SerialName("read_at") val readAt: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable
internal data class BellMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    @SerialName("per_page") val perPage: Int = 50,
    val total: Int = 0,
    val unread: Int = 0,
)

@Serializable
internal data class BellPage(val data: List<BellItem> = emptyList(), val meta: BellMeta = BellMeta())

@Serializable
internal data class BellChangeMeta(val message: String? = null, val unread: Int = 0)

@Serializable
internal data class BellChange(val data: BellItem? = null, val meta: BellChangeMeta = BellChangeMeta())

/** `events` o `venues`, l'id della scheda e il titolo. */
@Serializable
internal data class EditorialLinkTarget(val type: String = "", val id: Long = 0, val title: String = "")

internal interface EditorialCalendarSource {
    /** `from` e `to` (`AAAA-MM-GG`, compresi) nel fuso della città di lavoro. */
    suspend fun calendar(from: String, to: String, city: String?): CalendarWindow
    suspend fun notifications(page: Int = 1): BellPage
    suspend fun markRead(id: String): BellChange
    suspend fun markUnread(id: String): BellChange
    suspend fun remove(id: String): BellChange
    suspend fun markAllRead(): BellChange
    suspend fun clear(): BellChange
    /** L'id della scheda di un indirizzo del pannello con uno slug; 404 se non c'è più. */
    suspend fun resolve(type: String, slug: String): EditorialLinkTarget
}

internal class CalendarApi(private val api: ApiClient, private val token: String) : EditorialCalendarSource {
    override suspend fun calendar(from: String, to: String, city: String?): CalendarWindow =
        api.get(editorialPath("calendar", city, listOf("from" to from, "to" to to)), token)

    override suspend fun notifications(page: Int): BellPage =
        api.get(editorialPath("notifications", null, if (page > 1) listOf("page" to page.toString()) else emptyList()), token)

    override suspend fun markRead(id: String): BellChange = post("notifications/$id/read")
    override suspend fun markUnread(id: String): BellChange = post("notifications/$id/unread")
    override suspend fun remove(id: String): BellChange = post("notifications/$id/remove")
    override suspend fun markAllRead(): BellChange = post("notifications/read-all")
    override suspend fun clear(): BellChange = post("notifications/clear")

    override suspend fun resolve(type: String, slug: String): EditorialLinkTarget =
        api.get<ApiEnvelope<EditorialLinkTarget>>(editorialPath("links/$type/$slug", null), token).data

    private suspend fun post(path: String): BellChange = api.execute(editorialPath(path, null), "POST", "{}", token)
}

/** L'API di R14 per la sessione del repository. */
internal fun EditorialRepository.calendarApi(): EditorialCalendarSource =
    CalendarApi(client ?: throw IllegalStateException("Calendario non disponibile in questa sessione"), token)
