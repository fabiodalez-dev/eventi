package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class NotificationPreferences(
    val delivery: String = "auto",
    val reminders: Boolean = true,
    @SerialName("sold_out") val soldOut: Boolean = true,
    @SerialName("venue_digest") val venueDigest: Boolean = true,
    @SerialName("daily_digest") val dailyDigest: Boolean = false,
    @SerialName("daily_digest_time") val dailyDigestTime: String? = null,
    @SerialName("push_available") val pushAvailable: Boolean = false,
)

@Serializable
data class NotificationInterest(val id: Long, val name: String, val selected: Boolean = false)

@Serializable
data class NotificationInterests(
    val categories: List<NotificationInterest> = emptyList(),
    val venues: List<NotificationInterest> = emptyList(),
)

@Serializable
data class InterestSelection(val categories: List<Long>, val venues: List<Long>)

@Serializable
data class InboxContent(val title: String = "", val body: String = "", val url: String = "")

@Serializable
data class InboxNotification(val id: String, val data: InboxContent, val read: Boolean = false)

@Serializable
data class PushDeviceBody(
    @SerialName("push_token") val pushToken: String,
    @SerialName("installation_id") val installationId: String,
    val platform: String,
    @SerialName("app_version") val appVersion: String = it.fabiodalez.incitta.BuildConfig.VERSION_NAME,
    val locale: String = "it",
)
