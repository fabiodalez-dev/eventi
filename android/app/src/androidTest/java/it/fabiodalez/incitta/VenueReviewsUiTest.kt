package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.OwnVenueReview
import it.fabiodalez.incitta.data.VenueReviewPage
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.VenueReviewsSection
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class VenueReviewsUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun registeredUserCanChooseStarsSubmitAndDelete() {
        var own: OwnVenueReview? = null
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    Column(Modifier.verticalScroll(rememberScrollState())) {
                        VenueReviewsSection("locale", 1, {},
                            load = { VenueReviewPage(myReview = own) },
                            submit = { stars, text -> own = OwnVenueReview(stars, text, "pending") },
                            delete = { own = null })
                    }
                }
            }
        }
        compose.onNodeWithText("Invia per approvazione").performScrollTo().assertIsNotEnabled()
        compose.onNodeWithContentDescription("4 su 5", useUnmergedTree = true).performScrollTo().performClick()
        compose.onNodeWithText("Racconta la tua esperienza").performScrollTo().performTextInput("Locale accogliente e tranquillo.")
        compose.onNodeWithText("Invia per approvazione").performScrollTo().performClick()
        compose.onNodeWithText("In attesa di approvazione").assertExists()
        compose.runOnIdle { assertEquals(4, own?.rating) }
        compose.onNodeWithText("Elimina la mia recensione").performScrollTo().performClick()
        compose.onNodeWithText("Recensione eliminata.").assertExists()
        compose.runOnIdle { assertEquals(null, own) }
    }

    @Test fun guestMustLoginBeforeWriting() {
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    VenueReviewsSection("locale", null, {}, { VenueReviewPage() }, { _, _ -> error("Guest submitted") }, {})
                }
            }
        }
        compose.onNodeWithText("Accedi per lasciare una recensione").assertExists()
        compose.onNodeWithText("Racconta la tua esperienza").assertDoesNotExist()
    }
}
