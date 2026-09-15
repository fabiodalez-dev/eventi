package it.fabiodalez.incitta.data

import kotlinx.serialization.Serializable
import kotlinx.serialization.SerialName

@Serializable
data class RememberedPosition(
    val lat: Double,
    val lng: Double,
    @SerialName("saved_at") val savedAt: Long,
    @SerialName("expires_at") val expiresAt: Long,
) {
    fun valid(now: Long = System.currentTimeMillis() / 1000): Boolean =
        lat.isFinite() && lng.isFinite() && lat in -90.0..90.0 && lng in -180.0..180.0 && savedAt <= now && expiresAt > now
}

@Serializable
internal data class RememberPositionRequest(val lat: Double, val lng: Double, val remember: Boolean = true, @SerialName("observed_at") val observedAt: Long? = null)

@Serializable
internal data class NearbyHome(val sections: Map<String, List<Occurrence>> = emptyMap())
