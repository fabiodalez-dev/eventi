package it.fabiodalez.incitta.community

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import it.fabiodalez.incitta.MainActivity

/** Exported for WhatsApp; a matching unexpired session-bound nonce is mandatory. */
class WhatsappCodeActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (intent != null && WhatsappAutofill.receive(this, intent)) {
            startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP))
        }
        finish()
    }
}
