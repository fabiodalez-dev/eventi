package it.fabiodalez.incitta.data

import java.io.IOException
import java.net.SocketException
import org.junit.Assert.*
import org.junit.Test

class NetworkDiagnosticsTest {
    @Test fun clientsShareConnectionsButKeepInstallationHeadersOutsideThePool() {
        assertSame(ApiClient("first").client, ApiClient("second").client)
        assertEquals("/api/v1/me/calendar/export", safePath("/api/v1/me/calendar/export"))
    }
    @Test fun retainsExactNetworkFailureWithoutRequestSecrets() {
        val cause = diagnosticCause(IOException("https://private.test?q=secret", SocketException("ECONNRESET connection reset token=supersecret")))
        assertTrue(cause.contains("java.net.SocketException"))
        assertTrue(cause.contains("ECONNRESET"))
        assertFalse(cause.contains("secret"))
        assertFalse(cause.contains("private.test"))
    }

    @Test fun neverIncludesArbitraryMessagesOrPayloads() {
        assertEquals("", sanitizeNetworkMessage("Authorization: Bearer abc email=private@example.test password: hidden"))
        assertFalse(diagnosticCause(IllegalArgumentException("response body password=secret")).contains("secret"))
        assertEquals("/api/v1/events/…", safePath("/api/v1/events/private-slug"))
    }
}
