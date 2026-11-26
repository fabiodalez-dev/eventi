package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.MergeCandidate
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog

/**
 * «Unisci con un doppione» (R-EV-14): si cerca per titolo fra gli eventi
 * della stessa città (il server dà i primi venti, come il modulo del
 * pannello), si sceglie quello da assorbire e si conferma. È un'azione
 * pericolosa: il server chiede la password recente, e l'area la chiede da sé.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MergeEventSheet(
    candidates: List<MergeCandidate>,
    busy: Boolean,
    onSearch: (String) -> Unit,
    onMerge: (Long) -> Unit,
    onDismiss: () -> Unit,
) {
    var query by rememberSaveable { mutableStateOf("") }
    var chosen by remember { mutableStateOf<MergeCandidate?>(null) }
    LaunchedEffect(query) { onSearch(query) }
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text(stringResource(R.string.editorial_events_merge_title), style = MaterialTheme.typography.titleLarge)
            Text(stringResource(R.string.editorial_events_merge_text), color = Muted)
            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                label = { Text(stringResource(R.string.editorial_events_merge_search)) },
                singleLine = true,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )
            if (candidates.isEmpty() && query.isNotBlank()) Text(stringResource(R.string.editorial_events_merge_none), color = Muted)
            candidates.forEach { candidate ->
                OutlinedButton(onClick = { chosen = candidate }, enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(candidate.label.ifBlank { candidate.title }, Modifier.weight(1f))
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
    chosen?.let { candidate ->
        ConfirmDialog(
            title = stringResource(R.string.editorial_events_merge_confirm_title, candidate.title),
            text = stringResource(R.string.editorial_events_merge_confirm_text),
            confirmLabel = stringResource(R.string.editorial_events_merge_confirm),
            onConfirm = { chosen = null; onMerge(candidate.id) },
            onDismiss = { chosen = null },
            destructive = true,
            enabled = !busy,
        )
    }
}
