package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString

/** Una raccolta di date, come la riporta `GET /v1/me/collections`. */
@Serializable
data class EventCollection(
    val id: Long,
    val name: String,
    val description: String? = null,
    @SerialName("is_public") val isPublic: Boolean = false,
    @SerialName("public_url") val publicUrl: String? = null,
    @SerialName("items_count") val itemsCount: Int = 0,
    @SerialName("created_at") val createdAt: String? = null,
)

/** Una data dentro una raccolta: non è un `Occurrence` completo, solo quanto serve a elencarla e ad aprirla. */
@Serializable
data class CollectionItem(
    @SerialName("occurrence_id") val occurrenceId: Long,
    val title: String? = null,
    @SerialName("starts_at") val startsAt: String,
    val venue: String? = null,
    val url: String? = null,
    val note: String? = null,
)

/**
 * Il dettaglio di una raccolta. La stessa forma serve due risposte: quella
 * della proprietaria (`/v1/me/collections/{id}`, con id e link pubblico) e
 * quella pubblica (`/v1/collections/{token}`, con il nome di chi la condivide
 * e senza id).
 */
@Serializable
data class CollectionDetail(
    val id: Long? = null,
    val name: String,
    val description: String? = null,
    @SerialName("is_public") val isPublic: Boolean = false,
    @SerialName("public_url") val publicUrl: String? = null,
    @SerialName("items_count") val itemsCount: Int = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val owner: String? = null,
    val items: List<CollectionItem> = emptyList(),
) {
    fun summary(): EventCollection? = id?.let { EventCollection(it, name, description, isPublic, publicUrl, itemsCount, createdAt) }
}

@Serializable
internal data class CollectionBody(
    val name: String,
    val description: String? = null,
    @SerialName("is_public") val isPublic: Boolean = false,
    @SerialName("occurrence_id") val occurrenceId: Long? = null,
)

@Serializable
internal data class CollectionItemBody(
    @SerialName("occurrence_id") val occurrenceId: Long,
    val note: String? = null,
)

internal class CollectionsApi(private val api: ApiClient, private val token: String?) {
    private fun auth(): String = requireNotNull(token)

    suspend fun list(): List<EventCollection> =
        api.get<ApiEnvelope<List<EventCollection>>>("me/collections?city=$CITY", auth()).data

    suspend fun create(name: String, isPublic: Boolean, occurrenceId: Long? = null): EventCollection =
        api.post<ApiEnvelope<EventCollection>, CollectionBody>("me/collections?city=$CITY", CollectionBody(name.trim(), null, isPublic, occurrenceId), auth(), idempotent = true).data

    suspend fun show(id: Long): CollectionDetail =
        api.get<ApiEnvelope<CollectionDetail>>("me/collections/$id?city=$CITY", auth()).data

    suspend fun update(id: Long, name: String, description: String?, isPublic: Boolean): EventCollection =
        api.execute<ApiEnvelope<EventCollection>>(
            "me/collections/$id?city=$CITY",
            "PATCH",
            api.json.encodeToString(CollectionBody(name.trim(), description?.trim()?.ifBlank { null }, isPublic)),
            auth(),
        ).data

    suspend fun delete(id: Long) = api.send("me/collections/$id", "DELETE", null, auth())

    suspend fun addItem(id: Long, occurrenceId: Long, note: String? = null): EventCollection =
        api.post<ApiEnvelope<EventCollection>, CollectionItemBody>("me/collections/$id/items?city=$CITY", CollectionItemBody(occurrenceId, note?.trim()?.ifBlank { null }), auth(), idempotent = true).data

    suspend fun removeItem(id: Long, occurrenceId: Long) = api.send("me/collections/$id/items/$occurrenceId", "DELETE", null, auth())

    /** La raccolta condivisa, senza account: 404 se chi l'ha creata l'ha richiusa. */
    suspend fun shared(token: String): CollectionDetail {
        require(CollectionsRoutes.validToken(token))
        return api.get<ApiEnvelope<CollectionDetail>>("collections/$token").data
    }

    private companion object { const val CITY = "padova" }
}

/** I percorsi delle raccolte condivise: `/raccolte/{token}`, con il gettone di 24 caratteri del server. */
internal object CollectionsRoutes {
    private val TOKEN = Regex("[A-Za-z0-9]{24}")

    fun validToken(token: String): Boolean = TOKEN.matches(token)

    /** Il gettone da un percorso già diviso in segmenti, oppure null se il percorso non è una raccolta. */
    fun tokenFrom(segments: List<String>): String? =
        segments.takeIf { it.size == 2 && it[0] == "raccolte" }?.get(1)?.takeIf(::validToken)
}
