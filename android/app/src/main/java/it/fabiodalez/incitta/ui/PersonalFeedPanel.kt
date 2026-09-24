package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException

@Composable
internal fun PersonalFeedPanel(state: AppUiState, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit, onAll: () -> Unit) {
    val token = state.session?.token ?: return
    var visible by remember(token) { mutableStateOf(true) }
    if (!visible) return
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    var dates by remember(token) { mutableStateOf(emptyList<Occurrence>()) }
    var error by remember(token) { mutableStateOf<String?>(null) }
    var retry by remember(token) { mutableIntStateOf(0) }
    LaunchedEffect(token, retry, state.isLoading) {
        try {
            dates = api.get<ApiEnvelope<List<Occurrence>>>("me/feed?limit=3", token).data
            error = null
        } catch (e: Exception) { if (e is CancellationException) throw e; error = requestFailureMessage(e) }
    }
    if (dates.isEmpty() && error == null) return
    Column(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.feed_for_you), Modifier.padding(horizontal = 18.dp), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.feed_rules), Modifier.padding(horizontal = 18.dp), color = Muted)
        error?.let { Text(it, Modifier.padding(horizontal = 18.dp)); TextButton(onClick = { retry++ }) { Text(stringResource(R.string.feed_retry)) } }
        dates.forEach { EventRow(it, it.occurrenceId in state.savedIds, onOpen, onSave) }
        TextButton(onClick = { visible = false; onAll() }) { Text(stringResource(R.string.feed_show_all)) }
    }
}
