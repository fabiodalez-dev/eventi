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
        compose.onNodeWithText("AD").performClick()
        compose.runOnIdle { assertEquals(1, clicked); assertEquals(1, impressions) }
    }
}
