package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.putJsonArray
import kotlinx.serialization.json.add

/** Le immagini del locale che hanno un posto fisso (una sola per tipo). */
internal enum class VenueImageSlot(val segment: String) { Logo("logo"), Cover("cover") }

/**
 * Caricamento e rimozione delle immagini della gestione, campo multipart
 * `image`. Le schermate che le usano (locandina in P1, profilo del locale in
 * P4) arrivano con i loro pacchetti; qui c'è solo il client. Le risposte
 * restano JSON grezzo finché quei pacchetti non ne fissano la forma.
 */
internal class ManagementMediaApi(private val api: ApiClient, private val token: String) {
    suspend fun uploadPoster(subject: ManagementSubject, eventId: Long, image: PreparedImage): JsonObject =
        upload("${subject.apiPath}/events/$eventId/poster", image)

    suspend fun deletePoster(subject: ManagementSubject, eventId: Long) =
        api.send("${subject.apiPath}/events/$eventId/poster", "DELETE", token)

    /** `GET V/media`: logo, copertina e galleria con gli `id` delle foto (servono per toglierle e riordinarle). */
    suspend fun venueMedia(venueId: Long): JsonObject = api.get("${venuePath(venueId)}/media", token)

    suspend fun uploadVenueImage(venueId: Long, slot: VenueImageSlot, image: PreparedImage): JsonObject =
        upload("${venuePath(venueId)}/media/${slot.segment}", image)

    suspend fun deleteVenueImage(venueId: Long, slot: VenueImageSlot) =
        api.send("${venuePath(venueId)}/media/${slot.segment}", "DELETE", token)

    suspend fun addGalleryImage(venueId: Long, image: PreparedImage): JsonObject =
        upload("${venuePath(venueId)}/media/gallery", image)

    suspend fun deleteGalleryImage(venueId: Long, mediaId: Long) =
        api.send("${venuePath(venueId)}/media/gallery/$mediaId", "DELETE", token)

    suspend fun reorderGallery(venueId: Long, ids: List<Long>) =
        api.send("${venuePath(venueId)}/media/gallery/order", "PUT", token, galleryOrderBody(ids).toString())

    private suspend fun upload(path: String, image: PreparedImage): JsonObject =
        api.upload(path, token, IMAGE_FIELD, image.bytes, image.mime, image.fileName)

    private fun venuePath(id: Long) = "management/${SubjectKind.Venue.segment}/$id"

    companion object {
        const val IMAGE_FIELD = "image"
    }
}

/** `PUT V/media/gallery/order`: gli id nell'ordine nuovo. */
internal fun galleryOrderBody(ids: List<Long>): JsonObject = buildJsonObject { putJsonArray("ids") { ids.forEach { add(it) } } }
