package it.fabiodalez.incitta.data

import java.time.Instant
import org.junit.Assert.*
import org.junit.Test
import kotlinx.serialization.json.Json

class SponsoredBannerTest {
    private val banner = SponsoredBanner(1, "concerto", "Concerto", "Oggi · 21:00", "Teatro", advertiser = "Teatro", expiresAt = "2026-09-10T22:00:00+00:00", metricToken = "token")

    @Test fun expiresExactlyAtLocalMidnightAndRejectsInvalidDates() {
        assertTrue(banner.validAt(Instant.parse("2026-09-10T21:59:59Z")))
        assertFalse(banner.validAt(Instant.parse("2026-09-10T22:00:00Z")))
        assertFalse(banner.copy(expiresAt = "invalid").validAt())
    }

    @Test fun acceptsEmptyInventoryAndNullablePoster() {
        val json = Json { ignoreUnknownKeys = true }
        assertNull(json.decodeFromString<ApiEnvelope<SponsoredBanner?>>("""{"data":null}""").data)
        val decoded = json.decodeFromString<ApiEnvelope<SponsoredBanner?>>("""{"data":{"id":1,"event_slug":"concerto","title":"Concerto","when":"Oggi","place":"Teatro","advertiser":"Teatro","expires_at":"2026-09-10T22:00:00Z","metric_token":"token","image":null}}""")
        assertNull(decoded.data!!.image)
        assertEquals("concerto", decoded.data!!.eventSlug)
    }
}
