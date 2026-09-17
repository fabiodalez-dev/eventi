package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.EventComment
import it.fabiodalez.incitta.data.EventCommentPage
import it.fabiodalez.incitta.ui.EventCommentsSection
import it.fabiodalez.incitta.ui.InCittaTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class EventCommentsUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun guestCanReadLiteralMarkupButMustLoginToWrite() {
        var logins = 0
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    EventCommentsSection("evento", null, { logins++ },
                        load = { _, _, _ -> EventCommentPage(comments = listOf(EventComment(1, "Autore", "<script>alert(1)</script>"))) },
                        submit = { _, _ -> error("Guest submitted") }, react = { _, _ -> }, delete = {}, resend = {})
                }
            }
        }
        compose.onNodeWithText("<script>alert(1)</script>").assertExists()
        compose.onNodeWithText("Il tuo commento").assertDoesNotExist()
        compose.onNodeWithText("Accedi per commentare").performClick()
        compose.runOnIdle { assertEquals(1, logins) }
    }

    @Test fun verifiedUserCanPublishAndDeleteWithConfirmation() {
        var comments = emptyList<EventComment>()
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    Column(Modifier.verticalScroll(rememberScrollState())) {
                        EventCommentsSection("evento", 1, {},
                            load = { _, _, _ -> EventCommentPage(comments = comments, canComment = true) },
                            submit = { body, parent ->
                                assertEquals(null, parent)
                                comments = listOf(EventComment(1, "Autore", body, canDelete = true)); 1L
                            }, react = { _, _ -> }, delete = { comments = emptyList() }, resend = {})
                    }
                }
            }
        }
        compose.onNodeWithText("Pubblica").performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText("Il tuo commento").performScrollTo().performTextInput("Ci vediamo al concerto!")
        compose.onNodeWithText("Pubblica").performScrollTo().performClick()
        compose.onNodeWithText("Ci vediamo al concerto!").assertExists()
        compose.onNodeWithText("Elimina").performScrollTo().performClick()
        compose.onNodeWithText("Eliminare questo commento? Verranno eliminate anche le sue risposte.").assertExists()
        compose.onAllNodesWithText("Elimina").onLast().performClick()
        compose.waitUntil { comments.isEmpty() }
    }
}
