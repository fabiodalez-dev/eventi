package it.fabiodalez.incitta.data

import org.junit.Assert.*
import org.junit.Test

/**
 * Il collegamento di conferma che la pagina del sito riporta nell'app: si
 * rimanda al server col token solo se è davvero quello della conferma, dello
 * stesso sito dell'API.
 */
class EmailConfirmationLinkTest {
    private val api = "https://eventi.fabiodalez.it/api/v1/"
    private val link = "https://eventi.fabiodalez.it/email/verifica/12/0123abcd?expires=1790000000&signature=abc123"

    @Test fun `the signed confirmation link of the same site is accepted`() {
        assertTrue(isEmailConfirmationLink(link, api))
        assertTrue(isEmailConfirmationLink("http://10.0.2.2:8095/email/verifica/3/ff?expires=1&signature=x", "http://10.0.2.2:8095/api/v1/"))
    }

    @Test fun `links of other sites, schemes or ports are refused`() {
        assertFalse(isEmailConfirmationLink(link.replace("eventi.fabiodalez.it", "attacker.example"), api))
        assertFalse(isEmailConfirmationLink(link.replace("https://", "http://"), api))
        assertFalse(isEmailConfirmationLink("https://eventi.fabiodalez.it:8443/email/verifica/12/0123abcd?signature=abc", api))
    }

    @Test fun `other pages and unsigned links are refused`() {
        assertFalse(isEmailConfirmationLink("https://eventi.fabiodalez.it/il-mio-profilo?signature=abc", api))
        assertFalse(isEmailConfirmationLink("https://eventi.fabiodalez.it/email/verifica/12/0123abcd?expires=1", api))
        assertFalse(isEmailConfirmationLink("https://eventi.fabiodalez.it/email/verifica/12/0123abcd?nosignature=abc", api))
        assertFalse(isEmailConfirmationLink("non è un indirizzo", api))
    }
}
