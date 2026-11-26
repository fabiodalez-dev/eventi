package it.fabiodalez.incitta.ui.editorial.social

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.SocialPublicationRow
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.events.StatusBadge
import it.fabiodalez.incitta.ui.management.social.socialMoment
import java.time.ZoneId

/*
 * Lo storico delle pubblicazioni della città, come la sezione «Storico
 * pubblicazioni» della pagina «Social»: quando, piattaforma, parte, stato,
 * errore, la nota del pacchetto tolto dalla pulizia, e le azioni della riga
 * (riprogramma e annulla per quelle programmate, riprova per quelle non
 * riuscite o incerte, con la conferma della pagina).
 */
@Composable
internal fun SocialPublicationsScreen(
    viewModel: SocialPublicationsViewModel,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onOpenBatch: (String) -> Unit,
) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val actions = listOf(
        RowAction("reschedule", stringResource(R.string.editorial_social_reschedule)),
        RowAction("cancel", stringResource(R.string.editorial_social_cancel_schedule), destructive = true),
        RowAction("retry", stringResource(R.string.editorial_social_retry)),
    )
    val statusFilter = FilterDefinition(
        key = "status",
        label = stringResource(R.string.editorial_social_filter_status),
        options = SocialPublicationsViewModel.STATUSES.map { OptionChoice(it, stringResource(publicationStatusLabel(it))) },
    )
    PagedListScreen(
        title = stringResource(R.string.editorial_social_history),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = { },
        onLoadMore = viewModel.list::loadMore,
        onRetry = viewModel.list::retry,
        rowKey = SocialPublicationRow::id,
        filters = listOf(statusFilter),
        onFilters = viewModel::applyFilters,
        searchable = false,
        emptyText = stringResource(R.string.editorial_social_history_empty),
        header = {
            state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
            state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
        },
    ) { row ->
        PublicationRow(row, zone, busy = state.busyId == row.id, actions = actions, onAction = { action ->
            when (action.key) {
                "reschedule" -> viewModel.startReschedule(row, cityLocalDateTime(row.scheduledAt, socialZone(row.timezone) ?: zone))
                "cancel" -> viewModel.cancel(row)
                "retry" -> viewModel.askRetry(row)
            }
        }, onOpenBatch = onOpenBatch)
    }
    state.reschedule?.let { draft ->
        val rowZone = socialZone(draft.row.timezone) ?: zone
        AlertDialog(
            onDismissRequest = viewModel::dismissReschedule,
            title = { Text(stringResource(R.string.editorial_social_reschedule_title)) },
            text = {
                CityDateTimeField(
                    label = stringResource(R.string.editorial_social_schedule_when),
                    value = draft.value,
                    zone = rowZone,
                    cityName = null,
                    onChange = viewModel::setReschedule,
                )
            },
            confirmButton = {
                TextButton(enabled = draft.value != null, onClick = viewModel::confirmReschedule, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_social_reschedule))
                }
            },
            dismissButton = {
                TextButton(onClick = viewModel::dismissReschedule, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_cancel)) }
            },
        )
    }
    state.retryConfirm?.let {
        ConfirmDialog(
            title = stringResource(R.string.editorial_social_retry_title),
            text = stringResource(R.string.editorial_social_retry_confirm),
            confirmLabel = stringResource(R.string.editorial_social_retry),
            onConfirm = viewModel::confirmRetry,
            onDismiss = viewModel::dismissRetry,
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PublicationRow(
    row: SocialPublicationRow,
    zone: ZoneId,
    busy: Boolean,
    actions: List<RowAction>,
    onAction: (RowAction) -> Unit,
    onOpenBatch: (String) -> Unit,
) {
    val rowZone = socialZone(row.timezone) ?: zone
    val title = stringResource(R.string.editorial_social_row_title, row.platformLabel.ifBlank { row.platform }, row.part)
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(title, style = MaterialTheme.typography.titleMedium)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) { StatusBadge(row.status.label) }
                }
                if (!busy) ActionMenu(actions, abilitiesOf(row.abilities), onAction, subject = title)
            }
            if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            row.createdAt?.let { iso -> socialMoment(iso, rowZone)?.let { Text(stringResource(R.string.editorial_social_row_created, it), color = Muted) } }
            row.scheduledAt?.let { iso -> socialMoment(iso, rowZone)?.let { Text(stringResource(R.string.editorial_social_row_expected, it)) } }
            row.error?.takeIf(String::isNotBlank)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (row.status.value == "uncertain") Text(stringResource(R.string.editorial_social_uncertain_help), color = Muted)
            if (row.batchGone) Text(stringResource(R.string.editorial_social_batch_gone), color = Muted)
            row.batchId?.let { id ->
                TextButton(onClick = { onOpenBatch(id) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_open_batch)) }
            }
        }
    }
}

/** L'etichetta di uno stato per il filtro (le righe hanno quella del server). */
internal fun publicationStatusLabel(status: String): Int = when (status) {
    "scheduled" -> R.string.editorial_social_status_scheduled
    "cancelled" -> R.string.editorial_social_status_cancelled
    "queued" -> R.string.editorial_social_status_queued
    "processing" -> R.string.editorial_social_status_processing
    "published" -> R.string.editorial_social_status_published
    "failed" -> R.string.editorial_social_status_failed
    else -> R.string.editorial_social_status_uncertain
}
