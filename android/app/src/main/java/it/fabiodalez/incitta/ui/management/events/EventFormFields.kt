package it.fabiodalez.incitta.ui.management.events

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.ExpandLess
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.Muted

/*
 * I pezzi del modulo dell'evento: sezioni richiudibili, scelte da un elenco,
 * scelte a pulsanti, righe ripetibili, ricerca di tag e locali. Ogni
 * pulsante è alto almeno 48 dp e ha un nome leggibile.
 */

/** Una sezione che si apre e si chiude, come le sezioni `collapsed()` del pannello. */
@Composable
internal fun CollapsibleSection(
    title: String,
    hint: String? = null,
    initiallyOpen: Boolean = false,
    content: @Composable ColumnScope.() -> Unit,
) {
    var open by rememberSaveable(title) { mutableStateOf(initiallyOpen) }
    val action = stringResource(if (open) R.string.management_events_section_close else R.string.management_events_section_open, title)
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(
            Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(role = Role.Button, onClickLabel = action) { open = !open }.semantics { contentDescription = action },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
            Icon(if (open) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, contentDescription = null)
        }
        hint?.let { Text(it, color = Muted) }
        if (open) content()
        HorizontalDivider()
    }
}

/** Una scelta da un elenco, con «Nessuno» quando il campo è facoltativo. */
@Composable
internal fun ChoiceField(
    label: String,
    options: List<Pair<String, String>>,
    selected: String?,
    onSelect: (String?) -> Unit,
    error: String? = null,
    allowNone: Boolean = false,
    noneLabel: String = stringResource(R.string.management_events_venue_none),
) {
    var open by remember { mutableStateOf(false) }
    val current = options.firstOrNull { it.first == selected }?.second
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        Box {
            OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(current ?: stringResource(R.string.management_events_choose), modifier = Modifier.weight(1f))
                Icon(Icons.Outlined.ExpandMore, contentDescription = null)
            }
            DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                if (allowNone) DropdownMenuItem(text = { Text(noneLabel) }, onClick = { open = false; onSelect(null) }, modifier = Modifier.heightIn(min = 48.dp))
                options.forEach { (value, text) ->
                    DropdownMenuItem(text = { Text(text) }, onClick = { open = false; onSelect(value) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/** Poche scelte affiancate (tipo di prezzo, sì/no/non so). Toccare la scelta attiva la toglie se `clearable`. */
@Composable
internal fun ChoiceChips(
    label: String,
    options: List<Pair<String, String>>,
    selected: String?,
    onSelect: (String?) -> Unit,
    clearable: Boolean = false,
    error: String? = null,
) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            options.forEach { (value, text) ->
                FilterChip(
                    selected = selected == value,
                    onClick = { onSelect(if (clearable && selected == value) null else value) },
                    label = { Text(text) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/** Più scelte da un elenco (fasce d'età, caratteristiche, giorni della settimana). */
@Composable
internal fun MultiChips(label: String, options: List<Pair<String, String>>, selected: Set<String>, onChange: (Set<String>) -> Unit, help: String? = null) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        help?.let { Text(it, color = Muted) }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            options.forEach { (value, text) ->
                FilterChip(
                    selected = value in selected,
                    onClick = { onChange(if (value in selected) selected - value else selected + value) },
                    label = { Text(text) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
    }
}

/** Un campo di testo semplice con l'errore del server sotto. */
@Composable
internal fun PlainField(
    value: String,
    onChange: (String) -> Unit,
    label: String,
    error: String? = null,
    help: String? = null,
    keyboard: KeyboardType = KeyboardType.Text,
    minLines: Int = 1,
    singleLine: Boolean = minLines == 1,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        isError = error != null,
        supportingText = (error ?: help)?.let { text -> { Text(text) } },
        keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        singleLine = singleLine,
        minLines = minLines,
        modifier = Modifier.fillMaxWidth(),
    )
}

/**
 * Righe ripetibili (link, listino, scheda tecnica, programma, domande): ogni
 * riga con «Sposta su» e «Togli», e il pulsante per aggiungerne una finché
 * non si arriva al tetto.
 */
@Composable
internal fun <T> RepeaterField(
    rows: List<T>,
    onChange: (List<T>) -> Unit,
    addLabel: String,
    max: Int,
    newRow: () -> T,
    error: String? = null,
    row: @Composable ColumnScope.(T, (T) -> Unit) -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        rows.forEachIndexed { index, item ->
            OutlinedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    row(item) { updated -> onChange(rows.toMutableList().also { it[index] = updated }) }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (index > 0) TextButton(onClick = { onChange(rows.toMutableList().also { list -> val moved = list.removeAt(index); list.add(index - 1, moved) }) }, modifier = Modifier.heightIn(min = 48.dp)) {
                            Text(stringResource(R.string.management_events_move_up))
                        }
                        TextButton(onClick = { onChange(rows.toMutableList().also { it.removeAt(index) }) }, modifier = Modifier.heightIn(min = 48.dp)) {
                            Text(stringResource(R.string.management_events_remove_row), color = MaterialTheme.colorScheme.error)
                        }
                    }
                }
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (rows.size < max) OutlinedButton(onClick = { onChange(rows + newRow()) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(addLabel) }
    }
}

/** I tag scelti e la ricerca: si tocca un risultato per aggiungerlo; il locale può creare un tag nuovo. */
@Composable
internal fun TagsField(
    selected: List<NamedRef>,
    results: List<NamedRef>,
    onSearch: (String) -> Unit,
    onChange: (List<NamedRef>) -> Unit,
    onCreate: ((String) -> Unit)?,
    error: String?,
    max: Int,
) {
    var query by rememberSaveable { mutableStateOf("") }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.management_events_field_tags), style = MaterialTheme.typography.titleSmall)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            selected.forEach { tag ->
                val remove = stringResource(R.string.management_events_tag_remove, tag.name)
                InputChip(
                    selected = true,
                    onClick = { onChange(selected - tag) },
                    label = { Text(tag.name) },
                    trailingIcon = { Icon(Icons.Outlined.Close, contentDescription = remove) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
        if (selected.size < max) {
            OutlinedTextField(
                value = query,
                onValueChange = { query = it; if (it.trim().length >= 2) onSearch(it.trim()) },
                label = { Text(stringResource(R.string.management_events_tags_add)) },
                supportingText = { Text(error ?: stringResource(R.string.management_events_tags_hint)) },
                isError = error != null,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            if (query.trim().length >= 2) {
                results.filter { result -> selected.none { it.id == result.id } }.take(8).forEach { result ->
                    TextButton(onClick = { onChange(selected + result); query = "" }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(result.name, modifier = Modifier.fillMaxWidth())
                    }
                }
                if (onCreate != null && results.none { it.name.equals(query.trim(), ignoreCase = true) }) {
                    TextButton(onClick = { onCreate(query.trim()); query = "" }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.management_events_tags_create, query.trim()), modifier = Modifier.fillMaxWidth())
                    }
                }
            }
        }
    }
}

/** La scelta di un locale approvato (locale principale, organizzatore registrato), con la ricerca per nome. */
@Composable
internal fun VenuePickerField(
    label: String,
    selected: NamedRef?,
    results: List<Pair<Long, String>>,
    onSearch: (String) -> Unit,
    onSelect: (NamedRef?) -> Unit,
    error: String? = null,
    help: String? = null,
    allowNone: Boolean = false,
) {
    var query by rememberSaveable(label) { mutableStateOf("") }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        selected?.let { venue ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(venue.name.ifBlank { "#${venue.id}" }, modifier = Modifier.weight(1f))
                if (allowNone) TextButton(onClick = { onSelect(null) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_remove_row)) }
            }
        }
        OutlinedTextField(
            value = query,
            onValueChange = { query = it; if (it.trim().length >= 2) onSearch(it.trim()) },
            label = { Text(stringResource(R.string.management_events_venue_search)) },
            supportingText = (error ?: help)?.let { text -> { Text(text) } },
            isError = error != null,
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        if (query.trim().length >= 2) {
            results.take(8).forEach { (id, name) ->
                TextButton(onClick = { onSelect(NamedRef(id, name)); query = "" }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(name, modifier = Modifier.fillMaxWidth())
                }
            }
        }
    }
}
