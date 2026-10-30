package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.BookingAvailability
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.ReservationScreen
import org.junit.Rule
import org.junit.Test

/**
 * Come sul sito: chi non ha confermato l'email non compila un modulo che non
 * potrà inviare, ma trova subito l'invito a confermarla.
 */
class ReservationVerificationUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    private fun screen(verified: Boolean) {
        val state = AppUiState(
            isLoading = false,
            session = Session("token", User(id = 1, email = "giulia@example.test", emailVerified = verified), null),
            bookingDate = Occurrence(occurrenceId = 7, eventId = 7, eventSlug = "evento", title = "Serata di prova", startsAt = "2026-12-06T18:00:00+01:00"),
            bookingAvailability = BookingAvailability(enabled = true, open = true, remaining = 10),
        )
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme { ReservationScreen(state, PaddingValues(), { _, _, _, _ -> }, {}, onRetry = {}) }
            }
        }
    }

    @Test fun unverifiedAccountSeesTheInvitationInsteadOfTheForm() {
        screen(verified = false)
        compose.onNodeWithText("Conferma il tuo indirizzo email per prenotare.").assertExists()
        compose.onNodeWithText("Reinvia email di conferma").assertExists()
        compose.onNodeWithText("Chi prenota").assertDoesNotExist()
        compose.onNodeWithText("CONFERMA PRENOTAZIONE GRATUITA").assertDoesNotExist()
    }

    @Test fun verifiedAccountSeesTheForm() {
        screen(verified = true)
        compose.onNodeWithText("Chi prenota").assertExists()
        compose.onNodeWithText("Conferma il tuo indirizzo email per prenotare.").assertDoesNotExist()
    }
}
