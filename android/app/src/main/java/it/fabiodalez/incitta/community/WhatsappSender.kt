package it.fabiodalez.incitta.community

import android.app.PendingIntent
import android.content.Intent
import androidx.core.content.IntentCompat

/**
 * Who sent the one-tap code. WhatsApp attaches a PendingIntent under [EXTRA_CREATOR]; its creatorPackage
 * is filled in by the system and a third app cannot forge it (documented WhatsApp OTP check).
 */
internal object WhatsappSender {
    const val EXTRA_CREATOR = "_ci_"
    val TRUSTED = setOf("com.whatsapp", "com.whatsapp.w4b")

    /** Log-only until the one-tap flow is proven on a real device with WhatsApp AND WhatsApp Business; then flip to true (issue #106). */
    const val ENFORCE = false

    fun of(intent: Intent): String? =
        runCatching { IntentCompat.getParcelableExtra(intent, EXTRA_CREATOR, PendingIntent::class.java)?.creatorPackage }.getOrNull()

    fun trusted(sender: String?): Boolean = sender in TRUSTED

    fun accepts(sender: String?, enforce: Boolean = ENFORCE): Boolean = trusted(sender) || !enforce
}
