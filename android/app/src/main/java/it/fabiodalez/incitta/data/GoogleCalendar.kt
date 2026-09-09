package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

@Serializable
internal data class GoogleCalendarState(
    val configured: Boolean = false,
    val connected: Boolean = false,
    @SerialName("synced_at") val syncedAt: String? = null,
    @SerialName("event_count") val eventCount: Int = 0,
    @SerialName("error_code") val errorCode: String? = null,
    val selection: JsonObject? = null,
)

@Serializable
internal data class GoogleCalendarSelection(
    val categories: List<String>,
    val days: Int,
    val venue: String?,
    val free: Boolean,
)

@Serializable
internal data class GoogleCalendarManagementLink(val url: String)
