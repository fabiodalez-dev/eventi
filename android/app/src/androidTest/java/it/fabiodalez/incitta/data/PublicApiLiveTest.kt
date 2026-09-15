package it.fabiodalez.incitta.data

import androidx.test.platform.app.InstrumentationRegistry
import kotlinx.coroutines.runBlocking
import org.junit.Assume.assumeTrue
import org.junit.Test

/** Opt-in, read-only probe using Android's actual TLS and HTTP stack. */
class PublicApiLiveTest {
    @Test fun publicScreensReachAndDecodeTheReleaseServer() = runBlocking {
        assumeTrue(InstrumentationRegistry.getArguments().getString("liveReadOnly") == "true")
        val api = ApiClient("release-network-verification")
        val events = api.get<ApiEnvelope<List<Occurrence>>>("events?city=padova").data
        api.get<ApiEnvelope<List<Venue>>>("venues?city=padova")
        api.get<ApiEnvelope<List<MapMarker>>>("map/occurrences?city=padova&preset=today")
        api.get<ApiEnvelope<SearchResults>>("search?city=padova&q=teatro")
        events.firstOrNull()?.let { api.get<ApiEnvelope<EventDetail>>("events/${it.eventSlug}") }
        // Events uses cursor pagination, unlike Home and Map. Exercise every page.
        var cursor: String? = null
        val seen = mutableSetOf<String>()
        do {
            val page = api.get<ApiEnvelope<List<Occurrence>>>("events?city=padova&limit=50&q=" + (cursor?.let { "&cursor=${java.net.URLEncoder.encode(it, "UTF-8")}" } ?: ""))
            cursor = page.meta?.nextCursor
            org.junit.Assert.assertTrue("Repeated cursor", cursor == null || seen.add(cursor))
        } while (cursor != null)
        Unit
    }
}
