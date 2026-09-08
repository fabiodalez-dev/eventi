package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.SavedScreen
import java.time.OffsetDateTime
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class SavedAgendaUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    private val futureDate = OffsetDateTime.now().plusDays(2)
    private fun event(id: Long, title: String, start: OffsetDateTime) = Occurrence(
        occurrenceId = id, eventId = id, eventSlug = "event-$id", title = title,
        startsAt = start.toString(), effectiveEndsAt = start.plusHours(2).toString(),
    )
    private fun screen(onOpen: (Occurrence) -> Unit = {}) {
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    SavedScreen(AppUiState(isLoading = false, savedOccurrences = listOf(
                        event(1, "Passato di prova", OffsetDateTime.now().minusDays(2)),
                        event(2, "Futuro di prova", futureDate),
                    )), PaddingValues(), onOpen, {})
                }
            }
        }
    }
    @Test fun pastTabSeparatesFinishedEvents() {
        screen()
        compose.onNodeWithText("FUTURO DI PROVA").assertExists()
        compose.onNodeWithText("PASSATO DI PROVA").assertDoesNotExist()
        compose.onNodeWithText("PASSATI").performClick()
        compose.onNodeWithText("PASSATO DI PROVA").assertExists()
        compose.onNodeWithText("FUTURO DI PROVA").assertDoesNotExist()
    }
    @Test fun tappingTheDayWithOneSavedEventOpensIt() {
        var opened: Long? = null
        screen { opened = it.occurrenceId }
        compose.onNode(hasText("CALENDARIO") and hasClickAction()).performClick()
        compose.onNodeWithText(futureDate.dayOfMonth.toString()).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(2L, opened) }
    }
}
