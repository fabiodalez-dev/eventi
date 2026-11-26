package it.fabiodalez.incitta.ui.editorial.common

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
import it.fabiodalez.incitta.data.management.OptionChoice

/**
 * Un filtro dell'elenco, come uno di quelli che Filament mette sopra la
 * tabella: `key` diventa `filter[key]`, `options` sono i valori ammessi (per
 * un filtro sì/no basta una voce, «Sì» con valore `1`). Un valore alla volta.
 */
internal data class FilterDefinition(val key: String, val label: String, val options: List<OptionChoice>)

/** Il nuovo insieme di filtri dopo un tocco su una voce: toccare la voce scelta la toglie. */
internal fun toggleFilter(current: Map<String, String>, key: String, value: String): Map<String, String> =
    if (current[key] == value) current - key else current + (key to value)

/**
 * I filtri in un foglio dal basso: una riga di scelte per filtro, «Applica»
 * e «Azzera i filtri». Le scelte restano nel foglio finché non si applica:
 * chiudere il foglio non cambia l'elenco.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun FilterSheet(
    definitions: List<FilterDefinition>,
    current: Map<String, String>,
    onApply: (Map<String, String>) -> Unit,
    onDismiss: () -> Unit,
) {
    var draft by rememberSaveable { mutableStateOf(current.toList()) }
    val selected = draft.toMap()
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.editorial_filters_title), style = MaterialTheme.typography.titleLarge)
            definitions.forEach { definition ->
                Text(definition.label, style = MaterialTheme.typography.titleMedium)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    definition.options.forEach { option ->
                        FilterChip(
                            selected = selected[definition.key] == option.value,
                            onClick = { draft = toggleFilter(selected, definition.key, option.value).toList() },
                            label = { Text(option.label) },
                            modifier = Modifier.heightIn(min = 48.dp),
                        )
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { draft = emptyList() }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_filters_clear))
                }
                Button(onClick = { onApply(draft.toMap()) }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_filters_apply))
                }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}
