package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.editorial.EditorialActivityRow
import it.fabiodalez.incitta.ui.EventDetailScreen
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.ZoneId

/**
 * La cronologia delle modifiche di un evento (R-EV-25): dalla più recente,
 * con data, tipo, autore e «campo: prima → dopo». Sulle righe «Modificato»
 * con campi di contenuto c'è «Ripristina i valori di prima»; stato,
 * pubblicazione ed evidenza non si riavvolgono da qui.
 */
@Composable
internal fun ActivityScreen(viewModel: EventActivityViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit) {
    val state by viewModel.list.current.collectAsStateWithLifecycle()
    val action by viewModel.action.collectAsStateWithLifecycle()
    var confirm by remember { mutableStateOf<EditorialActivityRow?>(null) }
    ActivityContent(state, padding, zone, action.busy, action.error ?: action.message, action.error != null,
        onBack = onBack, onLoadMore = viewModel.list::loadMore, onRetry = viewModel.list::retry,
        onRestore = { confirm = it }, onDismissMessage = viewModel::consumeMessage)
    confirm?.let { row ->
        ConfirmDialog(
            title = stringResource(R.string.editorial_activity_restore_title),
            text = stringResource(R.string.editorial_activity_restore_text, row.restorableFields.joinToString(", ")),
            confirmLabel = stringResource(R.string.editorial_activity_restore),
            onConfirm = { confirm = null; viewModel.restore(row) },
            onDismiss = { confirm = null },
        )
    }
}

@Composable
internal fun ActivityContent(
    state: PagedState<EditorialActivityRow>,
    padding: PaddingValues,
    zone: ZoneId,
    busy: Boolean,
    message: String?,
    isError: Boolean,
    onBack: () -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onRestore: (EditorialActivityRow) -> Unit,
    onDismissMessage: () -> Unit,
) {
    PagedListScreen(
        title = stringResource(R.string.editorial_activity_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = {},
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        emptyText = stringResource(R.string.editorial_activity_empty),
        showSearch = false,
        header = {
            if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            message?.let {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(it, color = if (isError) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary, modifier = Modifier.weight(1f))
                    TextButton(onClick = onDismissMessage, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_close)) }
                }
            }
        },
    ) { row ->
        Surface(shape = RoundedCornerShape(10.dp), tonalElevation = 1.dp, modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(row.event.label, fontWeight = FontWeight.SemiBold)
                Text(stringResource(R.string.editorial_activity_by, editorialMoment(row.createdAt, zone).orEmpty(), row.causer), color = Muted, style = MaterialTheme.typography.bodySmall)
                if (row.changes.isNotBlank()) Text(row.changes)
                if (row.canRestore) {
                    OutlinedButton(onClick = { onRestore(row) }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.editorial_activity_restore))
                    }
                }
            }
        }
    }
}

/** L'anteprima della pagina pubblica (R-EV-16, R-WB-01): la scheda evento nativa, anche per una bozza. */
@Composable
internal fun EventPreviewScreen(viewModel: EventPreviewViewModel, padding: PaddingValues, onBack: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val detail: EventDetail? = (state as? Loadable.Ready)?.value
    if (detail != null) {
        Box(Modifier.fillMaxSize().padding(padding)) {
            EventDetailScreen(detail = detail, savedIds = emptySet(), onBack = onBack, onSave = {})
        }
        return
    }
    ManagementScaffold(title = stringResource(R.string.editorial_preview_title), padding = padding, onBack = onBack) {
        LoadState(state, onRetry = viewModel::load) { }
    }
}
