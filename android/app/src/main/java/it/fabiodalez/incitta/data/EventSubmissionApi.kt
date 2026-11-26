package it.fabiodalez.incitta.data

import java.net.URI
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.SerializationException
import kotlinx.serialization.encodeToString

/*
 * «Proponi un evento» nell'app (Q1): lo stesso modulo di `/proponi-evento`,
 * mandato a `POST /v1/submissions`. La proposta non si pubblica: nasce in
 * attesa e la valuta la redazione, come sul sito.
 */

/** Un locale della città fra cui scegliere: basta quello che serve all'elenco. */
@Serializable
internal data class SubmissionVenue(
    val id: Long = 0,
    val name: String = "",
    val municipality: String? = null,
    val zone: String? = null,
)

/** Il nome e il fuso della città, per la data della proposta. */
@Serializable
internal data class SubmissionCity(
    val slug: String = "",
    val name: String = "",
    val timezone: String = "Europe/Rome",
)

/** Il corpo di `POST /v1/submissions`: i campi vuoti non si mandano. */
@Serializable
internal data class EventSubmissionBody(
    val title: String = "",
    @SerialName("starts_at_hint") val startsAtHint: String? = null,
    @SerialName("venue_id") val venueId: Long? = null,
    @SerialName("venue_hint") val venueHint: String? = null,
    @SerialName("raw_text") val rawText: String? = null,
    @SerialName("contact_name") val contactName: String? = null,
    @SerialName("contact_email") val contactEmail: String = "",
)

/** La ricevuta del server: la proposta è in attesa (`pending`), con il messaggio da mostrare. */
@Serializable
internal data class SubmissionReceipt(
    val id: Long = 0,
    val status: String = "",
    val message: String = "",
    @SerialName("created_at") val createdAt: String? = null,
)

/** Le tre chiamate della schermata: un'interfaccia sola, così i test usano un server finto. */
internal interface EventSubmissionSource {
    suspend fun city(): SubmissionCity
    suspend fun venues(query: String): List<SubmissionVenue>
    suspend fun submit(body: EventSubmissionBody, idempotencyKey: String): SubmissionReceipt
}

/**
 * Le chiamate vere. Nessun token: la proposta è pubblica e la riconosce
 * l'installazione (`X-Installation-ID`), che resta la stessa anche se nel
 * frattempo si entra o si esce dall'account. La chiave `Idempotency-Key` la
 * sceglie il ViewModel, una per proposta: un nuovo tentativo con la stessa
 * chiave riceve la risposta del primo, senza creare un doppione.
 */
internal class EventSubmissionApi(private val api: ApiClient, private val citySlug: String = SUBMISSION_CITY) : EventSubmissionSource {
    override suspend fun city(): SubmissionCity =
        api.get<ApiEnvelope<SubmissionCity>>("cities/${citySlug.urlEncodedSegment()}").data

    /** I locali approvati della città in ordine di nome, filtrati per nome, comune o quartiere. */
    override suspend fun venues(query: String): List<SubmissionVenue> {
        val term = query.trim().take(MAX_VENUE_QUERY)
        val search = if (term.isEmpty()) "" else "&q=" + java.net.URLEncoder.encode(term, Charsets.UTF_8)
        return api.get<ApiEnvelope<List<SubmissionVenue>>>("venues?city=${citySlug.urlEncodedSegment()}&limit=$VENUE_PAGE$search").data
    }

    override suspend fun submit(body: EventSubmissionBody, idempotencyKey: String): SubmissionReceipt = withContext(Dispatchers.IO) {
        val payload = api.call(submissionRequest(body, idempotencyKey))
        try {
            api.json.decodeFromString<ApiEnvelope<SubmissionReceipt>>(payload).data
        } catch (error: SerializationException) {
            throw ApiPayloadException(error)
        }
    }

    /** La richiesta, con le intestazioni comuni dell'API più la chiave di questa proposta. */
    internal fun submissionRequest(body: EventSubmissionBody, idempotencyKey: String): okhttp3.Request =
        api.request("submissions?city=${citySlug.urlEncodedSegment()}", "POST", api.json.encodeToString(body), null, false)
            .newBuilder().header("Idempotency-Key", idempotencyKey).build()

    private fun String.urlEncodedSegment(): String = java.net.URLEncoder.encode(this, Charsets.UTF_8)

    private companion object {
        const val VENUE_PAGE = 50
        const val MAX_VENUE_QUERY = 120
    }
}

/** La città dell'app: oggi una sola, come nel resto delle chiamate. */
internal const val SUBMISSION_CITY = "padova"

/**
 * Vero per l'indirizzo del modulo del sito (`/proponi-evento`), che l'app apre
 * nativo: dal pulsante della home, da un collegamento toccato dentro l'app o
 * da un App Link. Solo con l'origine del sito, come gli altri collegamenti.
 */
internal fun isEventSubmissionLink(url: String, allowedOrigin: String): Boolean = runCatching {
    val uri = URI(url)
    val allowed = URI(allowedOrigin)
    uri.scheme == allowed.scheme && uri.host == allowed.host && uri.port == allowed.port && uri.userInfo == null &&
        uri.path.orEmpty().trimEnd('/') == SUBMISSION_PATH
}.getOrDefault(false)

internal const val SUBMISSION_PATH = "/proponi-evento"
