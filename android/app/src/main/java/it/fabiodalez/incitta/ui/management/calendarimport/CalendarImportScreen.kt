package it.fabiodalez.incitta.ui.management.calendarimport

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.CalendarFileException
import it.fabiodalez.incitta.data.management.CalendarImportState
import it.fabiodalez.incitta.data.management.CalendarPreview
import it.fabiodalez.incitta.data.management.CalendarPreviewRow
import it.fabiodalez.incitta.data.management.CalendarSource
import it.fabiodalez.incitta.data.management.CalendarSourceType
import it.fabiodalez.incitta.data.management.readCalendarFile
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter

/** I tipi che il selettore di sistema propone per un foglio di date. */
private val CSV_TYPES = arrayOf("text/csv", "text/comma-separated-values", "text/plain", "application/csv", "application/vnd.ms-excel")

/** Un istante del server nel fuso della città, come la pagina: «05/09/2026 21:30». */
internal fun calendarImportInstant(value: String?): String? = value?.let { raw ->
    runCatching { OffsetDateTime.parse(raw).format(DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm")) }.getOrNull()
}

/**
 * «Calendario collegato» (W-IM-01…07), nell'ordine della pagina: prima lo
 * stato di ciò che è già collegato, poi il modulo, poi l'anteprima, e solo
 * dopo l'anteprima il pulsante che collega.
 */
@Composable
internal fun CalendarImportScreen(viewModel: CalendarImportViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val resolver = LocalContext.current.contentResolver
    var confirmDisconnect by rememberSaveable { mutableStateOf(false) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) viewModel.fileChosen { readCalendarFile(resolver, uri, state.limits.maxFileBytes) }
    }
    ManagementScaffold(
        title = stringResource(R.string.calendar_import_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = stringResource(R.string.calendar_import_lead),
        busy = state.busy,
    ) {
        LoadState(state.load, onRetry = viewModel::load) { loaded ->
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
                state.runResult?.let { result ->
                    val color = if (result.failed) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary
                    Column(Modifier.semantics(mergeDescendants = true) {}) {
                        Text(result.title, style = MaterialTheme.typography.titleMedium, color = color)
                        Text(result.message, color = color)
                    }
                }
                state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                val source = loaded.source
                if (source == null) {
                    Text(stringResource(R.string.calendar_import_none), style = MaterialTheme.typography.titleMedium, color = Muted)
                } else {
                    SourceStatus(source, enabled = !state.busy, onAction = { action ->
                        if (action == CalendarAction.Disconnect) confirmDisconnect = true else viewModel.act(action)
                    })
                }
                HorizontalDivider()
                CalendarForm(state, loaded, viewModel, onPickFile = { picker.launch(CSV_TYPES) })
                state.preview?.let { PreviewSection(it) }
            }
        }
    }
    if (confirmDisconnect) ConfirmDialog(
        title = stringResource(R.string.calendar_import_disconnect_title),
        text = stringResource(R.string.calendar_import_disconnect_confirm),
        confirmLabel = stringResource(R.string.calendar_import_disconnect),
        destructive = true,
        onConfirm = { confirmDisconnect = false; viewModel.act(CalendarAction.Disconnect) },
        onDismiss = { confirmDisconnect = false },
    )
}

/** Il riquadro «Stato del collegamento» (W-IM-01) con le azioni della testata della pagina (W-IM-05…07). */
@Composable
private fun SourceStatus(source: CalendarSource, enabled: Boolean, onAction: (CalendarAction) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(stringResource(R.string.calendar_import_status_heading), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        StatusLine(
            stringResource(R.string.calendar_import_status_state),
            stringResource(if (source.isActive) R.string.calendar_import_status_active else R.string.calendar_import_status_suspended),
            highlight = source.isActive,
        )
        StatusLine(stringResource(R.string.calendar_import_status_last_run), calendarImportInstant(source.lastRunAt) ?: stringResource(R.string.calendar_import_status_never))
        StatusLine(stringResource(R.string.calendar_import_status_outcome), source.lastStatusLabel?.takeIf { it.isNotBlank() } ?: "—")
        StatusLine(
            stringResource(R.string.calendar_import_status_address),
            if (source.isUpload) stringResource(R.string.calendar_import_status_uploaded) else source.address,
        )
        source.lastError?.takeIf { it.isNotBlank() }?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (source.canRun) OutlinedButton(enabled = enabled, onClick = { onAction(CalendarAction.Run) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.calendar_import_run_now))
            }
            if (source.canUpdate) OutlinedButton(
                enabled = enabled,
                onClick = { onAction(if (source.isActive) CalendarAction.Pause else CalendarAction.Resume) },
                modifier = Modifier.heightIn(min = 48.dp),
            ) {
                Text(stringResource(if (source.isActive) R.string.calendar_import_suspend else R.string.calendar_import_resume))
            }
            if (source.canDelete) OutlinedButton(enabled = enabled, onClick = { onAction(CalendarAction.Disconnect) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.calendar_import_disconnect), color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

@Composable
private fun StatusLine(label: String, value: String, highlight: Boolean = false) {
    Column(Modifier.fillMaxWidth().semantics(mergeDescendants = true) {}) {
        Text(label, style = MaterialTheme.typography.labelLarge, color = Muted)
        Text(value, color = if (highlight) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
    }
}

/** Il modulo (W-IM-02), «Anteprima» (W-IM-03) e «Collega» / «Aggiorna» (W-IM-04). */
@Composable
private fun CalendarForm(state: CalendarImportUiState, loaded: CalendarImportState, viewModel: CalendarImportViewModel, onPickFile: () -> Unit) {
    val errors = state.errors
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.calendar_import_form_heading), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        Text(stringResource(R.string.calendar_import_form_help), color = Muted)
        errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (errors.general == null && errors.fields.isNotEmpty()) Text(stringResource(R.string.management_form_fix), color = MaterialTheme.colorScheme.error)

        Text(stringResource(R.string.calendar_import_form_type), style = MaterialTheme.typography.labelLarge)
        Column(Modifier.selectableGroup()) {
            CalendarSourceType.entries.forEach { type ->
                val selected = state.type == type
                Row(
                    Modifier.fillMaxWidth().heightIn(min = 48.dp)
                        .selectable(selected = selected, enabled = !state.busy, role = Role.RadioButton, onClick = { viewModel.setType(type) }),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    RadioButton(selected = selected, onClick = null, enabled = !state.busy)
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(if (type == CalendarSourceType.Ics) R.string.calendar_import_form_type_ics else R.string.calendar_import_form_type_csv))
                }
            }
        }
        errors.field("type")?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        FormTextField(
            value = state.url,
            onValueChange = viewModel::setUrl,
            label = stringResource(R.string.calendar_import_form_url),
            errors = errors,
            field = "url",
            singleLine = true,
            enabled = !state.busy,
            help = stringResource(if (state.type == CalendarSourceType.Csv) R.string.calendar_import_form_csv_help else R.string.calendar_import_form_url_help),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Done),
        )

        if (state.type == CalendarSourceType.Csv) FileField(state, viewModel, onPickFile)

        KeywordsField(state, viewModel)

        Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxWidth()) {
            OutlinedButton(enabled = !state.busy, onClick = viewModel::preview, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                Text(stringResource(R.string.calendar_import_preview))
            }
            Button(enabled = !state.busy && state.previewed, onClick = viewModel::connect, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                Text(stringResource(if (loaded.source != null) R.string.calendar_import_update else R.string.calendar_import_connect))
            }
        }
        if (!state.previewed) Text(stringResource(R.string.calendar_import_preview_required), color = Muted)
    }
}

/** Il CSV dal selettore di sistema: il nome scelto, l'errore del file o del server, e come cambiarlo. */
@Composable
private fun FileField(state: CalendarImportUiState, viewModel: CalendarImportViewModel, onPickFile: () -> Unit) {
    val maxMb = (state.limits.maxFileBytes / (1024 * 1024)).toInt()
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.calendar_import_form_file), style = MaterialTheme.typography.labelLarge)
        state.fileName?.let { Text(stringResource(R.string.calendar_import_form_file_chosen, it)) }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(enabled = !state.busy, onClick = onPickFile, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (state.fileName == null) R.string.calendar_import_form_file_choose else R.string.calendar_import_form_file_change))
            }
            if (state.fileName != null) TextButton(enabled = !state.busy, onClick = viewModel::clearFile, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.calendar_import_form_file_remove))
            }
        }
        val fileError = when (state.fileError) {
            CalendarFileException.Reason.Unreadable -> stringResource(R.string.calendar_import_form_file_unreadable)
            CalendarFileException.Reason.TooLarge -> stringResource(R.string.calendar_import_form_file_too_large, maxMb)
            CalendarFileException.Reason.Empty -> stringResource(R.string.calendar_import_form_file_empty)
            null -> state.errors.field("file")
        }
        fileError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(stringResource(R.string.calendar_import_form_file_help), color = Muted, style = MaterialTheme.typography.bodySmall)
    }
}

/** Le parole escluse come etichette che si tolgono, e il campo per aggiungerne. */
@Composable
private fun KeywordsField(state: CalendarImportUiState, viewModel: CalendarImportViewModel) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.calendar_import_form_exclude), style = MaterialTheme.typography.labelLarge)
        if (state.keywords.isEmpty()) Text(stringResource(R.string.calendar_import_form_exclude_none), color = Muted)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            state.keywords.forEach { word ->
                val remove = stringResource(R.string.calendar_import_form_exclude_remove, word)
                InputChip(
                    selected = false,
                    enabled = !state.busy,
                    onClick = { viewModel.removeKeyword(word) },
                    label = { Text(word) },
                    trailingIcon = { Icon(Icons.Outlined.Close, contentDescription = null, modifier = Modifier.size(18.dp)) },
                    modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = remove },
                )
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(
                value = state.keywordInput,
                onValueChange = viewModel::setKeywordInput,
                label = { Text(stringResource(R.string.calendar_import_form_exclude_placeholder)) },
                singleLine = true,
                enabled = !state.busy,
                isError = state.errors.field("exclude_keywords") != null,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { if (state.canAddKeyword) viewModel.addKeyword() }),
                modifier = Modifier.weight(1f),
            )
            OutlinedButton(enabled = state.canAddKeyword && !state.busy, onClick = viewModel::addKeyword, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.calendar_import_form_exclude_add))
            }
        }
        state.errors.field("exclude_keywords")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(stringResource(R.string.calendar_import_form_exclude_help), color = Muted, style = MaterialTheme.typography.bodySmall)
    }
}

/** «Cosa verrà pubblicato»: l'errore del calendario, il vuoto, o le prime date con le loro note. */
@Composable
private fun PreviewSection(preview: CalendarPreview) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        HorizontalDivider()
        Text(stringResource(R.string.calendar_import_preview_heading), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        Text(stringResource(R.string.calendar_import_preview_description), color = Muted)
        when {
            preview.error != null -> Text(preview.error, color = MaterialTheme.colorScheme.error)
            preview.rows.isEmpty() -> Text(stringResource(R.string.calendar_import_preview_empty), color = Muted)
            else -> preview.rows.forEach { PreviewRow(it) }
        }
    }
}

@Composable
private fun PreviewRow(row: CalendarPreviewRow) {
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp).semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(row.`when`, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
        Text(row.title, style = MaterialTheme.typography.titleMedium)
        val notes = row.notes + if (row.cancelled) listOf(stringResource(R.string.calendar_import_preview_cancelled)) else emptyList()
        if (notes.isNotEmpty()) Text(notes.joinToString(" · "), color = if (row.cancelled) MaterialTheme.colorScheme.error else Muted)
        Text(row.where?.takeIf { it.isNotBlank() } ?: stringResource(R.string.calendar_import_preview_where_none), color = Muted)
    }
    HorizontalDivider()
}
