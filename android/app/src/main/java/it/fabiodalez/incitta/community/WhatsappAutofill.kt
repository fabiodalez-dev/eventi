package it.fabiodalez.incitta.community

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.util.Log
import com.whatsapp.otp.android.sdk.WhatsAppOtpHandler
import com.whatsapp.otp.android.sdk.WhatsAppOtpIncomingIntentHandler
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.WhatsappPendingRequest
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

/** [seen] turns true once the form has read the code: from then on nobody navigates to the WhatsApp screen for it again. */
internal data class ReceivedWhatsappCode(val request: WhatsappPendingRequest, val code: String, val seen: Boolean = false)

/** Only a freshly received code, valid for this session and not yet shown in the form, takes the person to the WhatsApp screen. */
internal fun shouldNavigateToWhatsapp(code: ReceivedWhatsappCode?, token: String?, now: Long): Boolean =
    token != null && code != null && !code.seen && code.request.validFor(token, now)

/** The code stays in memory. Only an encrypted, expiring handshake is persisted. */
internal object WhatsappAutofill {
    const val ACTION = "com.whatsapp.otp.OTP_RETRIEVED"
    private const val TAG = "WhatsappOtp"
    private val incoming = MutableStateFlow<ReceivedWhatsappCode?>(null)
    val received = incoming.asStateFlow()

    fun begin(context: Context, token: String): String? {
        clear(context)
        return runCatching {
            val handler = WhatsAppOtpHandler()
            if (!handler.isWhatsAppOtpHandshakeSupported(context)) return null
            val id = UUID.randomUUID()
            LocalStore(context).writeWhatsappRequest(WhatsappPendingRequest(id.toString(), WhatsappPendingRequest.digest(token), System.currentTimeMillis()))
            handler.sendOtpIntentToWhatsApp(id, context)
            id.toString()
        }.getOrElse { clear(context); null }
    }

    fun bind(context: Context, id: String, token: String, challengeId: String) {
        val store = LocalStore(context)
        val pending = store.readWhatsappRequest() ?: return
        if (pending.id != id || !pending.validFor(token, System.currentTimeMillis())) return
        if (runCatching { UUID.fromString(challengeId).toString() != challengeId }.getOrDefault(true)) return
        val bound = pending.copy(challengeId = challengeId)
        store.writeWhatsappRequest(bound)
        incoming.value?.takeIf { it.request.id == id }?.let { incoming.value = it.copy(request = bound) }
    }

    /** [sender] is a test seam: in the app it is always the creator package of the intent's `_ci_` PendingIntent. */
    fun receive(context: Context, intent: Intent, sender: String? = WhatsappSender.of(intent)): Boolean {
        if (intent.action != ACTION) return false
        if (!WhatsappSender.trusted(sender)) {
            if (BuildConfig.DEBUG) logUntrusted(sender)
            if (!WhatsappSender.accepts(sender)) return false
        }
        val store = LocalStore(context)
        val session = store.readSession() ?: return false
        val pending = store.readWhatsappRequest() ?: return false
        if (!pending.validFor(session.token, System.currentTimeMillis()) || incoming.value != null) return false
        val code = runCatching { WhatsAppOtpIncomingIntentHandler().getOtpCodeFromWhatsAppIntent(intent, pending.id) }.getOrNull() ?: return false
        if (!pending.accepts(intent.getStringExtra("request_id"), session.token, code, System.currentTimeMillis())) return false
        incoming.value = ReceivedWhatsappCode(pending, code)
        return true
    }

    /** Package name only: never the code, the request id, the phone or the token. Timber is only a transitive dependency and no tree is planted, so it would log nothing. */
    @SuppressLint("LogNotTimber")
    private fun logUntrusted(sender: String?) {
        Log.w(TAG, "one-tap intent from untrusted sender: " + (sender ?: "<none>"))
    }

    fun take(context: Context, token: String, challengeId: String): String? {
        val value = incoming.value ?: return null
        if (!value.request.validFor(token, System.currentTimeMillis())) { clear(context); return null }
        if (value.request.challengeId != challengeId) return null
        if (!value.seen) incoming.value = value.copy(seen = true)
        // Readable until confirmation, DELETE, a failed send, a session change or a new begin() clears it:
        // a rotation or a failed confirmation must not lose the code.
        return value.code
    }

    fun clear(context: Context) {
        LocalStore(context).clearWhatsappRequest()
        discard()
    }

    fun discard() { incoming.value = null }
}
