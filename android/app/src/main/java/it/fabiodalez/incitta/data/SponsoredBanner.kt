package it.fabiodalez.incitta.data

import java.time.Instant
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class SponsoredBanner(
    val id: Long,
    @SerialName("event_slug") val eventSlug: String,
    val title: String,
    @SerialName("when") val dateLabel: String,
    val place: String,
    val category: String = "",
    val price: String = "",
    val advertiser: String,
    val image: String? = null,
    @SerialName("expires_at") val expiresAt: String,
    @SerialName("metric_token") val metricToken: String,
    @SerialName("occurrence_id") val occurrenceId: Long? = null,
    /* Il gettone usa e getta di questa risposta: si rimanda in
       `X-Impression-Token` con la visualizzazione e con il clic, e conta una
       volta sola. Manca solo con un server più vecchio dell'app. */
    @SerialName("impression_token") val impressionToken: String? = null,
) {
    fun validAt(now: Instant = Instant.now()): Boolean =
        runCatching { Instant.parse(expiresAt).isAfter(now) }.getOrDefault(false)

    /**
     * La chiave con cui l'app evita di mandare due volte la stessa
     * visualizzazione: il gettone, cioè una per risposta del server. Con un
     * server che non lo manda resta il comportamento di prima, una per
     * campagna.
     */
    val impressionKey: String get() = impressionToken?.let { "token:$it" } ?: "campaign:$id"
}
