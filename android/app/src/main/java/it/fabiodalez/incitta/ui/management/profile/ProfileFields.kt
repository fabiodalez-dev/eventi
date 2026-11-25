package it.fabiodalez.incitta.ui.management.profile

import android.content.ClipData
import android.content.Intent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.ClipEntry
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.data.management.formatHour
import it.fabiodalez.incitta.data.management.parseDay
import it.fabiodalez.incitta.data.management.parseHour
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.pickerDate
import it.fabiodalez.incitta.ui.management.common.pickerMillis
import java.time.LocalDate
import java.time.LocalTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.launch

/*
 * I campi dei moduli del profilo: scelta da un elenco, sì/no/non dichiarato,
 * righe ripetute con sposta e togli, giorno e ora, copia e condividi. Il
 * testo formattato è il `RichTextField` comune di `ui/management/common/`.
 * Pulsanti da 48 dp, ogni icona con il suo nome.
 */

internal fun List<ValueLabel>.asChoices(): List<OptionChoice> = map { OptionChoice(it.value, it.label) }

/** Una scelta da un elenco chiuso; `emptyLabel` aggiunge la voce «non specificato». */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ChoiceField(
    label: String,
    options: List<OptionChoice>,
    selected: String,
    onSelect: (String) -> Unit,
    error: String? = null,
    emptyLabel: String? = null,
    modifier: Modifier = Modifier.fillMaxWidth(),
) {
    var open by remember { mutableStateOf(false) }
    val shown = options.firstOrNull { it.value == selected }?.label ?: if (selected.isBlank()) emptyLabel.orEmpty() else selected
    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }, modifier = modifier) {
        OutlinedTextField(
            value = shown,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            isError = error != null,
            supportingText = error?.let { { Text(it) } },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(open) },
            modifier = Modifier.fillMaxWidth().menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            emptyLabel?.let { DropdownMenuItem(text = { Text(it) }, onClick = { open = false; onSelect("") }, modifier = Modifier.heightIn(min = 48.dp)) }
            options.forEach { option ->
                DropdownMenuItem(text = { Text(option.label) }, onClick = { open = false; onSelect(option.value) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
    }
}

/** Una scelta da un elenco lungo (i 101 comuni della provincia): si scrive per filtrare. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SearchableChoiceField(label: String, options: List<String>, selected: String, onSelect: (String) -> Unit, error: String? = null) {
    var open by remember { mutableStateOf(false) }
    var query by remember(selected) { mutableStateOf(selected) }
    val filtered = remember(query, options) {
        val needle = query.trim().lowercase(Locale.ITALIAN)
        if (needle.isEmpty() || needle == selected.lowercase(Locale.ITALIAN)) options else options.filter { it.lowercase(Locale.ITALIAN).contains(needle) }
    }
    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
        OutlinedTextField(
            value = query,
            onValueChange = { query = it; open = true },
            label = { Text(label) },
            singleLine = true,
            isError = error != null,
            supportingText = error?.let { { Text(it) } },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(open) },
            modifier = Modifier.fillMaxWidth().menuAnchor(ExposedDropdownMenuAnchorType.PrimaryEditable),
        )
        ExposedDropdownMenu(expanded = open && filtered.isNotEmpty(), onDismissRequest = { open = false; query = selected }) {
            filtered.take(60).forEach { option ->
                DropdownMenuItem(text = { Text(option) }, onClick = { open = false; query = option; onSelect(option) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
    }
}

/** Tre scelte a pulsanti: sì, no, non dichiarato. `null` è «non dichiarato». */
@Composable
internal fun TriStateField(label: String, value: Boolean?, onChange: (Boolean?) -> Unit, yes: String = stringResource(R.string.profile_yes), no: String = stringResource(R.string.profile_no), unknown: String = stringResource(R.string.profile_undeclared)) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(value == true, { onChange(true) }, label = { Text(yes) }, modifier = Modifier.heightIn(min = 48.dp))
            FilterChip(value == false, { onChange(false) }, label = { Text(no) }, modifier = Modifier.heightIn(min = 48.dp))
            FilterChip(value == null, { onChange(null) }, label = { Text(unknown) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
}

/** La stessa scelta per i campi che il server salva come `yes`/`no` (vuoto = non specificato). */
@Composable
internal fun YesNoField(label: String, value: String, onChange: (String) -> Unit, yes: String = stringResource(R.string.profile_yes), no: String = stringResource(R.string.profile_no)) {
    TriStateField(label, when (value) { "yes" -> true; "no" -> false; else -> null }, { onChange(when (it) { true -> "yes"; false -> "no"; null -> "" }) }, yes, no, stringResource(R.string.profile_unspecified))
}

/** Intestazione di una riga ripetuta con sposta su, sposta giù e togli. */
@Composable
internal fun RowHeader(title: String, index: Int, count: Int, onMove: (Int) -> Unit, onRemove: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(title, style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
        IconButton(enabled = index > 0, onClick = { onMove(-1) }, modifier = Modifier.size(48.dp)) {
            Icon(Icons.Outlined.ArrowUpward, contentDescription = stringResource(R.string.profile_row_up, title))
        }
        IconButton(enabled = index < count - 1, onClick = { onMove(1) }, modifier = Modifier.size(48.dp)) {
            Icon(Icons.Outlined.ArrowDownward, contentDescription = stringResource(R.string.profile_row_down, title))
        }
        IconButton(onClick = onRemove, modifier = Modifier.size(48.dp)) {
            Icon(Icons.Outlined.Delete, contentDescription = stringResource(R.string.profile_row_remove, title))
        }
    }
}

/** «Aggiungi …», spento oltre il tetto con la frase che lo dice. */
@Composable
internal fun AddRowButton(label: String, count: Int, max: Int, onAdd: () -> Unit) {
    OutlinedButton(enabled = count < max, onClick = onAdd, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Icon(Icons.Outlined.Add, contentDescription = null)
        Spacer(Modifier.width(8.dp))
        Text(label)
    }
    if (count >= max) Text(stringResource(R.string.profile_rows_max, max), color = Muted)
}

/** Sposta l'elemento `index` di `delta` posti; fuori dai bordi resta com'è. */
internal fun <T> List<T>.moved(index: Int, delta: Int): List<T> {
    val to = index + delta
    if (index !in indices || to !in indices) return this
    return toMutableList().apply { add(to, removeAt(index)) }
}

internal fun <T> List<T>.replaced(index: Int, value: T): List<T> = toMutableList().apply { if (index in indices) this[index] = value }
internal fun <T> List<T>.without(index: Int): List<T> = filterIndexed { i, _ -> i != index }

private val dayFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.ITALIAN)

/** Un giorno di calendario (`yyyy-MM-dd`): niente fuso, è un giorno e basta. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun DayField(label: String, value: String, onChange: (String) -> Unit, error: String? = null, clearLabel: String? = null, emptyText: String = stringResource(R.string.profile_pick_day), minDate: LocalDate? = null) {
    var open by remember { mutableStateOf(false) }
    val date = parseDay(value)
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(date?.format(dayFormat) ?: emptyText)
        }
        if (clearLabel != null && date != null) TextButton(onClick = { onChange("") }, modifier = Modifier.heightIn(min = 48.dp)) { Text(clearLabel) }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
    if (open) {
        val state = rememberDatePickerState(
            initialSelectedDateMillis = (date ?: minDate ?: LocalDate.now()).let(::pickerMillis),
            selectableDates = object : SelectableDates {
                override fun isSelectableDate(utcTimeMillis: Long): Boolean = minDate == null || !pickerDate(utcTimeMillis).isBefore(minDate)
            },
        )
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = { open = false; state.selectedDateMillis?.let { onChange(pickerDate(it).toString()) } }) { Text(stringResource(R.string.management_datetime_done)) }
            },
            dismissButton = { TextButton(onClick = { open = false }) { Text(stringResource(R.string.management_cancel)) } },
        ) { DatePicker(state = state) }
    }
}

/** Un'ora `HH:mm` con il selettore a 24 ore. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun HourField(label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier) {
    var open by remember { mutableStateOf(false) }
    val time = parseHour(value)
    Column(modifier, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(time?.let(::formatHour) ?: stringResource(R.string.profile_pick_time))
        }
    }
    if (open) {
        val initial = time ?: LocalTime.of(18, 0)
        val state = rememberTimePickerState(initialHour = initial.hour, initialMinute = initial.minute, is24Hour = true)
        AlertDialog(
            onDismissRequest = { open = false },
            title = { Text(label) },
            text = { TimePicker(state = state) },
            confirmButton = { TextButton(onClick = { open = false; onChange(formatHour(LocalTime.of(state.hour, state.minute))) }) { Text(stringResource(R.string.management_datetime_done)) } },
            dismissButton = { TextButton(onClick = { open = false }) { Text(stringResource(R.string.management_cancel)) } },
        )
    }
}

/** Un testo con il contatore dei caratteri sotto, che diventa errore oltre il tetto. */
@Composable
internal fun CountedTextField(value: String, onValueChange: (String) -> Unit, label: String, max: Int, errors: FormErrors, field: String, help: String? = null, minLines: Int = 1, singleLine: Boolean = false, keyboardType: KeyboardType = KeyboardType.Text) {
    val error = errors.field(field)
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        isError = error != null || value.length > max,
        singleLine = singleLine,
        minLines = minLines,
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
        supportingText = {
            Column {
                (error ?: help)?.let { Text(it) }
                Text(stringResource(R.string.profile_counter, value.length, max))
            }
        },
        modifier = Modifier.fillMaxWidth(),
    )
}

/** Un indirizzo o un codice con «Copia» e «Condividi» (e «Apri» per gli indirizzi). */
@Composable
internal fun CopyShareBlock(title: String, help: String, value: String, openable: Boolean, onCopied: () -> Unit) {
    val context = LocalContext.current
    val clipboard = LocalClipboard.current
    val scope = rememberCoroutineScope()
    val uriHandler = androidx.compose.ui.platform.LocalUriHandler.current
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(title, style = MaterialTheme.typography.titleMedium)
        Text(help, color = Muted)
        SelectionContainerText(value)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            val copyLabel = stringResource(R.string.profile_copy_label, title)
            val shareLabel = stringResource(R.string.profile_share_label, title)
            OutlinedButton(
                onClick = { scope.launch { clipboard.setClipEntry(ClipEntry(ClipData.newPlainText(title, value))) }; onCopied() },
                modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = copyLabel },
            ) { Icon(Icons.Outlined.ContentCopy, contentDescription = null); Spacer(Modifier.width(6.dp)); Text(stringResource(R.string.profile_copy)) }
            OutlinedButton(
                onClick = {
                    val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, value).putExtra(Intent.EXTRA_SUBJECT, title)
                    context.startActivity(Intent.createChooser(send, title))
                },
                modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = shareLabel },
            ) { Icon(Icons.Outlined.Share, contentDescription = null); Spacer(Modifier.width(6.dp)); Text(stringResource(R.string.profile_share)) }
            if (openable) TextButton(onClick = { uriHandler.openUri(value) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.profile_open)) }
        }
    }
}

@Composable
private fun SelectionContainerText(value: String) {
    androidx.compose.foundation.text.selection.SelectionContainer {
        Surface(tonalElevation = 2.dp, modifier = Modifier.fillMaxWidth()) {
            Text(value, fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(10.dp))
        }
    }
}
