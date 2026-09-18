package it.fabiodalez.incitta.ui

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappPromptTest {
    @Test fun idleVerifiedEmailWithoutWhatsappIsPrompted() {
        assertTrue(shouldPromptWhatsapp(emailVerified = true, whatsappVerified = false, whatsappPrompted = false, alreadyPrompted = false, userIdle = true))
    }

    @Test fun busyPersonIsNotInterrupted() {
        assertFalse(shouldPromptWhatsapp(emailVerified = true, whatsappVerified = false, whatsappPrompted = false, alreadyPrompted = false, userIdle = false))
    }

    @Test fun anyBlockingConditionSuppressesThePrompt() {
        assertFalse(shouldPromptWhatsapp(emailVerified = false, whatsappVerified = false, whatsappPrompted = false, alreadyPrompted = false, userIdle = true))
        assertFalse(shouldPromptWhatsapp(emailVerified = true, whatsappVerified = true, whatsappPrompted = false, alreadyPrompted = false, userIdle = true))
        assertFalse(shouldPromptWhatsapp(emailVerified = true, whatsappVerified = false, whatsappPrompted = true, alreadyPrompted = false, userIdle = true))
        assertFalse(shouldPromptWhatsapp(emailVerified = true, whatsappVerified = false, whatsappPrompted = false, alreadyPrompted = true, userIdle = true))
    }

    @Test fun onlyOneCombinationOfAllThirtyTwoPrompts() {
        val all = listOf(false, true)
        var prompts = 0
        for (a in all) for (b in all) for (c in all) for (d in all) for (e in all) if (shouldPromptWhatsapp(a, b, c, d, e)) prompts++
        assertTrue(prompts == 1)
    }
}
