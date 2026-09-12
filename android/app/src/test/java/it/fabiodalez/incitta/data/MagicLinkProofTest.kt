package it.fabiodalez.incitta.data

import org.junit.Assert.*
import org.junit.Test

class MagicLinkProofTest {
    @Test fun matchesRfc7636Vector() {
        assertEquals("E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM",
            MagicLinkProof.challenge("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"))
    }
    @Test fun generatesDistinctUrlSafeProofs() {
        val first = MagicLinkProof.createVerifier()
        assertTrue(first.matches(Regex("[A-Za-z0-9_-]{43}")))
        assertNotEquals(first, MagicLinkProof.createVerifier())
    }
}
