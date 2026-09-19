package it.fabiodalez.incitta.ui

/** Opening the screen already on top must not stack a duplicate that Back would have to pop twice. */
internal fun pushRoute(stack: List<String>, next: String): List<String> = if (stack.lastOrNull() == next) stack else stack + next

/**
 * WhatsApp verification is optional: the app never opens on it. The single invitation appears
 * over the first event the person opens, and whatever they answer it is not shown again.
 */
internal fun shouldPromptWhatsapp(emailVerified: Boolean, whatsappVerified: Boolean, whatsappPrompted: Boolean, alreadyPrompted: Boolean, eventOpen: Boolean): Boolean =
    emailVerified && !whatsappVerified && !whatsappPrompted && !alreadyPrompted && eventOpen
