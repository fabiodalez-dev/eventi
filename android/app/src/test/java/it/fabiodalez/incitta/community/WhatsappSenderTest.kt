package it.fabiodalez.incitta.community

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappSenderTest {
    @Test fun whatsappAndWhatsappBusinessAreTrusted() {
        assertTrue(WhatsappSender.trusted("com.whatsapp"))
        assertTrue(WhatsappSender.trusted("com.whatsapp.w4b"))
    }

    @Test fun missingEmptyOrLookalikeSendersAreNotTrusted() {
        assertFalse(WhatsappSender.trusted(null))
        assertFalse(WhatsappSender.trusted(""))
        assertFalse(WhatsappSender.trusted("com.whatsapp.evil"))
        assertFalse(WhatsappSender.trusted("com.WhatsApp"))
    }

    @Test fun enforcingRejectsEverySenderButWhatsapp() {
        assertFalse(WhatsappSender.accepts(null, enforce = true))
        assertFalse(WhatsappSender.accepts("com.whatsapp.evil", enforce = true))
        assertTrue(WhatsappSender.accepts("com.whatsapp", enforce = true))
        assertTrue(WhatsappSender.accepts("com.whatsapp.w4b", enforce = true))
    }

    @Test fun logOnlyModeStillAcceptsUntrustedSenders() {
        assertTrue(WhatsappSender.accepts(null, enforce = false))
        assertTrue(WhatsappSender.accepts("com.whatsapp.evil", enforce = false))
    }

    @Test fun enforcementStaysOffUntilTheRealDeviceTest() {
        // Tripwire: flip together with ENFORCE, only after the real-device test in PIANO-UTENTI-VERIFICATI-SOCIAL.md (issue #106).
        assertFalse("flip only after the real-device test in PIANO", WhatsappSender.ENFORCE)
    }
}
