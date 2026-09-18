package it.fabiodalez.incitta.community

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.os.Bundle
import it.fabiodalez.incitta.MainActivity

/** Exported for WhatsApp; a matching unexpired session-bound nonce is mandatory. */
class WhatsappCodeActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (intent != null && WhatsappAutofill.receive(this, intent)) startActivity(whatsappForwardIntent(this))
        // Theme.NoDisplay requires finish() before onResume, whatever the outcome.
        finish()
    }
}

/** WhatsApp starts us outside the app task: NEW_TASK is required, CLEAR_TOP/SINGLE_TOP reuse the running screen. */
internal fun whatsappForwardIntent(context: Context): Intent = Intent(context, MainActivity::class.java)
    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
