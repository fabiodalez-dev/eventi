package it.fabiodalez.incitta.data

import org.junit.Assert.*
import org.junit.Test

class NetworkDiagnosticsTest {
    @Test fun sharesConnectionsWithoutDiagnosticListeners() {
        val first = ApiClient("first").client
        assertSame(first, ApiClient("second").client)
        assertTrue(first.interceptors.isEmpty())
        assertFalse(requestFailureMessage(java.io.IOException("secret")).contains("DEBUG"))
    }
}
