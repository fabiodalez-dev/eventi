package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class EventWeather(
    val available: Boolean = false, val message: String? = null, val date: String? = null,
    val icon: String = "cloud", val description: String = "",
    @SerialName("temperature_min") val minimum: Double? = null,
    @SerialName("temperature_max") val maximum: Double? = null,
    @SerialName("rain_probability") val rain: Double? = null,
    @SerialName("wind_speed") val wind: Double? = null,
    val indicative: Boolean = false,
)
