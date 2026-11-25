package it.fabiodalez.incitta.ui.management.events

import androidx.compose.foundation.layout.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.EventDetailScreen
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold

/**
 * «Anteprima della pagina» (W-EV-23): la scheda evento nativa che vede il
 * pubblico, con i dati dell'evento anche se non è ancora pubblicato. Non si
 * può salvare fra i preferiti: non è ancora una pagina pubblica.
 */
@Composable
internal fun EventPreviewScreen(viewModel: EventPreviewViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val detail = (state as? Loadable.Ready)?.value
    if (detail != null) {
        Box(Modifier.fillMaxSize().padding(context.padding)) {
            EventDetailScreen(detail = detail, savedIds = emptySet(), onBack = context::back, onSave = {})
        }
        return
    }
    ManagementScaffold(title = stringResource(R.string.management_events_preview), padding = context.padding, onBack = context::back) {
        LoadState(state, onRetry = viewModel::load) { }
    }
}
