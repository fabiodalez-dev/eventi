package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.EventDetail
import java.net.URLEncoder
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/** Le schede dell'elenco eventi, come nei pannelli. */
internal enum class EventsTab(val value: String) { All("all"), Upcoming("upcoming"), Past("past"), Drafts("drafts") }

/**
 * Le rotte degli eventi della gestione (P1), sotto `S` =
 * `management/venues/{id}` o `management/organizers/{id}`. Ogni chiamata
 * prende il soggetto, così le rotte del solo locale o della sola
 * organizzazione non partono per sbaglio dall'altro.
 */
internal class ManagementEventsApi(private val api: ApiClient, private val token: String) {
    suspend fun list(subject: ManagementSubject, tab: EventsTab, status: String?, query: String?, page: Int): ManagedEventsPage {
        val params = buildList {
            add("tab=${tab.value}")
            add("page=$page")
            status?.takeIf { it.isNotBlank() }?.let { add("status=${encode(it)}") }
            query?.trim()?.takeIf { it.isNotEmpty() }?.let { add("q=${encode(it)}") }
        }.joinToString("&")
        return api.get("${subject.apiPath}/events?$params", token)
    }

    suspend fun detail(subject: ManagementSubject, eventId: Long): ManagedEventDetail =
        api.get<ApiEnvelope<ManagedEventDetail>>("${subject.apiPath}/events/$eventId", token).data

    suspend fun form(subject: ManagementSubject, venueId: Long? = null): EventFormOptions =
        api.get<ApiEnvelope<EventFormOptions>>("${subject.apiPath}/events/form" + (venueId?.let { "?venue_id=$it" } ?: ""), token).data

    /** Nuovo evento: `body` lo prepara `EventForm`; per il locale porta `publish` e, dopo la prima bozza, `draft_id`. */
    suspend fun create(subject: ManagementSubject, body: JsonObject): EventSaveResponse =
        api.execute("${subject.apiPath}/events", "POST", body.toString(), token)

    suspend fun update(subject: ManagementSubject, eventId: Long, body: JsonObject): EventSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId", "PATCH", body.toString(), token)

    suspend fun delete(subject: ManagementSubject, eventId: Long) =
        api.send("${subject.apiPath}/events/$eventId", "DELETE", token)

    suspend fun publish(subject: ManagementSubject, eventId: Long): EventSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/publish", "POST", "{}", token)

    suspend fun submit(subject: ManagementSubject, eventId: Long): EventSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/submit", "POST", "{}", token)

    suspend fun duplicate(subject: ManagementSubject, eventId: Long): ManagedEventDetail =
        api.execute<ApiEnvelope<ManagedEventDetail>>("${subject.apiPath}/events/$eventId/duplicate", "POST", "{}", token).data

    /** `publishAt` è l'istante con lo scarto della città (`cityIso`). */
    suspend fun schedule(subject: ManagementSubject, eventId: Long, publishAt: String): EventSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/schedule", "PUT", buildJsonObject { put("publish_at", publishAt) }.toString(), token)

    suspend fun cancelSchedule(subject: ManagementSubject, eventId: Long): EventSaveResponse =
        api.execute("${subject.apiPath}/events/$eventId/schedule", "DELETE", "{}", token)

    /** La pagina pubblica dell'evento anche se non è pubblicato, nella forma di `GET /events/{slug}`. */
    suspend fun preview(subject: ManagementSubject, eventId: Long): EventDetail =
        api.get<ApiEnvelope<EventDetail>>("${subject.apiPath}/events/$eventId/preview", token).data

    suspend fun checks(subject: ManagementSubject, body: JsonObject): EventDateChecksResult =
        api.execute<ApiEnvelope<EventDateChecksResult>>("${subject.apiPath}/events/checks", "POST", body.toString(), token).data

    suspend fun importFacebook(subject: ManagementSubject, url: String, cityId: Long?): FacebookImportResult =
        api.execute<ApiEnvelope<FacebookImportResult>>("${subject.apiPath}/events/import/facebook", "POST", buildJsonObject {
            put("url", url)
            cityId?.let { put("city_id", it) }
        }.toString(), token).data

    suspend fun tags(subject: ManagementSubject, query: String): List<NamedRef> =
        api.get<ApiEnvelope<List<NamedRef>>>("${subject.apiPath}/events/lookup/tags?q=${encode(query)}", token).data

    suspend fun createTag(subject: ManagementSubject, name: String, eventId: Long?): NamedRef =
        api.execute<ApiEnvelope<NamedRef>>("${subject.apiPath}/tags", "POST", buildJsonObject {
            put("name", name)
            eventId?.let { put("event_id", it) }
        }.toString(), token).data

    suspend fun venues(subject: ManagementSubject, query: String): List<VenueLookup> =
        api.get<ApiEnvelope<List<VenueLookup>>>("${subject.apiPath}/events/lookup/venues?q=${encode(query)}", token).data

    private fun encode(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name())
}
