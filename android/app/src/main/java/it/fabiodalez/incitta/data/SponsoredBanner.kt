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
) {
    fun validAt(now: Instant = Instant.now()): Boolean =
        runCatching { Instant.parse(expiresAt).isAfter(now) }.getOrDefault(false)
}
