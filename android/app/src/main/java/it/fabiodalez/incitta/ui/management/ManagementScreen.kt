package it.fabiodalez.incitta.ui.management

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementGraph
import it.fabiodalez.incitta.data.management.ManagementLanding
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold

/**
 * L'ingresso dell'area di gestione, con la firma di sempre. `client` serve ai
 * test per mettere un OkHttp finto; `destination` è la schermata chiesta da un
 * avviso o da un collegamento.
 *
 * Prima di mostrare qualcosa decide dove atterrare: con `features.management`
 * acceso e almeno un locale o un'organizzazione si arriva sul soggetto; in
 * tutti gli altri casi (area spenta, server vecchio, rete assente, solo
 * personale d'ingresso) sull'elenco delle date, come fino alla 1.23.0. La
 * decisione resta salvata, così una rotazione non la rifà.
 */
@Composable
internal fun ManagementScreen(
    session: Session?,
    padding: PaddingValues,
    client: ApiClient? = null,
    destination: ManagementDestination? = null,
    onBack: () -> Unit,
) {
    if (session == null) { LaunchedEffect(Unit) { onBack() }; return }
    val context = LocalContext.current
    val repository = remember(session.token, session.user.id, client) { ManagementGraph.repository(context, session, client) }
    var landing by rememberSaveable(session.token) { mutableStateOf<String?>(null) }
    LaunchedEffect(repository) {
        if (landing == null) landing = encodeLanding(repository.landing())
    }
    val decided = landing?.let(::decodeLanding)
    if (decided == null) {
        ManagementScaffold(title = stringResource(R.string.management_title), padding = padding, onBack = onBack) {
            LoadState(Loadable.Loading, onRetry = {}) {}
        }
        return
    }
    if (decided is ManagementLanding.Subject && repository.selectedSubjectKey == null) repository.selectedSubjectKey = decided.key
    ManagementNavHost(repository, session, padding, decided, destination, onBack)
}

/* La decisione salvata come testo: `dates` oppure `subject:venue:12`. */
internal fun encodeLanding(landing: ManagementLanding): String = when (landing) {
    ManagementLanding.TicketingDates -> "dates"
    is ManagementLanding.Subject -> "subject:${landing.key}"
}

internal fun decodeLanding(value: String): ManagementLanding? = when {
    value == "dates" -> ManagementLanding.TicketingDates
    value.startsWith("subject:") -> ManagementLanding.Subject(value.removePrefix("subject:"))
    else -> null
}
