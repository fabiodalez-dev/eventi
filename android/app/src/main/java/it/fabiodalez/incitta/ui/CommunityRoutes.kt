package it.fabiodalez.incitta.ui

/** Opening the screen already on top must not stack a duplicate that Back would have to pop twice. */
internal fun pushRoute(stack: List<String>, next: String): List<String> = if (stack.lastOrNull() == next) stack else stack + next

/** The one-time WhatsApp invitation waits until the person is not in the middle of something else. */
internal fun shouldPromptWhatsapp(emailVerified: Boolean, whatsappVerified: Boolean, whatsappPrompted: Boolean, alreadyPrompted: Boolean, userIdle: Boolean): Boolean =
    emailVerified && !whatsappVerified && !whatsappPrompted && !alreadyPrompted && userIdle
