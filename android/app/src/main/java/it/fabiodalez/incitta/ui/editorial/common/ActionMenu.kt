package it.fabiodalez.incitta.ui.editorial.common

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.truthy
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/**
 * Un'azione su una riga di un elenco (pubblica, rifiuta, sospendi…). `key`
 * è il nome dell'abilità che il server manda in `abilities` per quella riga;
 * `destructive` la colora come pericolosa (la conferma la chiede chi la
 * esegue, con `ConfirmDialog` o `ReasonDialog`).
 */
internal data class RowAction(
    val key: String,
    val label: String,
    val destructive: Boolean = false,
)

/**
 * Le `abilities{}` di una riga, calcolate dal server con le policy: una
 * chiave assente, nulla o non vera vale «no». Si legge da un `JsonElement`
 * per tollerare `[]` (mappa vuota di PHP) e i valori `1`/`"1"`.
 */
internal fun abilitiesOf(element: JsonElement?): Map<String, Boolean> =
    (element as? JsonObject)?.mapValues { (_, value) -> truthy(value) } ?: emptyMap()

/** Le azioni che la riga permette, nell'ordine in cui la schermata le elenca. */
internal fun visibleActions(actions: List<RowAction>, abilities: Map<String, Boolean>): List<RowAction> =
    actions.filter { abilities[it.key] == true }

/**
 * Il menu «⋮» di una riga: solo le azioni che `abilities` permette. Senza
 * azioni permesse il pulsante non c'è (non un menu vuoto).
 */
@Composable
internal fun ActionMenu(
    actions: List<RowAction>,
    abilities: Map<String, Boolean>,
    onAction: (RowAction) -> Unit,
    subject: String? = null,
) {
    val allowed = visibleActions(actions, abilities)
    if (allowed.isEmpty()) return
    var open by remember { mutableStateOf(false) }
    Box {
        IconButton(onClick = { open = true }, modifier = Modifier.size(48.dp)) {
            Icon(
                Icons.Outlined.MoreVert,
                contentDescription = subject?.let { stringResource(R.string.editorial_actions_for, it) } ?: stringResource(R.string.editorial_actions),
            )
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            allowed.forEach { action ->
                DropdownMenuItem(
                    text = { Text(action.label, color = if (action.destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface) },
                    onClick = { open = false; onAction(action) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
    }
}
