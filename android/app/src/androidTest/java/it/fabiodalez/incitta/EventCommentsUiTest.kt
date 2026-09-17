package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.data.EventComment
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
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

    @Test fun unverifiedUserCanResendAndRefreshAfterVerification() {
        var verified = false
        var sent = 0
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    Column(Modifier.verticalScroll(rememberScrollState())) {
                        EventCommentsSection("evento", 1, {},
                            load = { _, _, _ -> EventCommentPage(canComment = verified) },
                            submit = { _, _ -> error("Unverified user submitted") },
                            react = { _, _ -> }, delete = {}, resend = { sent++ })
                    }
                }
            }
        }
        compose.onNodeWithText("Il tuo commento").assertDoesNotExist()
        compose.onNodeWithText("Reinvia email di conferma").performScrollTo().performClick()
        compose.onNodeWithText("Email di conferma inviata. Controlla anche la cartella spam.").assertExists()
        compose.runOnIdle { assertEquals(1, sent); verified = true }
        compose.onNodeWithText("Ho confermato, aggiorna").performScrollTo().performClick()
        compose.onNodeWithText("Il tuo commento").assertExists()
    }

    @Test fun rejectedReplyKeepsDraftAndParentAndReactionTargetsTheComment() {
        var root = EventComment(7, "Autore", "Informazioni sul concerto")
        var reject = true
        var parentId: Long? = null
        var targetThread: Long? = null
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    Column(Modifier.verticalScroll(rememberScrollState())) {
                        EventCommentsSection("evento", 1, {},
                            load = { _, thread, _ ->
                                targetThread = thread
                                EventCommentPage(comments = listOf(root), canComment = true, thread = thread)
                            },
                            submit = { body, parent ->
                                parentId = parent
                                if (reject) throw ApiException(422, ApiProblem(fields = mapOf("body" to listOf("Rimuovi i dati personali."))))
                                root = root.copy(replies = listOf(EventComment(8, "Io", body)), repliesCount = 1)
                                8L
                            },
                            react = { id, type ->
                                assertEquals(7L, id); assertEquals("useful", type)
                                root = root.copy(myReaction = type, reactionsCount = 1)
                            }, delete = {}, resend = {})
                    }
                }
            }
        }
        compose.onNodeWithText("Utile").performScrollTo().performClick()
        compose.onNodeWithText("Utile").assertIsSelected()
        compose.onNodeWithText("Rispondi").performScrollTo().performClick()
        compose.onNodeWithText("Il tuo commento").performScrollTo().performTextInput("test@example.com")
        compose.onNodeWithText("Pubblica").performScrollTo().performClick()
        compose.onNodeWithText("Rimuovi i dati personali.").assertExists()
        compose.onNodeWithText("Il tuo commento").assertTextContains("test@example.com")
        compose.runOnIdle { assertEquals(7L, parentId); reject = false }
        compose.onNodeWithText("Il tuo commento").performScrollTo().performTextReplacement("Ci vediamo al concerto!")
        compose.onNodeWithText("Pubblica").performScrollTo().performClick()
        compose.onNodeWithText("Ci vediamo al concerto!").assertExists()
        compose.runOnIdle { assertEquals(7L, parentId); assertEquals(8L, targetThread) }
    }
}
