package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorDate
import it.fabiodalez.incitta.data.editorial.EditorScope
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.events.PlainField
import it.fabiodalez.incitta.ui.management.events.formatEventDate

/*
 * Le date di un evento (R-EV-19…24): la sezione «Date» della pagina
 * «Modifica evento». Ogni riga ha «Modifica» e il menu «Altre azioni»
 * (Sposta, Annulla la data, Tutto esaurito o Rimetti in programma,
 * Elimina) e «Gestisci biglietti» per chi gestisce la biglietteria della
 * data, che si apre nella biglietteria dell'app.
 */

@Composable
internal fun EventDatesScreen(
    viewModel: EventDatesViewModel,
    padding: PaddingValues,
    onBack: () -> Unit,
    onAdd: () -> Unit,
    onEdit: (Long) -> Unit,
    onOpenInApp: (String) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ManagementScaffold(
        title = state.meta.event.title.ifBlank { stringResource(R.string.editorial_dates_title) },
        padding = padding,
        onBack = onBack,
        subtitle = stringResource(R.string.editorial_dates_timezone, state.meta.event.city.ifBlank { state.zone.id }),
        busy = state.busy,
    ) {
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (state.meta.abilities.create) {
            Button(onClick = onAdd, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).testTag("dates-add")) { Text(stringResource(R.string.editorial_dates_add)) }
        }
        LoadState(state.dates, onRetry = viewModel::load, emptyText = stringResource(R.string.editorial_dates_empty)) { dates ->
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (dates.none { it.abilities.update }) Text(stringResource(R.string.editorial_dates_read_only), color = Muted)
                dates.forEach { date -> DateRow(date, state, viewModel, onEdit, onOpenInApp) }
                if (state.canLoadMore) {
                    OutlinedButton(onClick = viewModel::loadMore, enabled = !state.loadingMore, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.editorial_dates_title) + " …")
                    }
                }
            }
        }
    }
    state.gesture?.let { gesture -> GestureDialog(gesture, state.meta.choices.scopes, state.busy, viewModel) }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DateRow(date: EditorDate, state: EventDatesUiState, viewModel: EventDatesViewModel, onEdit: (Long) -> Unit, onOpenInApp: (String) -> Unit) {
    val zone = date.zone
    val moment = date.startsAt?.let { formatEventDate(it, zone) }.orEmpty()
    OutlinedCard(Modifier.fillMaxWidth().testTag("date-row-${date.id}")) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(moment, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            date.venue?.let { Text(it.name, color = Muted) }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                AssistChip(onClick = {}, label = { Text(date.status.label.ifBlank { date.status.value }) })
                if (date.series) AssistChip(onClick = {}, label = { Text(stringResource(R.string.editorial_dates_series)) })
                if (date.isException) AssistChip(onClick = {}, label = { Text(stringResource(R.string.editorial_dates_exception)) })
                if (date.lineupsCount > 0) AssistChip(onClick = {}, label = { Text(stringResource(R.string.editorial_dates_lineups, date.lineupsCount)) })
            }
            date.statusNote?.let { RichText.plain(RichText.toMarkup(it)).takeIf(String::isNotBlank)?.let { note -> Text(note, color = Muted) } }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (date.abilities.update) TextButton(onClick = { onEdit(date.id) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_edit)) }
                date.ticketingPath?.let { path ->
                    TextButton(onClick = { onOpenInApp(path) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_dates_tickets)) }
                }
                androidx.compose.foundation.layout.Spacer(Modifier.weight(1f))
                val abilities = mapOf(
                    "shift" to date.abilities.shift,
                    "cancel" to date.abilities.cancel,
                    "sold_out" to date.abilities.soldOut,
                    "destroy" to date.abilities.destroy,
                )
                ActionMenu(
                    actions = listOf(
                        RowAction("shift", stringResource(R.string.editorial_dates_shift)),
                        RowAction("cancel", stringResource(R.string.editorial_dates_cancel), destructive = true),
                        RowAction("sold_out", stringResource(if (date.status.value == "sold_out") R.string.editorial_dates_back_on_sale else R.string.editorial_dates_sold_out)),
                        RowAction("destroy", stringResource(R.string.editorial_dates_delete), destructive = true),
                    ),
                    abilities = abilities,
                    onAction = { action ->
                        when (action.key) {
                            "shift" -> viewModel.ask(DateGesture.Shift(date))
                            "cancel" -> viewModel.ask(DateGesture.Cancel(date))
                            "sold_out" -> viewModel.ask(DateGesture.SoldOut(date))
                            "destroy" -> viewModel.ask(DateGesture.Delete(date))
                        }
                    },
                    subject = moment,
                )
            }
        }
    }
}

/** Il dialogo di conferma del gesto, con «Applica a» solo per una data di una ricorrenza. */
@Composable
private fun GestureDialog(gesture: DateGesture, scopes: List<EditorScope>, busy: Boolean, viewModel: EventDatesViewModel) {
    val date = gesture.date
    var minutes by rememberSaveable(date.id) { mutableStateOf("") }
    var note by rememberSaveable(date.id) { mutableStateOf("") }
    var scope by rememberSaveable(date.id) { mutableStateOf("single") }
    val title = when (gesture) {
        is DateGesture.Shift -> stringResource(R.string.editorial_dates_shift)
        is DateGesture.Cancel -> stringResource(R.string.editorial_dates_cancel)
        is DateGesture.SoldOut -> stringResource(if (date.status.value == "sold_out") R.string.editorial_dates_back_on_sale else R.string.editorial_dates_sold_out)
        is DateGesture.Delete -> stringResource(R.string.editorial_dates_delete)
    }
    val minutesValue = minutes.trim().toIntOrNull()
    AlertDialog(
        onDismissRequest = viewModel::dismiss,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                date.startsAt?.let { Text(formatEventDate(it, date.zone), color = Muted) }
                when (gesture) {
                    is DateGesture.Shift -> PlainField(minutes, { minutes = it }, stringResource(R.string.editorial_dates_shift_minutes),
                        help = stringResource(R.string.editorial_dates_shift_hint), keyboard = KeyboardType.Number)
                    is DateGesture.Cancel -> RichTextField(note, { note = it }, stringResource(R.string.editorial_dates_cancel_note), minLines = 2, toolbar = false)
                    is DateGesture.SoldOut -> Text(stringResource(R.string.editorial_dates_sold_out_confirm))
                    is DateGesture.Delete -> Text(stringResource(R.string.editorial_dates_delete_confirm))
                }
                if (gesture is DateGesture.Shift || gesture is DateGesture.Cancel) {
                    if (date.series) ScopeChoice(scopes, scope) { scope = it } else Text(stringResource(R.string.editorial_dates_no_series), color = Muted)
                }
            }
        },
        confirmButton = {
            TextButton(
                enabled = !busy && (gesture !is DateGesture.Shift || (minutesValue != null && minutesValue != 0)),
                onClick = {
                    when (gesture) {
                        is DateGesture.Shift -> minutesValue?.let { viewModel.shift(date, it, scope) }
                        is DateGesture.Cancel -> viewModel.cancel(date, RichText.toHtml(note).ifBlank { null }, scope)
                        is DateGesture.SoldOut -> viewModel.toggleSoldOut(date)
                        is DateGesture.Delete -> viewModel.delete(date)
                    }
                },
                modifier = Modifier.heightIn(min = 48.dp).testTag("gesture-confirm"),
            ) { Text(stringResource(R.string.editorial_dates_confirm), color = if (gesture is DateGesture.Delete || gesture is DateGesture.Cancel) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary) }
        },
        dismissButton = { TextButton(onClick = viewModel::dismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_cancel)) } },
    )
}

@Composable
private fun ScopeChoice(scopes: List<EditorScope>, selected: String, onSelect: (String) -> Unit) {
    Column(Modifier.selectableGroup(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(stringResource(R.string.editorial_dates_scope), style = MaterialTheme.typography.titleSmall)
        scopes.forEach { scope ->
            Row(
                Modifier.fillMaxWidth().heightIn(min = 48.dp).selectable(selected = scope.value == selected, role = Role.RadioButton) { onSelect(scope.value) },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                RadioButton(selected = scope.value == selected, onClick = null)
                Column(Modifier.padding(start = 8.dp)) {
                    Text(scope.label)
                    if (scope.help.isNotBlank()) Text(scope.help, color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}
