package it.fabiodalez.incitta.data

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappPendingRequestTest {
    private val token = "session-a"
    private val id = "ee7b3d33-9b4c-4b53-96a7-cb719458bf6d"
    private val created = 1_000_000L
    private val pending = WhatsappPendingRequest(id, WhatsappPendingRequest.digest(token), created)

    @Test fun matchingFreshRequestAcceptsOnlyTheSixDigitCode() {
        assertTrue(pending.accepts(id, token, "123456", created + 1))
    }

    @Test fun callbackCannotCrossLoginSessions() {
        assertFalse(pending.accepts(id, "session-b", "123456", created + 1))
    }

    @Test fun missingOrForgedHandshakeNeverFillsTheCode() {
        assertFalse(pending.accepts(null, token, "123456", created + 1))
        assertFalse(pending.accepts("ee7b3d33-9b4c-4b53-96a7-cb719458bf60", token, "123456", created + 1))
    }

    @Test fun oldRequestExpiresAtTheExactFiveMinuteBoundary() {
        assertTrue(pending.accepts(id, token, "123456", created + WhatsappPendingRequest.MAX_AGE_MS - 1))
        assertFalse(pending.accepts(id, token, "123456", created + WhatsappPendingRequest.MAX_AGE_MS))
    }

    @Test fun clockRollbackInvalidatesTheRequest() {
        assertFalse(pending.accepts(id, token, "123456", created - 1))
    }

    @Test fun malformedAndUnicodeCodesAreRejected() {
        listOf("", "12345", "1234567", "12 456", "１２３４５６", "123456\n").forEach {
            assertFalse(it, pending.accepts(id, token, it, created + 1))
        }
    }

    @Test fun malformedStoredNonceAndEmptySessionFailClosed() {
        assertFalse(pending.copy(id = "broken").validFor(token, created))
        assertFalse(pending.copy(sessionDigest = WhatsappPendingRequest.digest("")).validFor("", created))
    }

    @Test fun bindingServerChallengeKeepsTheOriginalExpiryAndAccount() {
        val bound = pending.copy(challengeId = "111d9e35-b174-4bd0-b28a-01b9ee783a23")
        assertFalse(bound.validFor("session-b", created))
        assertFalse(bound.validFor(token, created + WhatsappPendingRequest.MAX_AGE_MS))
    }
}
