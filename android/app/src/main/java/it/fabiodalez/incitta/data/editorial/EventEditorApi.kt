package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.galleryOrderBody
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * R3 — le rotte del modulo evento e delle date della redazione:
 * `E/events/form`, `E/events/{id}/form`, `POST`/`PATCH E/events`, locandina e
 * galleria, ricerche dei campi a scelta, «Prima di andare» quando cambia il
 * locale, programmazione, «Genera date», e `E/events/{id}/dates`.
 */

/** Le date dopo una lettura singola: la data e il quadro dell'evento. */
@Serializable
internal data class EditorDateEnvelope(val data: EditorDate = EditorDate(), val meta: EditorDatesMeta = EditorDatesMeta())

/** Quello che le schermate del modulo chiedono al server. */
internal interface EventEditorSource {
    suspend fun newForm(): EditorFormEnvelope
    suspend fun editForm(id: Long): EditorFormEnvelope
    suspend fun create(body: JsonObject): EditorEventResult
    suspend fun update(id: Long, body: JsonObject): EditorEventResult
    suspend fun rebaseBeforeGoing(previousVenueId: Long?, venueId: Long?, details: JsonObject): JsonObject
    suspend fun venues(query: String, cityId: Long?, approved: Boolean): List<EditorVenueChoice>
    suspend fun organizers(query: String): List<NamedRef>
    suspend fun tags(query: String): EditorTagResults
    suspend fun createTag(name: String, eventId: Long?): NamedRef
    suspend fun uploadPoster(id: Long, image: PreparedImage): EditorImages
    suspend fun removePoster(id: Long): EditorImages
    suspend fun addGalleryPhoto(id: Long, image: PreparedImage): EditorImages
    suspend fun removeGalleryPhoto(id: Long, mediaId: Long): EditorImages
    suspend fun orderGallery(id: Long, ids: List<Long>): EditorImages
    suspend fun schedule(id: Long, publishAt: String): EditorEventResult
    suspend fun unschedule(id: Long): EditorEventResult
    suspend fun generate(id: Long): EditorEventResult

    suspend fun dates(eventId: Long, page: Int): EditorDatesPage
    suspend fun date(eventId: Long, dateId: Long): EditorDateEnvelope
    suspend fun createDate(eventId: Long, body: JsonObject): EditorDateResult
    suspend fun updateDate(eventId: Long, dateId: Long, body: JsonObject): EditorDateResult
    suspend fun shiftDate(eventId: Long, dateId: Long, minutes: Int, scope: String): EditorDateResult
    suspend fun cancelDate(eventId: Long, dateId: Long, note: String?, scope: String): EditorDateResult
    suspend fun toggleSoldOut(eventId: Long, dateId: Long): EditorDateResult
    suspend fun deleteDate(eventId: Long, dateId: Long)
}

internal class EventEditorApi(
    private val api: ApiClient,
    private val token: String,
    /* La città scelta nel selettore: decide la città predefinita di un evento nuovo, come `CurrentCity` nel pannello. */
    private val city: () -> String? = { null },
) : EventEditorSource {
    private fun path(path: String, params: List<Pair<String, String>> = emptyList()) = editorialPath(path, city(), params)

    override suspend fun newForm(): EditorFormEnvelope = api.get<ApiEnvelope<EditorFormEnvelope>>(path("events/form"), token).data
    override suspend fun editForm(id: Long): EditorFormEnvelope = api.get<ApiEnvelope<EditorFormEnvelope>>(path("events/$id/form"), token).data
    override suspend fun create(body: JsonObject): EditorEventResult = api.post(path("events"), body, token)
    override suspend fun update(id: Long, body: JsonObject): EditorEventResult = send("events/$id", "PATCH", body)

    override suspend fun rebaseBeforeGoing(previousVenueId: Long?, venueId: Long?, details: JsonObject): JsonObject {
        val body = buildJsonObject {
            previousVenueId?.let { put("previous_venue_id", it) }
            venueId?.let { put("venue_id", it) }
            put("content_details", details)
        }
        val result = api.post<ApiEnvelope<JsonObject>, JsonObject>(path("events/before-going"), body, token).data
        return result["content_details"] as? JsonObject ?: JsonObject(emptyMap())
    }

    override suspend fun venues(query: String, cityId: Long?, approved: Boolean): List<EditorVenueChoice> =
        api.get<ApiEnvelope<List<EditorVenueChoice>>>(path("events/lookup/venues", buildList {
            add("q" to query)
            cityId?.let { add("city_id" to it.toString()) }
            if (approved) add("approved" to "1")
        }), token).data

    override suspend fun organizers(query: String): List<NamedRef> =
        api.get<ApiEnvelope<List<NamedRef>>>(path("events/lookup/organizers", listOf("q" to query)), token).data

    override suspend fun tags(query: String): EditorTagResults = api.get(path("events/lookup/tags", listOf("q" to query)), token)

    override suspend fun createTag(name: String, eventId: Long?): NamedRef =
        api.post<ApiEnvelope<NamedRef>, JsonObject>(path("events/lookup/tags"), buildJsonObject { put("name", name); eventId?.let { put("event_id", it) } }, token).data

    override suspend fun uploadPoster(id: Long, image: PreparedImage): EditorImages = upload("events/$id/poster", image)
    override suspend fun removePoster(id: Long): EditorImages = images(api.execute<JsonObject>(path("events/$id/poster"), "DELETE", "{}", token))
    override suspend fun addGalleryPhoto(id: Long, image: PreparedImage): EditorImages = upload("events/$id/gallery", image)
    override suspend fun removeGalleryPhoto(id: Long, mediaId: Long): EditorImages = images(api.execute<JsonObject>(path("events/$id/gallery/$mediaId"), "DELETE", "{}", token))
    override suspend fun orderGallery(id: Long, ids: List<Long>): EditorImages =
        images(api.execute<JsonObject>(path("events/$id/gallery/order"), "PUT", galleryOrderBody(ids).toString(), token))

    override suspend fun schedule(id: Long, publishAt: String): EditorEventResult = send("events/$id/schedule", "PUT", buildJsonObject { put("publish_at", publishAt) })
    override suspend fun unschedule(id: Long): EditorEventResult = send("events/$id/schedule", "DELETE", JsonObject(emptyMap()))
    override suspend fun generate(id: Long): EditorEventResult = api.post(path("events/$id/occurrences/generate"), JsonObject(emptyMap()), token)

    override suspend fun dates(eventId: Long, page: Int): EditorDatesPage = api.get(path("events/$eventId/dates", listOf("page" to page.toString())), token)
    override suspend fun date(eventId: Long, dateId: Long): EditorDateEnvelope = api.get(path("events/$eventId/dates/$dateId"), token)
    override suspend fun createDate(eventId: Long, body: JsonObject): EditorDateResult = api.post(path("events/$eventId/dates"), body, token)
    override suspend fun updateDate(eventId: Long, dateId: Long, body: JsonObject): EditorDateResult =
        api.execute(path("events/$eventId/dates/$dateId"), "PATCH", body.toString(), token)
    override suspend fun shiftDate(eventId: Long, dateId: Long, minutes: Int, scope: String): EditorDateResult =
        api.post(path("events/$eventId/dates/$dateId/shift"), buildJsonObject { put("shift_minutes", minutes); put("scope", scope) }, token)
    override suspend fun cancelDate(eventId: Long, dateId: Long, note: String?, scope: String): EditorDateResult =
        api.post(path("events/$eventId/dates/$dateId/status"), buildJsonObject {
            put("action", "cancel"); note?.takeIf { it.isNotBlank() }?.let { put("status_note", it) }; put("scope", scope)
        }, token)
    override suspend fun toggleSoldOut(eventId: Long, dateId: Long): EditorDateResult =
        api.post(path("events/$eventId/dates/$dateId/status"), buildJsonObject { put("action", "sold_out") }, token)
    override suspend fun deleteDate(eventId: Long, dateId: Long) = api.send(path("events/$eventId/dates/$dateId"), "DELETE", token)

    private suspend fun send(path: String, method: String, body: JsonObject): EditorEventResult = api.execute(path(path), method, body.toString(), token)

    private suspend fun upload(path: String, image: PreparedImage): EditorImages =
        images(api.upload(path(path), token, "image", image.bytes, image.mime, image.fileName))

    private fun images(response: JsonObject): EditorImages =
        (response["data"] as? JsonElement)?.let { lenient.decodeFromJsonElement(EditorImages.serializer(), it) } ?: EditorImages()

    companion object {
        private val lenient = Json { ignoreUnknownKeys = true; explicitNulls = false }

        /** L'API della sessione della redazione: client e token del repository. */
        fun of(repository: EditorialRepository): EventEditorSource =
            EventEditorApi(repository.client ?: error("Nessun client HTTP"), repository.token) { repository.selectedCity }
    }
}
