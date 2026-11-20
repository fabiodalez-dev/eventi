package it.fabiodalez.incitta.data

import java.time.Instant
import okio.Buffer
import org.junit.Assert.*
import org.junit.Test
import kotlinx.serialization.json.Json

class SponsoredBannerTest {
    private val banner = SponsoredBanner(1, "concerto", "Concerto", "Oggi · 21:00", "Teatro", advertiser = "Teatro", expiresAt = "2026-09-10T22:00:00+00:00", metricToken = "token")
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun expiresExactlyAtLocalMidnightAndRejectsInvalidDates() {
        assertTrue(banner.validAt(Instant.parse("2026-09-10T21:59:59Z")))
        assertFalse(banner.validAt(Instant.parse("2026-09-10T22:00:00Z")))
        assertFalse(banner.copy(expiresAt = "invalid").validAt())
    }

    @Test fun acceptsEmptyInventoryAndNullablePoster() {
        assertNull(json.decodeFromString<ApiEnvelope<SponsoredBanner?>>("""{"data":null}""").data)
        val decoded = json.decodeFromString<ApiEnvelope<SponsoredBanner?>>("""{"data":{"id":1,"event_slug":"concerto","title":"Concerto","when":"Oggi","place":"Teatro","advertiser":"Teatro","expires_at":"2026-09-10T22:00:00Z","metric_token":"token","image":null}}""")
        assertNull(decoded.data!!.image)
        assertEquals("concerto", decoded.data!!.eventSlug)
    }

    @Test fun readsTheSingleUseTokenAndStillWorksWithAnOlderServer() {
        val fresh = json.decodeFromString<ApiEnvelope<SponsoredBanner?>>("""{"data":{"id":7,"event_slug":"concerto","title":"Concerto","when":"Oggi","place":"Teatro","advertiser":"Teatro","expires_at":"2026-09-10T22:00:00Z","metric_token":"daily","impression_token":"v1.7.banner.29000000.0123456789abcdef.sig"}}""").data!!
        assertEquals("v1.7.banner.29000000.0123456789abcdef.sig", fresh.impressionToken)
        assertEquals("daily", fresh.metricToken)
        // Un server di prima non manda il gettone: il banner resta valido e si conta come prima.
        val old = json.decodeFromString<ApiEnvelope<SponsoredBanner?>>("""{"data":{"id":7,"event_slug":"concerto","title":"Concerto","when":"Oggi","place":"Teatro","advertiser":"Teatro","expires_at":"2026-09-10T22:00:00Z","metric_token":"daily"}}""").data!!
        assertNull(old.impressionToken)
    }

    @Test fun countsOneViewPerTokenAndOnePerCampaignWithoutToken() {
        val first = banner.copy(impressionToken = "v1.1.banner.1.aaaaaaaaaaaaaaaa.x")
        val second = banner.copy(impressionToken = "v1.1.banner.1.bbbbbbbbbbbbbbbb.x")
        assertNotEquals(first.impressionKey, second.impressionKey)
        assertEquals(first.impressionKey, first.copy(title = "Altro titolo").impressionKey)
        assertEquals("campaign:1", banner.impressionKey)
    }

    @Test fun sendsBothTokensWithTheMetric() {
        val client = ApiClient("android-installation-0001")
        val request = client.sponsorshipMetricRequest(banner.copy(impressionToken = "v1.1.banner.1.aaaaaaaaaaaaaaaa.x"), click = true, page = "home", clickId = "6f1c1a52-0a4b-4c4e-9a43-1d1f0c5b7f00")
        assertTrue(request.url.toString().endsWith("reports/sponsorships/1/metrics/clicks"))
        assertEquals("POST", request.method)
        assertEquals("token", request.header("X-Metric-Token"))
        assertEquals("v1.1.banner.1.aaaaaaaaaaaaaaaa.x", request.header("X-Impression-Token"))
        assertEquals("android-installation-0001", request.header("X-Installation-ID"))
        val body = Buffer().also { request.body!!.writeTo(it) }.readUtf8()
        assertTrue(body.contains("\"click_id\":\"6f1c1a52-0a4b-4c4e-9a43-1d1f0c5b7f00\""))
        assertTrue(body.contains("\"placement\":\"banner\""))
    }

    @Test fun leavesTheNewHeaderOutWhenTheServerSentNoToken() {
        val request = ApiClient("android-installation-0001").sponsorshipMetricRequest(banner, click = false, page = "home")
        assertTrue(request.url.toString().endsWith("reports/sponsorships/1/metrics/impressions"))
        assertEquals("token", request.header("X-Metric-Token"))
        assertNull(request.header("X-Impression-Token"))
        val body = Buffer().also { request.body!!.writeTo(it) }.readUtf8()
        assertFalse(body.contains("click_id"))
    }
}
