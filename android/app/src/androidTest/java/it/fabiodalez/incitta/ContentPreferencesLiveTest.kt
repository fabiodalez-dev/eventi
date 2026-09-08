package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Surface
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.unit.dp
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.platform.app.InstrumentationRegistry
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.ui.ContentPreferencesPanel
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.Ink
import it.fabiodalez.incitta.ui.Paper
import org.junit.Assume.assumeTrue
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

/** Opt-in against an isolated QA account. Never uses or changes existing account credentials. */
class ContentPreferencesLiveTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun userCanSelectHideSaveAndResetDynamicCategories() {
        val token = InstrumentationRegistry.getArguments().getString("contentLiveToken")
        assumeTrue(!token.isNullOrBlank())
        var saves = 0
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    Surface(color = Ink, contentColor = Paper) {
                        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(18.dp)) {
                            ContentPreferencesPanel(Session(requireNotNull(token), User(0, "QA", "qa@example.test"), "2099-01-01T00:00:00Z")) { saves++ }
                        }
                    }
                }
            }
        }
        compose.onNodeWithText("I MIEI INTERESSI · COSA VEDERE").performClick()
        compose.waitUntil(20_000) { compose.onAllNodesWithText("Nessuna preferenza").fetchSemanticsNodes().isNotEmpty() }
        compose.onAllNodesWithText("Nessuna preferenza").onFirst().performScrollTo().performClick()
        compose.onNodeWithText("Mi interessa").performClick()
        compose.onAllNodesWithText("Nessuna preferenza").onFirst().performScrollTo().performClick()
        compose.onNodeWithText("Nascondi").performClick()
        compose.onNodeWithText("SALVA I MIEI INTERESSI").performScrollTo().performClick()
        compose.waitUntil(20_000) { saves == 1 }
        compose.onNodeWithText("Interessi salvati. Eventi e mappe aggiornati.").assertExists()
        compose.onNodeWithText("Mi interessa").performScrollTo()
        java.io.File(compose.activity.externalCacheDir, "content-preferences-native.png").outputStream().use {
            compose.onRoot().captureToImage().asAndroidBitmap().compress(android.graphics.Bitmap.CompressFormat.PNG, 100, it)
        }
        compose.onNodeWithText("RIPRISTINA TUTTE LE CATEGORIE (POI SALVA)").performScrollTo().performClick()
        compose.onNodeWithText("SALVA I MIEI INTERESSI").performScrollTo().performClick()
        compose.waitUntil(20_000) { saves == 2 }
        compose.runOnIdle { assertEquals(2, saves) }
    }
}
