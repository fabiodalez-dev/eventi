package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class EventWeatherTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun acceptsHourlyForecast() {
        val data = json.decodeFromString<EventWeather>("""{"available":true,"temperature_at_start":0.0,"temperature_estimated":true,"start_time":"18:30","temperature_min":-2,"temperature_max":5}""")
        assertEquals(0.0, data.temperatureAtStart!!, 0.0)
        assertTrue(data.temperatureEstimated)
        assertEquals("18:30", data.startTime)
        assertEquals(-2.0, data.minimum!!, 0.0)
    }

    @Test fun acceptsOlderDailyOnlyResponse() {
        val data = json.decodeFromString<EventWeather>("""{"available":true,"temperature_min":15,"temperature_max":25}""")
        assertNull(data.temperatureAtStart)
        assertFalse(data.temperatureEstimated)
        assertEquals(25.0, data.maximum!!, 0.0)
    }
}
