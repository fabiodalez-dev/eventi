package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.graphics.asAndroidBitmap
import it.fabiodalez.incitta.data.SponsoredBanner
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.SponsoredEventBanner
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import java.time.Instant

class SponsoredBannerUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun showsDisclosureAndAutofilledDataAndOpensOnTap() {
        var clicked = 0
        var impressions = 0
        compose.runOnIdle {
            compose.activity.setContent { InCittaTheme {
                SponsoredEventBanner(SponsoredBanner(1, "concerto", "Una serata di musica e racconti tra le piazze e i portici della città", "Oggi · 21:00", "Associazione culturale Sagunto", price = "Gratis", advertiser = "Sagunto", expiresAt = Instant.now().plusSeconds(60).toString(), metricToken = "test"), { impressions++ }, { clicked++ })
            } }
        }
        compose.onNodeWithText("AD").assertIsDisplayed()
        compose.onNodeWithText("Oggi · 21:00 · Gratis").assertIsDisplayed()
        compose.onNodeWithText("Associazione culturale Sagunto").assertIsDisplayed()
        java.io.File(compose.activity.externalCacheDir, "sponsored-banner-test.png").outputStream().use {
            compose.onRoot().captureToImage().asAndroidBitmap().compress(android.graphics.Bitmap.CompressFormat.PNG, 100, it)
        }
        compose.mainClock.advanceTimeBy(1_100)
        compose.onNodeWithText("AD").performClick()
        compose.runOnIdle { assertEquals(1, clicked); assertEquals(1, impressions) }
    }

    /* La regola del sito: conta solo dopo un secondo con metà banner nello schermo. */
    @Test fun countsTheViewOnlyAfterOneSecondOnScreen() {
        var impressions = 0
        compose.mainClock.autoAdvance = false
        compose.runOnIdle {
            compose.activity.setContent { InCittaTheme {
                SponsoredEventBanner(SponsoredBanner(2, "concerto", "Concerto", "Oggi · 21:00", "Teatro", advertiser = "Teatro", expiresAt = Instant.now().plusSeconds(60).toString(), metricToken = "test", impressionToken = "v1.2.banner.1.aaaaaaaaaaaaaaaa.x"), { impressions++ }, {})
            } }
        }
        compose.mainClock.advanceTimeBy(500)
        compose.runOnIdle { assertEquals(0, impressions) }
        compose.mainClock.advanceTimeBy(700)
        compose.runOnIdle { assertEquals(1, impressions) }
        compose.mainClock.advanceTimeBy(5_000)
        compose.runOnIdle { assertEquals(1, impressions) }
        compose.mainClock.autoAdvance = true
    }
}
