package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.ApiPayloadException
import java.net.URLEncoder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * P13 — registrare o rivendicare un locale, registrare un'organizzazione e
 * «Le mie richieste». Le forme sono quelle di `ApplicationController` e di
 * `GET /me/venue-applications`; ogni campo ha un valore predefinito, così un
 * server più nuovo non rompe la lettura.
 */

/** Una richiesta di «Le mie richieste». `state`: `awaiting`, `pending`, `approved`, `rejected`. */
@Serializable
internal data class MyApplication(
    val id: Long = 0,
    /* `venue`, `claim` o `organizer`. */
    val kind: String = "",
    @SerialName("kind_label") val kindLabel: String = "",
    val name: String = "",
    val type: String? = null,
    @SerialName("type_label") val typeLabel: String? = null,
    val address: String? = null,
    val state: String = "",
    @SerialName("state_label") val stateLabel: String = "",
    val next: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("reviewed_at") val reviewedAt: String? = null,
    @SerialName("can_confirm") val canConfirm: Boolean = false,
    @SerialName("panel_url") val panelUrl: String? = null,
    @SerialName("public_url") val publicUrl: String? = null,
    @SerialName("venue_slug") val venueSlug: String? = null,
    @SerialName("organizer_slug") val organizerSlug: String? = null,
    /* Il locale o l'organizzazione che «Apri il pannello» apre nella gestione: solo se l'account lo gestisce. */
    val subject: ApplicationSubject? = null,
    /* Dove porta «Scrivi una nuova richiesta»: solo per le respinte. */
    val retry: ApplicationRetry? = null,
) {
    /** Tipo e indirizzo sotto il nome, come la card del sito; niente per un'organizzazione. */
    val summary: String? get() = listOfNotNull(typeLabel?.takeIf { it.isNotBlank() }, address?.takeIf { it.isNotBlank() })
        .joinToString(" · ").ifBlank { null }
}

@Serializable
internal data class ApplicationSubject(val type: String = "", val id: Long = 0) {
    /** La chiave di `ManagementSubject.key`: `venue:12`. */
    val key: String get() = "$type:$id"
}

@Serializable
internal data class ApplicationRetry(
    /* `venue`, `claim` o `organizer`. */
    val form: String = "venue",
    @SerialName("venue_slug") val venueSlug: String? = null,
)

@Serializable
internal data class MyApplicationsMeta(@SerialName("next_page") val nextPage: Int? = null)

@Serializable
internal data class MyApplicationsPage(val data: List<MyApplication> = emptyList(), val meta: MyApplicationsMeta = MyApplicationsMeta())

/** `GET /me/venue-applications/options`. */
@Serializable
internal data class ApplicationOptions(
    val types: List<ApplicationChoice> = emptyList(),
    val municipality: String? = null,
    /* `null` per un ospite (Q2): l'email la scrive nel modulo. */
    @SerialName("account_email") val accountEmail: String? = null,
    val limits: ApplicationLimits = ApplicationLimits(),
)

@Serializable
internal data class ApplicationChoice(val value: String = "", val label: String = "")

@Serializable
internal data class ApplicationLimits(
    val pending: Int = 3,
    @SerialName("image_max_kb") val imageMaxKb: Int = 5120,
    @SerialName("image_min_width") val imageMinWidth: Int = 400,
    @SerialName("image_min_height") val imageMinHeight: Int = 300,
    @SerialName("claim_message_min") val claimMessageMin: Int = 10,
)

/** Un locale di «Il tuo locale è già nel catalogo?». */
@Serializable
internal data class CatalogueVenue(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val address: String? = null,
    val municipality: String? = null,
    val claimable: Boolean = false,
) {
    val place: String? get() = listOfNotNull(address?.takeIf { it.isNotBlank() }, municipality?.takeIf { it.isNotBlank() }).joinToString(", ").ifBlank { null }
}

/** `GET /venues/{slug}/claim`: il locale che si chiede di gestire. */
@Serializable
internal data class ClaimTarget(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    @SerialName("type_label") val typeLabel: String? = null,
    val address: String? = null,
    val municipality: String? = null,
) {
    val summary: String get() = listOfNotNull(typeLabel, address, municipality).filter { it.isNotBlank() }.joinToString(" · ")
}

/** La risposta all'invio: `state` è `queued`, `verify` o `organizer`. */
@Serializable
internal data class ApplicationSent(
    val state: String = "",
    val message: String = "",
    val application: MyApplication = MyApplication(),
)

@Serializable
internal data class ApplicationNotice(val message: String = "")

/** I campi di «Registra il tuo locale», con i nomi del server. */
@Serializable
internal data class VenueApplicationDraft(
    val venueName: String = "",
    val type: String = "",
    val address: String = "",
    val postalCode: String = "",
    val municipality: String = "",
    val website: String = "",
    val publicEmail: String = "",
    val publicPhone: String = "",
    val instagram: String = "",
    val facebook: String = "",
    val capacity: String = "",
    val description: String = "",
    val contactName: String = "",
    val contactRole: String = "",
    val contactPhone: String = "",
    val message: String = "",
) {
    /** Solo i campi scritti: uno vuoto non si manda, come un campo lasciato vuoto nel modulo del sito. */
    fun fields(): List<Pair<String, String>> = listOf(
        "venue_name" to venueName, "type" to type, "address" to address, "postal_code" to postalCode,
        "municipality" to municipality, "website" to website, "public_email" to publicEmail, "public_phone" to publicPhone,
        "instagram" to instagram, "facebook" to facebook, "capacity" to capacity, "description" to description,
        "contact_name" to contactName, "contact_role" to contactRole, "contact_phone" to contactPhone, "message" to message,
    ).map { (key, value) -> key to value.trim() }.filter { it.second.isNotEmpty() }
}

/** I campi di «Questo locale è mio». */
@Serializable
internal data class ClaimDraft(
    val contactName: String = "",
    val contactRole: String = "",
    val contactPhone: String = "",
    val message: String = "",
) {
    fun fields(): List<Pair<String, String>> = listOf(
        "contact_name" to contactName, "contact_role" to contactRole, "contact_phone" to contactPhone, "message" to message,
    ).map { (key, value) -> key to value.trim() }.filter { it.second.isNotEmpty() }
}

/** I campi di «Registra la tua organizzazione». */
@Serializable
internal data class OrganizerApplicationDraft(
    val name: String = "",
    val website: String = "",
    val message: String = "",
    val contactName: String = "",
    val contactRole: String = "",
    val contactPhone: String = "",
) {
    fun fields(): List<Pair<String, String>> = listOf(
        "venue_name" to name, "website" to website, "message" to message,
        "contact_name" to contactName, "contact_role" to contactRole, "contact_phone" to contactPhone,
    ).map { (key, value) -> key to value.trim() }.filter { it.second.isNotEmpty() }
}

/** Il corpo JSON di un modulo: i campi scritti, la capienza come numero. */
internal fun applicationBody(fields: List<Pair<String, String>>): JsonObject = buildJsonObject {
    fields.forEach { (key, value) ->
        val number = if (key == "capacity") value.toIntOrNull() else null
        if (number != null) put(key, number) else put(key, value)
    }
}

/** Le chiamate di P13. È un'interfaccia perché i ViewModel si provano con un finto, senza rete. */
internal interface ManagementApplicationsSource {
    suspend fun applications(page: Int): MyApplicationsPage
    suspend fun options(): ApplicationOptions
    suspend fun lookup(query: String): List<CatalogueVenue>
    suspend fun claimTarget(slug: String): ClaimTarget
    /* `idempotencyKey`: la chiave della bozza (`ApplicationDrafts.sendKey`); lo stesso invio ripetuto non crea un doppione. */
    suspend fun submitVenue(draft: VenueApplicationDraft, image: PreparedImage?, idempotencyKey: String? = null): ApplicationSent
    suspend fun claim(slug: String, draft: ClaimDraft, idempotencyKey: String? = null): ApplicationSent
    suspend fun submitOrganizer(draft: OrganizerApplicationDraft, idempotencyKey: String? = null): ApplicationSent
    suspend fun confirm(id: Long): String
    suspend fun discard(id: Long): String
}

/**
 * Con `token` nullo è l'ospite (Q2): valgono solo le letture del modulo
 * (`options`, `lookup`, `claimTarget`), pubbliche come le pagine del sito.
 */
internal class ManagementApplicationsApi(private val api: ApiClient, private val token: String?) : ManagementApplicationsSource {
    override suspend fun applications(page: Int): MyApplicationsPage = api.get("me/venue-applications?page=$page", token)

    override suspend fun options(): ApplicationOptions =
        api.get<ApiEnvelope<ApplicationOptions>>("me/venue-applications/options", token).data

    override suspend fun lookup(query: String): List<CatalogueVenue> =
        api.get<ApiEnvelope<List<CatalogueVenue>>>("venue-applications/lookup?q=" + URLEncoder.encode(query.trim(), "UTF-8"), token).data

    override suspend fun claimTarget(slug: String): ClaimTarget =
        api.get<ApiEnvelope<ClaimTarget>>("venues/${encodeSlug(slug)}/claim", token).data

    /* Con l'immagine il modulo parte in multipart, come dal sito; senza, in JSON. */
    override suspend fun submitVenue(draft: VenueApplicationDraft, image: PreparedImage?, idempotencyKey: String?): ApplicationSent {
        if (image == null) return sendForm("me/venue-applications", applicationBody(draft.fields()), idempotencyKey)
        val payload = api.upload("me/venue-applications", signedIn(), IMAGE_FIELD, image.bytes, image.mime, image.fileName, draft.fields(), idempotencyKey)
        return try {
            api.json.decodeFromJsonElement(ApiEnvelope.serializer(ApplicationSent.serializer()), payload).data
        } catch (error: SerializationException) {
            throw ApiPayloadException(error)
        } catch (error: IllegalArgumentException) {
            throw ApiPayloadException(error)
        }
    }

    override suspend fun claim(slug: String, draft: ClaimDraft, idempotencyKey: String?): ApplicationSent =
        sendForm("venues/${encodeSlug(slug)}/claim", applicationBody(draft.fields()), idempotencyKey)

    override suspend fun submitOrganizer(draft: OrganizerApplicationDraft, idempotencyKey: String?): ApplicationSent =
        sendForm("me/organizer-applications", applicationBody(draft.fields()), idempotencyKey)

    /* Un modulo in JSON, con la chiave dell'invio se c'è: senza, la richiesta è quella di prima. */
    private suspend fun sendForm(path: String, body: JsonObject, idempotencyKey: String?): ApplicationSent = withContext(Dispatchers.IO) {
        val payload = api.call(formRequest(path, body, idempotencyKey))
        try {
            api.json.decodeFromString(ApiEnvelope.serializer(ApplicationSent.serializer()), payload).data
        } catch (error: SerializationException) {
            throw ApiPayloadException(error)
        }
    }

    internal fun formRequest(path: String, body: JsonObject, idempotencyKey: String?): okhttp3.Request {
        val request = api.request(path, "POST", api.json.encodeToString(JsonObject.serializer(), body), token, false)
        return if (idempotencyKey == null) request else request.newBuilder().header(IDEMPOTENCY_HEADER, idempotencyKey).build()
    }

    override suspend fun confirm(id: Long): String =
        api.post<ApiEnvelope<ApplicationNotice>, JsonObject>("me/venue-applications/$id/confirm", buildJsonObject {}, token).data.message

    override suspend fun discard(id: Long): String =
        api.delete<ApiEnvelope<ApplicationNotice>>("me/venue-applications/$id", signedIn()).data.message

    private fun encodeSlug(slug: String) = URLEncoder.encode(slug, "UTF-8")

    /* Le scritture vogliono il token: un ospite non arriva mai fin qui, ma se succede il server non va nemmeno chiamato. */
    private fun signedIn(): String = token ?: throw IllegalStateException("Accedi per mandare la richiesta.")

    companion object {
        const val IMAGE_FIELD = "image"
        const val IDEMPOTENCY_HEADER = "Idempotency-Key"
    }
}
