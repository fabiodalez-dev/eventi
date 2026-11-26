package it.fabiodalez.incitta.notifications

import org.junit.Assert.*
import org.junit.Test

/*
 * Gli indirizzi interni della gestione (`incitta://gestione/…`) si aprono solo
 * dalle notifiche dell'app: con la persona collegata e il segreto
 * dell'installazione. Un'altra app che manda un intent esplicito a
 * `MainActivity` con un id utente indovinato non apre niente; gli App Link e
 * gli altri collegamenti restano come prima.
 */
class NotificationLinkProofTest {
    private val secret = "segreto-della-installazione-0123456789abcdef"
    private fun allowed(uri: String?, claimed: Long?, proof: String?, session: Long? = 7) = notificationLinkAllowed(uri, claimed, proof, session) { secret }

    @Test fun everyInternalManagementAddressIsRecognised() {
        listOf(
            "incitta://gestione/venue/12/event/40",
            "incitta://gestione/organizer/3/comments/8",
            "incitta://gestione/venue/12/sponsorships/5",
            "incitta://gestione/me/applications",
            "incitta://gestione/me/invitations",
            /* Anche le aree che verranno, e le forme scritte in un altro modo. */
            "incitta://gestione/venue/12/qualcosa-di-nuovo",
            "INCITTA://GESTIONE/venue/12/home",
            "incitta://utente@gestione/venue/12/home",
            "incitta://gestione:80/venue/12/home",
            "incitta://gestione?x=1",
        ).forEach { assertTrue(it, isInternalManagementLink(it)) }
        listOf(
            null,
            "https://eventi.fabiodalez.it/gestione/circolo",
            "incitta://auth/magic?token=abc",
            "incitta://auth/verify-email?url=x",
            "incitta://account",
            "incitta://gestionex/venue/1/home",
        ).forEach { assertFalse(it.toString(), isInternalManagementLink(it)) }
    }

    @Test fun aNotificationOfTheAppOpensItsInternalAddress() {
        assertTrue(allowed("incitta://gestione/venue/12/home", claimed = 7, proof = secret))
        assertTrue(allowed("incitta://gestione/me/invitations", claimed = 7, proof = secret))
    }

    @Test fun anotherAppCannotForgeAnInternalAddressWithAGuessedUser() {
        /* Senza segreto, con uno sbagliato o vuoto, senza id utente o senza sessione. */
        assertFalse(allowed("incitta://gestione/venue/12/home", claimed = 7, proof = null))
        assertFalse(allowed("incitta://gestione/venue/12/home", claimed = 7, proof = "indovinato"))
        assertFalse(allowed("incitta://gestione/venue/12/home", claimed = 7, proof = ""))
        assertFalse(allowed("incitta://gestione/me/applications", claimed = null, proof = secret))
        assertFalse(allowed("incitta://gestione/me/applications", claimed = null, proof = null))
        assertFalse(allowed("incitta://gestione/venue/12/home", claimed = 7, proof = secret, session = null))
        /* La notifica di un'altra persona non apre niente, come prima. */
        assertFalse(allowed("incitta://gestione/venue/12/home", claimed = 8, proof = secret))
        assertFalse(allowed("https://eventi.fabiodalez.it/eventi/jazz", claimed = 8, proof = secret))
    }

    @Test fun appLinksAndDeepLinksStayAsTheyWere() {
        assertTrue(allowed("https://eventi.fabiodalez.it/eventi/jazz-in-cortile", claimed = null, proof = null))
        assertTrue(allowed("https://eventi.fabiodalez.it/gestione/circolo-aurora/eventi", claimed = null, proof = null))
        assertTrue(allowed("https://eventi.fabiodalez.it/app/sponsorizzazioni/ritorno?esito=ok", claimed = null, proof = null, session = null))
        assertTrue(allowed("incitta://auth/magic?token=abc", claimed = null, proof = null, session = null))
        assertTrue(allowed("incitta://auth/verify-email?url=https%3A%2F%2Feventi.fabiodalez.it", claimed = null, proof = null, session = null))
        assertTrue(allowed("incitta://account", claimed = null, proof = null))
        /* La push di un evento: il suo `url` pubblico, con la persona giusta. */
        assertTrue(allowed("https://eventi.fabiodalez.it/eventi/jazz", claimed = 7, proof = secret))
        assertTrue(allowed(null, claimed = null, proof = null))
    }
}
