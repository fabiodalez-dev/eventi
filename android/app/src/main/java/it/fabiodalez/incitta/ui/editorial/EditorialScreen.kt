package it.fabiodalez.incitta.ui.editorial

import android.widget.Toast
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.editorial.EditorialGraph
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.SecureWindow
import it.fabiodalez.incitta.ui.editorial.common.UnlockGate
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import kotlinx.coroutines.CancellationException

/**
 * L'ingresso dell'area «Redazione», dalla riga «Amministrazione» del
 * profilo. `client` serve ai test per mettere un OkHttp finto.
 *
 * - Tutta l'area è in `FLAG_SECURE` (niente schermate, anteprima vuota fra
 *   le app recenti): ci sono dati personali di tutti gli iscritti.
 * - Prima chiede `features.editorial`: spento (interruttore d'emergenza o
 *   server vecchio) apre il pannello sul sito ed esce, come prima.
 * - Poi la porta (`UnlockGate`): blocco del telefono, password
 *   dell'account se il server non è sbloccato, blocco dopo 15 minuti.
 */
@Composable
internal fun EditorialScreen(
    session: Session?,
    padding: PaddingValues,
    client: ApiClient? = null,
    /* Apre nell'app un indirizzo del sito che l'app sa mostrare (la biglietteria di una data); senza, il browser. */
    onOpenInApp: ((String) -> Unit)? = null,
    onBack: () -> Unit,
) {
    if (session == null) { LaunchedEffect(Unit) { onBack() }; return }
    SecureWindow()
    val context = LocalContext.current
    val uriHandler = LocalUriHandler.current
    val repository = remember(session.token, session.user.id, client) { EditorialGraph.repository(context, session, client) }
    /* La decisione resta salvata, così una rotazione non richiama `/config`. */
    var enabled by rememberSaveable(session.token) { mutableStateOf<Boolean?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    val disabledText = stringResource(R.string.editorial_disabled)
    LaunchedEffect(repository, attempt) {
        if (enabled != null) return@LaunchedEffect
        failure = null
        try {
            enabled = repository.enabled()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            failure = requestFailureMessage(e)
        }
    }
    LaunchedEffect(enabled) {
        if (enabled == false) {
            Toast.makeText(context, disabledText, Toast.LENGTH_SHORT).show()
            runCatching { uriHandler.openUri(editorialWebUrl("admin")) }
            onBack()
        }
    }
    if (enabled != true) {
        ManagementScaffold(title = stringResource(R.string.editorial_title), padding = padding, onBack = onBack) {
            LoadState(failure?.let { Loadable.Failed(it) } ?: Loadable.Loading, onRetry = { attempt += 1 }) {}
        }
        return
    }
    UnlockGate(repository, onLockedBack = onBack) {
        EditorialNavHost(repository, session, padding, onBack, onOpenInApp)
    }
}
