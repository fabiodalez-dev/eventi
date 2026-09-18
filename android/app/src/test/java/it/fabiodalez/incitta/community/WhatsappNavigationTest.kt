package it.fabiodalez.incitta.community

import it.fabiodalez.incitta.data.WhatsappPendingRequest
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappNavigationTest {
    private val token = "session-a"
    private val created = 1_000_000L
    private val fresh = ReceivedWhatsappCode(WhatsappPendingRequest("ee7b3d33-9b4c-4b53-96a7-cb719458bf6d", WhatsappPendingRequest.digest(token), created), "123456")

    @Test fun freshUnseenCodeOpensTheWhatsappScreen() {
        assertTrue(shouldNavigateToWhatsapp(fresh, token, created + 1))
    }

    @Test fun codeAlreadyShownInTheFormNeverNavigatesAgain() {
        // Rotation, re-entering the community or "Più tardi" must not bring the person back.
        assertFalse(shouldNavigateToWhatsapp(fresh.copy(seen = true), token, created + 1))
    }

    @Test fun missingCodeSessionOrExpiredHandshakeDoNotNavigate() {
        assertFalse(shouldNavigateToWhatsapp(null, token, created + 1))
        assertFalse(shouldNavigateToWhatsapp(fresh, null, created + 1))
        assertFalse(shouldNavigateToWhatsapp(fresh, "session-b", created + 1))
        assertFalse(shouldNavigateToWhatsapp(fresh, token, created + WhatsappPendingRequest.MAX_AGE_MS))
    }
}
