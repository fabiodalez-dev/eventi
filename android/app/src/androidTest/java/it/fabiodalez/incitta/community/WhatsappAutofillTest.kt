package it.fabiodalez.incitta.community

import android.content.Context
import android.content.Intent
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.data.WhatsappPendingRequest
import java.util.UUID
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** Runs the real SDK intent parser and Android Keystore; never sends a WhatsApp message. */
@RunWith(AndroidJUnit4::class)
class WhatsappAutofillTest {
    private val context = ApplicationProvider.getApplicationContext<Context>()
    private val store = LocalStore(context)
    private val session = Session("instrumentation-session", User(91, email = "otp@example.test"), null)
    private val challenge = UUID.randomUUID().toString()
    private lateinit var request: WhatsappPendingRequest

    @Before fun prepare() {
        reset()
        store.writeSession(session)
        request = WhatsappPendingRequest(UUID.randomUUID().toString(), WhatsappPendingRequest.digest(session.token), System.currentTimeMillis())
        store.writeWhatsappRequest(request)
    }

    @After fun reset() {
        WhatsappAutofill.discard()
        context.getSharedPreferences("incitta", Context.MODE_PRIVATE).edit().clear().commit()
    }

    private fun callback(id: String? = request.id, code: String? = "123456") = Intent(WhatsappAutofill.ACTION)
        .putExtra("request_id", id).putExtra("code", code)

    @Test fun validCodeIsBoundToTheServerChallengeAndStaysReadableUntilCleared() {
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertTrue(WhatsappAutofill.receive(context, callback()))
        assertEquals("123456", WhatsappAutofill.take(context, session.token, challenge))
        assertEquals("123456", WhatsappAutofill.take(context, session.token, challenge))
        assertFalse(WhatsappAutofill.receive(context, callback()))
        WhatsappAutofill.clear(context)
        assertNull(WhatsappAutofill.take(context, session.token, challenge))
        assertNull(store.readWhatsappRequest())
    }

    @Test fun anotherChallengeCannotConsumeTheCode() {
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertTrue(WhatsappAutofill.receive(context, callback()))
        assertNull(WhatsappAutofill.take(context, session.token, UUID.randomUUID().toString()))
        assertEquals("123456", WhatsappAutofill.take(context, session.token, challenge))
    }

    @Test fun missingMalformedOrForgedNonceCannotFillTheForm() {
        listOf(null, "", "not-a-uuid", UUID.randomUUID().toString(), " ${request.id}").forEach {
            assertFalse(WhatsappAutofill.receive(context, callback(id = it)))
            assertNull(WhatsappAutofill.received.value)
        }
    }

    @Test fun onlyTheOtpActionAndAsciiSixDigitCodeAreAccepted() {
        assertFalse(WhatsappAutofill.receive(context, callback().setAction(Intent.ACTION_VIEW)))
        listOf(null, "", "12345", "1234567", "１２３４５６", "123456\n").forEach {
            assertFalse(WhatsappAutofill.receive(context, callback(code = it)))
            assertNull(WhatsappAutofill.received.value)
        }
    }

    @Test fun expiredHandshakeCannotReceiveOrBind() {
        store.writeWhatsappRequest(request.copy(createdAt = System.currentTimeMillis() - WhatsappPendingRequest.MAX_AGE_MS))
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertNull(store.readWhatsappRequest()?.challengeId)
        assertFalse(WhatsappAutofill.receive(context, callback()))
    }

    @Test fun changingAccountClearsStoredAndReceivedCodes() {
        assertTrue(WhatsappAutofill.receive(context, callback()))
        store.writeSession(session.copy(token = "another-session", user = User(92, email = "other@example.test")))
        assertNull(store.readWhatsappRequest())
        assertNull(WhatsappAutofill.received.value)
        assertFalse(WhatsappAutofill.receive(context, callback()))
    }

    @Test fun logoutRejectsAnOtherwiseValidCallback() {
        store.clearSession()
        assertNull(store.readWhatsappRequest())
        assertFalse(WhatsappAutofill.receive(context, callback()))
    }

    @Test fun codeArrivingBeforeTheHttpResponseWaitsForItsChallenge() {
        assertTrue(WhatsappAutofill.receive(context, callback()))
        assertNull(WhatsappAutofill.take(context, session.token, challenge))
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertEquals("123456", WhatsappAutofill.take(context, session.token, challenge))
    }

    @Test fun duplicateCallbackCannotReplaceTheFirstCode() {
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertTrue(WhatsappAutofill.receive(context, callback()))
        assertFalse(WhatsappAutofill.receive(context, callback(code = "654321")))
        assertEquals("123456", WhatsappAutofill.take(context, session.token, challenge))
    }

    @Test fun handshakeSurvivesMemoryLossButPlaintextOtpIsNeverPersisted() {
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertTrue(WhatsappAutofill.receive(context, callback()))
        val stored = context.getSharedPreferences("incitta", Context.MODE_PRIVATE).all.toString()
        listOf("123456", request.id, challenge, session.token).forEach { assertFalse(stored.contains(it)) }
        WhatsappAutofill.discard()
        assertNull(WhatsappAutofill.take(context, session.token, challenge))
        assertEquals(challenge, LocalStore(context).readWhatsappRequest()?.challengeId)
        assertTrue(WhatsappAutofill.receive(context, callback()))
    }

    @Test fun obsoleteHttpResponseCannotBindTheNewHandshake() {
        val replacement = request.copy(id = UUID.randomUUID().toString())
        store.writeWhatsappRequest(replacement)
        WhatsappAutofill.bind(context, request.id, session.token, challenge)
        assertNull(store.readWhatsappRequest()?.challengeId)
        assertFalse(WhatsappAutofill.receive(context, callback()))
    }

    @Test fun malformedOrCrossSessionChallengeBindingIsRejected() {
        WhatsappAutofill.bind(context, request.id, session.token, "malformed")
        assertNull(store.readWhatsappRequest()?.challengeId)
        WhatsappAutofill.bind(context, request.id, "other-session", challenge)
        assertNull(store.readWhatsappRequest()?.challengeId)
    }
}
