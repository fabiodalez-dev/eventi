package it.fabiodalez.incitta.ui

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.os.Bundle
import android.view.Window
import android.view.WindowManager
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.platform.LocalContext
import com.journeyapps.barcodescanner.CaptureActivity
import java.util.WeakHashMap

/*
 * Quante parti della schermata chiedono la finestra protetta. Due QR aperti
 * insieme sono normali (una prenotazione con più partecipanti): chiudere il
 * primo non deve togliere la protezione al secondo.
 */
private val secureHolders = WeakHashMap<Window, Int>()

/**
 * Finché è nella composizione la finestra resta in FLAG_SECURE: niente
 * screenshot, niente registrazione dello schermo e un'anteprima vuota tra le
 * app recenti. Serve dove si vede un QR d'ingresso o l'elenco del check-in,
 * che chiunque potrebbe riusare copiandolo da una foto dello schermo.
 * All'uscita il flag si toglie, così il resto dell'app resta fotografabile.
 */
@Composable
internal fun SecureWindow() {
    val context = LocalContext.current
    DisposableEffect(context) {
        val window = context.findActivity()?.window
        if (window != null) {
            val holders = secureHolders[window] ?: 0
            if (holders == 0) window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
            secureHolders[window] = holders + 1
        }
        onDispose {
            if (window != null) {
                val remaining = (secureHolders[window] ?: 1) - 1
                if (remaining <= 0) {
                    secureHolders.remove(window)
                    window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                } else {
                    secureHolders[window] = remaining
                }
            }
        }
    }
}

internal tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

/**
 * Lo scanner del check-in è un'attività della libreria, fuori dalla finestra
 * dell'app: la protezione va messa anche lì, altrimenti l'anteprima della
 * fotocamera con il QR inquadrato finisce tra le app recenti.
 */
class SecureCaptureActivity : CaptureActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        super.onCreate(savedInstanceState)
    }
}
