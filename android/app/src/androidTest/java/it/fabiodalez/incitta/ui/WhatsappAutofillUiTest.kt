package it.fabiodalez.incitta.ui

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.core.app.ApplicationProvider
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.community.WhatsappAutofill
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.data.WhatsappPendingRequest
import java.util.UUID
import kotlinx.serialization.json.*
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Rule
import org.junit.Test

class WhatsappAutofillUiTest {
    @get:Rule val compose = createComposeRule()
    private val context = ApplicationProvider.getApplicationContext<Context>()
    private val session = Session("ui-otp-session", User(91, email = "otp@example.test"), null)
    private val challenge = UUID.randomUUID().toString()
    private val nonce = UUID.randomUUID().toString()
    private var submitted: JsonObject? = null

    @Before fun prepare() {
        reset()
        LocalStore(context).writeSession(session)
        LocalStore(context).writeWhatsappRequest(WhatsappPendingRequest(nonce, WhatsappPendingRequest.digest(session.token), System.currentTimeMillis(), challenge))
    }

    @After fun reset() {
        WhatsappAutofill.discard()
        context.getSharedPreferences("incitta", Context.MODE_PRIVATE).edit().clear().commit()
    }

    private fun render(challengeId: String = challenge) {
        compose.setContent {
            InCittaTheme { Column(Modifier.verticalScroll(rememberScrollState())) {
                CommunityWhatsapp(buildJsonObject {
                    put("available", true); put("autofill_available", true); put("verified", false); put("challenge_id", challengeId)
                }, false, session.token, { path, body, method ->
                    assertEquals("whatsapp/confirm", path)
                    assertEquals("POST", method)
                    submitted = body
                }, {})
            } }
        }
    }

    private fun deliver() = WhatsappAutofill.receive(context, Intent(WhatsappAutofill.ACTION).putExtra("request_id", nonce).putExtra("code", "123456"))

    @Test fun receivedCodeFillsTheFieldButRequiresAnExplicitConfirmation() {
        render()
        compose.runOnIdle { assertTrue(deliver()) }
        compose.onNode(hasSetTextAction() and hasText(context.getString(R.string.community_wa_code))).assertTextContains("123456")
        compose.onNodeWithText(context.getString(R.string.community_wa_autofilled)).assertIsDisplayed()
        compose.runOnIdle { assertNull(submitted) }
        compose.onNodeWithText(context.getString(R.string.community_wa_confirm)).performScrollTo().performClick()
        compose.runOnIdle {
            assertEquals(challenge, submitted?.get("challenge_id")?.jsonPrimitive?.content)
            assertEquals("123456", submitted?.get("code")?.jsonPrimitive?.content)
            // The form never clears the handshake itself: only a successful confirmation in CommunityScreen does,
            // so a failed attempt can be retried with the same code.
            assertEquals(challenge, LocalStore(context).readWhatsappRequest()?.challengeId)
        }
    }

    @Test fun codeForAnotherChallengeNeverFillsTheVisibleForm() {
        render(UUID.randomUUID().toString())
        compose.runOnIdle { assertTrue(deliver()) }
        compose.onNodeWithText("123456").assertDoesNotExist()
        compose.onNodeWithText(context.getString(R.string.community_wa_confirm)).assertIsNotEnabled()
        compose.runOnIdle { assertNull(submitted) }
    }

    @Test fun manualCopyCodeRemainsAvailableWithoutAnyCallback() {
        render()
        compose.onNode(hasSetTextAction() and hasText(context.getString(R.string.community_wa_code))).performTextInput("654321")
        compose.onNodeWithText(context.getString(R.string.community_wa_confirm)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals("654321", submitted?.get("code")?.jsonPrimitive?.content) }
    }
}
